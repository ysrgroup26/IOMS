# 025 — Numbering Sequence Uniqueness: Portable Across MySQL and MariaDB

## Status

Accepted.

## Problem

Production deployment (shared hosting, MariaDB 10.x) failed at
`2026_08_17_100050_create_numbering_engine_tables` with `SQLSTATE[1064]`. The migration's original
uniqueness statement was a MySQL 8.0.13+ **functional index**:

```sql
ALTER TABLE numbering_sequences ADD UNIQUE KEY numbering_sequences_scope_unique
  (module_key, period_key, (COALESCE(company_id, 0)))
```

MariaDB never adopted this MySQL-specific syntax (it has its own, incompatible computed-column-index
feature) and rejects it outright. This project must run on both engines: MySQL in development, and
MariaDB in production (shared hosting).

## Why the functional index existed at all

`numbering_sequences.company_id` is nullable (NULL = "global scope," the only scope actually used
today -- see below). Every SQL engine, MySQL and MariaDB alike, treats each `NULL` in a unique index
as distinct from every other `NULL`. A naive `unique(['company_id', 'module_key', 'period_key'])`
would therefore **not** prevent two concurrent requests from both inserting a `company_id = NULL`
row for the same `module_key`+`period_key` -- reopening the exact race condition
`NumberGeneratorService::nextSequence()`'s locked-transaction design exists to close (two concurrent
`MaterialRequest`/`Incident`/etc. submissions getting the same generated number). The functional
index normalized NULL to a real `0` specifically to give the unique index something non-NULL, and
therefore enforceable, to compare. This was never a stylistic choice -- deleting it outright (rather
than replacing it) would have silently reintroduced the race on both engines.

## Investigation: is `company_id` actually used as a real per-company scope today?

No. `NumberGeneratorService::nextSequence()` hardcodes `company_id => null` on every read and write;
`generate()`'s own `$companyId` parameter only affects *format* resolution (which prefix/pattern to
use), never sequence scope. This is intentional and already documented in the original migration's
doc comment and in ADR-009 -- per-company sequence counters were deliberately deferred, and the
nullable `company_id` + FK column exists purely for forward-compatibility with a future task, not
because it varies today.

## Decision

Added a plain, physical, **always-NOT-NULL** column, `company_scope`, application-managed by
`NumberGeneratorService` as `company_id ?? 0` on every write. The unique index is an ordinary
composite index over three real columns:

```php
$table->unsignedBigInteger('company_scope');
// ...
$table->unique(['module_key', 'period_key', 'company_scope'], 'numbering_sequences_scope_unique');
```

`company_id` itself is untouched -- still nullable, still FK-constrained to `companies`, still
reserved for a future per-company-sequences feature. `company_scope` is a redundant bookkeeping
column that exists solely so the uniqueness guarantee can be expressed in completely ordinary SQL.

`NumberGeneratorService::nextSequence()`'s `firstOrCreate()` match array and the subsequent
`lockForUpdate()` query both now include `company_scope => 0` alongside the existing
`company_id => null` check -- necessary, not decorative: `firstOrCreate` only guards against races
via the underlying unique index (Laravel's `createOrFirst()` catches the duplicate-key exception on
a losing concurrent insert and re-queries), so the match array has to reach the column the index
actually protects.

## Why this is portable

- `$table->unsignedBigInteger()` and `$table->unique([...])` compile to completely standard SQL
  supported identically by MySQL 8+ and MariaDB 10.x -- no functional index, no generated/virtual
  column, no vendor-specific `DB::statement()` at all. The migration no longer contains a single
  line of raw, engine-specific SQL.
- The uniqueness guarantee no longer depends on NULL-handling semantics at all -- `company_scope` is
  never NULL, so "does this engine's unique index treat NULLs as distinct" (true on both MySQL and
  MariaDB, so this was never actually the differentiator) stops mattering entirely. The fix is
  engine-agnostic by construction, not by coincidence.

## Preserved exactly

- **Business rules**: one counter per `module_key`+`period_key`, global scope only, unchanged.
- **`NumberGeneratorService` public behavior**: `generate()`'s signature, resolution order, returned
  number format, and locking strategy are all identical. The only change is one additional
  always-`0` value written alongside the existing always-`null` `company_id` on every row -- an
  internal bookkeeping detail, invisible to every caller.
- **No race condition**: the same `firstOrCreate` + `lockForUpdate()` pattern, now backed by a
  uniqueness guarantee that's actually enforceable on both engines (the original functional index
  enforced it correctly on MySQL; it simply never took effect on MariaDB because the `ALTER TABLE`
  itself failed).

