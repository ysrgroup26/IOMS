# 013 — Activity Center (Milestone 3, Task #50)

## Status

Accepted.

## Problem

`ActivityLog` has existed since v1 and is already used 32+ times, but every existing viewer
(`ActivityTimeline`) only ever shows one record's own history (`where('subject_type', X)->where('subject_id', $id)`).
There was no cross-record, filterable view -- exactly what an audit trail needs to actually be useful
for "what happened across the company this week," not just "what happened to this one record."

## Decision

**Three new nullable columns on the existing `activity_logs` table** (`company_id`, `department_id`,
`module`), not a new parallel table. `ActivityLog::record()` best-effort auto-populates them straight
off the `$subject` model's own `company_id`/`department_id` attributes and its class name — every one
of the 32+ existing call sites keeps working completely unchanged (these are inferred, not required,
arguments).

**Historical rows are NOT backfilled.** The `$subject`'s state at the time of a past action isn't
reliably reconstructable (e.g. its `company_id` could have changed since), so guessing would produce
misleading data. Old rows simply show as "no company/department/module" in filters — an honest gap,
not a fabricated one.

**`ActivityCenterController` reuses the exact same table every module already writes to** — filters by
user/company/department/module/date, paginated. Genuinely nothing new to log; only a new way to browse
what was already being recorded.

**"Audit Logs" was already a disabled placeholder item in the Administration workspace since v1.9.0**
(`resources/js/lib/workspaces.js`) — this feature is that placeholder becoming a real link
(`activity-center.index`), not a new nav addition. Gated `role:super_admin`, matching the sensitivity
of a company-wide audit trail.

## Verified

Browser walkthrough as `admin@ioms.local`: page renders with zero activity (fresh seed, correct —
seeders don't call `ActivityLog::record()`), a real `MaterialRequest` transition immediately appeared
with the correct company (`GAJ`) and module (`material_request`) auto-populated, and the module filter
correctly narrowed the list.

## Consequences

- `department_id` is intentionally NOT a foreign key (plain `unsignedBigInteger`) — it's a
  denormalized, best-effort cache of whatever the subject happened to have at write time, not an
  authoritative relationship to enforce referential integrity on.
- A high-volume tenant will want this paginated feed backed by a proper search/filter index
  eventually (today: plain `WHERE` + `ORDER BY created_at DESC` on an indexed but unbounded table) --
  fine at current scale, worth revisiting if activity volume grows into the millions of rows.
