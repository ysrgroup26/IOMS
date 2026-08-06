# 009 — Numbering Engine (Milestone 3)

## Status

Accepted.

## Problem

Six near-identical `generate*Number()` methods existed (`MaterialRequest`, `Incident`,
`LeaveRequest`, `GoodsReceipt`, `PpeReplacementRequest`, `TaskService`), each doing an **unlocked**
`ORDER BY ... DESC LIMIT 1` read-then-write to compute the next sequence number. This is a real race
condition: two concurrent submissions can read the same "last number" before either writes, producing
duplicate numbers (which then either crash on the column's `unique()` constraint or, worse, silently
collide if that constraint were ever relaxed). Duplicating the same fragile pattern a seventh time
(for `Milestone`, which had no numbering at all) was the trigger to fix this properly instead.

## Decision

**One centralized service, `App\Services\NumberGeneratorService`**, replaces all six methods. Every
model keeps its original public method name (`generateRequestNumber()`, etc.) as a thin wrapper
delegating to the service — call sites in controllers didn't need to change at all.

**Concurrency-safe via a locked counter row per module+period**, not the old unlocked
`ORDER BY ... LIMIT 1`. `numbering_sequences` has one row per `(company_id, module_key, period_key)`;
generating a number wraps `firstOrCreate` + `lockForUpdate()` + `increment()` in a DB transaction, so
two concurrent requests for the same module always serialize onto two different numbers.

**Format is configurable per company, sequence scope stays global (shared across companies) for
now.** `numbering_formats` supports a per-company override (prefix/pattern/padding/reset-period), but
`numbering_sequences` always stores `company_id = NULL` — i.e. one shared counter across every company
under a tenant, reproducing exactly what all six original methods already did (none of them filtered
by company at all). This was a deliberate choice, not an oversight: `request_number`/`incident_number`/
etc. columns each have a DB-level `unique()` constraint with no `company_id` in it. Splitting sequences
per company without first widening those constraints to composite `(company_id, number)` would let two
companies generate the same number and crash on insert. Widening those constraints is a legitimate
future improvement (Task #57, Company Settings) but is a separate, riskier schema change on
already-live production tables — not bundled into this pass.

**MySQL functional unique index, not a plain composite unique.** A plain
`unique(['company_id', 'module_key', 'period_key'])` would NOT prevent two independent "global scope"
counter rows (`company_id` NULL) for the same module+period, because MySQL treats every NULL as
distinct in a unique index — exactly the race this table exists to close. Fixed with a raw
`ALTER TABLE ... ADD UNIQUE KEY (module_key, period_key, (COALESCE(company_id, 0)))` (MySQL 8.0.13+,
already assumed by this stack).

**Every module's default format reproduces its old hardcoded output exactly** (`{PREFIX}-{YEAR}-{SEQ}`,
5-digit padding, yearly reset) — generating a number today looks identical to before this engine
existed, unless a Company Admin explicitly edits the format later.

**New: `Milestone` gets numbering for the first time** (`MS-{YEAR}-{00001}`) — it previously had none.

## Consequences

- Adding a new numbered module = one new entry in `NumberGeneratorService::DEFAULTS` (or a genuinely
  custom `NumberingFormat` row), never a new bespoke `generate*Number()` method.
- Per-company independent sequences (so each company's numbers start fresh at 1) remain a real,
  reasonable future ask — but require widening the affected unique constraints first. Flagged here
  explicitly so it isn't attempted as a quick tweak to `NumberGeneratorService` alone.
- `NumberingFormat`/`NumberingSequence` are not tenant-scoped models (no `TenantScope`) — same
  reasoning as `Module`/`Package`: they're referenced by `company_id` directly where relevant, not by
  a global scope.