## Verified locally

`php artisan migrate:fresh --seed` and `php artisan optimize` both succeed against this project's
local MySQL. (MariaDB itself wasn't available to test directly in this environment; the fix's
portability rests on using only standard, engine-neutral column/index types documented as identical
on both engines -- not on running the migration against a live MariaDB instance.)

## Files changed

- `database/migrations/2026_08_17_100050_create_numbering_engine_tables.php` -- replaced the
  functional index with `company_scope` + a plain composite unique index; removed the now-unused
  `DB` facade import.
- `app/Models/NumberingSequence.php` -- added `company_scope` to `$fillable`.
- `app/Services/NumberGeneratorService.php` -- `nextSequence()` now sets/matches `company_scope => 0`
  alongside `company_id => null`.

No other file was touched -- this was scoped entirely to the Numbering Engine.

---

## v2.40.0 follow-up — sequence scope predates multi-tenancy (RESOLVED in v2.41.0)

This ADR settled a uniqueness/race problem and, in passing, restated the existing business rule as
"one counter per `module_key`+`period_key`, global scope only, unchanged", reserving `company_id`
for a future per-company feature. That decision was made in a SINGLE-INSTANCE world. Milestone 2
then introduced tenancy, and this scope was never revisited.

**Consequence today.** All tenants share one counter per module+period. Numbering FORMATS are
correctly per-tenant (`NumberGeneratorService` matches on `tenant_id`), but the sequence is not,
so each tenant sees GAPS in its own document numbers wherever another tenant consumed the counter.

- Not a confidentiality breach: no tenant can read another's records through this.
- It IS a weak information leak by inference: the size of a gap reveals other tenants' document volume.
- The bigger issue is audit quality. In an HSE context a permit register with gaps reads to an
  auditor like missing or destroyed records, which is exactly the wrong signal for the artifact
  IOMS exists to make trustworthy.

**Why it was NOT fixed in v2.40.0.** Naively adding `tenant_id` to the sequence scope starts every
tenant's counter at 0, which would re-issue numbers that already exist on live documents. Duplicate
permit numbers are materially WORSE than gapped ones. A correct fix needs a per-module backfill that
seeds each tenant's new counter from its current maximum issued number, which means enumerating
every module's number column and its format. That is a focused pass of its own, not a safe
by-the-way change during a security release.

**Recommended next step.** One migration that (a) adds `tenant_id` to `numbering_sequences` and to
the unique index, and (b) for each tenant and module, seeds `last_number` from MAX(existing issued
number) parsed via that tenant's own `NumberingFormat`. Verify against a production copy before
shipping: the failure mode is duplicate document identity, so it warrants a dry-run report first.

### Resolution (v2.41.0)

Implemented in `2026_09_07_100210_add_tenant_scope_to_numbering_sequences`. The counter is now
per tenant, using the same `*_scope` device this ADR established for companies: a plain,
always-NOT-NULL `tenant_scope` column (0 = platform) is what the unique index actually carries,
because a nullable `tenant_id` inside a unique key would not prevent duplicate platform rows.
The key is now `(module_key, period_key, company_scope, tenant_scope)`.

**The backfill deliberately does NOT parse document numbers.** The recommendation recorded above
was to seed each tenant from MAX(existing issued number) per module, which would have meant
reverse-engineering 29 modules' editable prefix/pattern/padding formats -- fragile, and it fails
outright once an admin customises a pattern. It is also unnecessary, because of this invariant:

> Every tenant drew from the SHARED counter, so the shared counter is already >= every tenant's
> own maximum issued sequence for that (module_key, period_key).

Seeding each tenant's row from the shared `last_number` is therefore deterministic and provably
collision-free: `tenant_next = shared + 1` exceeds anything that tenant already holds, nothing is
parsed, no counter is reset, and the number issued immediately after the migration is exactly the
one that tenant would have received anyway -- no discontinuity at cutover. Verified against a real
database: a shared counter at 42 with two tenants produced 43 for each, with the platform row
preserved at 42.

`down()` is equally careful: it carries the MAXIMUM per-tenant value back onto the platform row
before deleting the tenant rows, so a rollback cannot lower the high-water mark and re-issue live
numbers. Verified: after one tenant advanced to 49, rollback left the shared row at 49, not 42.

Two tenants legitimately holding the same number string is correct and intended -- a document
number is unique within a customer, like an invoice number. Confirmed before implementing that no
module's number column carries a global unique constraint. `company_id` remains reserved for a
future per-company series, unchanged by this work.
