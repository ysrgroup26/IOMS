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
