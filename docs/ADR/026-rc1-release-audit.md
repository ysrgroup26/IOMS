# 026 — RC1 Full Release Audit (Milestone 3 Deployment Readiness)

## Status

Accepted.

## Purpose

A full, systematic audit of everything touching Milestone 3 deployment, triggered by a third
sequential deploy failure (Pail provider → stale config cache → MariaDB functional index → this
audit's own trigger: a partially-applied migration left the numbering engine's tables in place
without Laravel ever recording the migration as run, so retrying it after pulling the portability
fix failed immediately with "Table numbering_formats already exists"). This ADR is the record of
that audit -- what was checked, what was found, what was fixed, and what was checked and found
already correct.

## Immediate blocker: partial-migration recovery

`2026_08_17_100050_create_numbering_engine_tables`'s pre-fix version crashed on its MySQL-only
functional index AFTER both `Schema::create()` calls had already succeeded. Laravel therefore never
recorded the migration as run, but `numbering_formats` and `numbering_sequences` were both already
in the database. The fixed migration (ADR-025) still started with an unconditional
`Schema::create('numbering_formats', ...)`, so re-running it hit "table already exists" immediately.

**Fix**: `up()` now checks `Schema::hasTable()` before each `Schema::create()`. For
`numbering_sequences` specifically -- the table an old partial run left in the *pre-portability-fix*
shape (no `company_scope` column, no unique index) -- the `else` branch upgrades it in place:
adds `company_scope`, backfills it from `company_id`, adds the unique index. A genuinely fresh
database still gets the identical end schema via the `Schema::create()` branch.

**Verified, not assumed**: reproduced the exact production scenario locally -- created both tables
in the old pre-fix shape (no `company_scope`, no unique index), inserted a real counter row
(`last_number: 7`) to prove existing data survives, deleted the migration's row from the
`migrations` table, then ran `php artisan migrate`. Result: no error, `company_scope` correctly
backfilled to `0`, the unique index created, and the pre-existing counter row (`last_number: 7`)
preserved untouched.

## Audit findings by checklist section

**A. Migrations (all 71).** Grepped for every requested risk category:
- `DB::statement`/`DB::unprepared`: 6 files. All are either the numbering-engine fix itself, or
  `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)` / multi-table `UPDATE ... INNER JOIN` statements --
  both standard MySQL-dialect DDL/DML that MariaDB implements identically (unlike functional
  indexes, which diverged). Not a portability risk.
- Functional indexes / generated columns (`virtualAs`/`storedAs`/`generatedAs`): zero remaining
  after this audit's fix -- confirmed by grep, not assumption.
- `fullText`/`spatialIndex`: none used anywhere.
- Duplicate index/table names: none found (checked all named `_unique`/`_index` keys for
  collisions).
- **Partial-failure risk pattern** (2+ `Schema::create()` calls in one `up()`, some followed by
  further logic that could throw): 7 migrations matched. Reviewed each:
  - `numbering_engine_tables` -- the actual incident, fixed (see above).
  - `tenant_module_workspace_grants` -- structurally identical risk (2 creates + a per-tenant
    backfill loop after). **Hardened preemptively**: `hasTable()` guards on both creates, and the
    backfill's `insert()` calls changed to `insertOrIgnore()` so a retry after a partial prior run
    can't throw a duplicate-key error on grants that already landed. Verified the same way: created
    the tables with 7 real grant rows, deleted the migration's tracking row, re-ran `migrate` --
    no error, grant count unchanged (7 before, 7 after).
  - `users_table`, `cache_and_jobs_tables`, `goods_receipts_table`, `approval_engine_v2_tables`,
    Spatie's own `create_permission_tables` -- all have multiple creates but nothing risky *between*
    them (no raw SQL, no external logic that could throw); a crash there would only come from a
    genuine DB connectivity loss, not a logic/portability bug, and Spatie's own migration already
    guards its one real failure mode (missing config) with `throw_if` *before* any table is created.
    Not hardened further -- disproportionate to harden every multi-table migration in the codebase
    against a failure mode with no actual trigger.
- FK ordering: unaffected by all of the above; already implicitly verified by every successful
  `migrate:fresh --seed` run in this and prior audit passes (a genuine FK-order bug would fail
  every time, not intermittently).

**B. Seeders.** All 15 reviewed. 13 use `firstOrCreate`/`updateOrCreate`/`syncWithoutDetaching`
(inherently idempotent). `EmployeeSeeder` is documented, intentional demo-data (not meant to be
re-run-safe, matches its own doc comment and the README's documented seeder categories).
`TenantGrantSeeder` uses `syncWithoutDetaching` (idempotent by construction). `RolePermissionSeeder`
uses `firstOrCreate`/`syncPermissions`/`syncRoles` throughout -- fully idempotent, tenant_id-aware via
`PermissionRegistrar::setPermissionsTeamId()`. No issues found.

**C. Numbering Engine.** Fully covered by ADR-025 (portability) and this ADR's partial-recovery
fix. Race condition prevention (`lockForUpdate()` inside a DB transaction), uniqueness (now portable
composite index), tenant/company readiness (deferred by design, ADR-009, unaffected) -- all
re-verified via a live `NumberGeneratorService::generate()` call producing sequential numbers after
every fix in this audit.

**D-H (Approval Engine, Notification Center, Report Center, Document Template Engine, Analytics
Engine).** Migrations checked for the same risk categories as Section A -- all clean (single
`Schema::create`/`Schema::table` per migration, no raw SQL, portable column types). Functionally
re-verified end-to-end in one live smoke test (see below) after all migration/model changes in this
audit, not assumed still-working from earlier sessions.

**I. SaaS structures.** `tenants`, `packages`, `subscriptions`, module/workspace grants,
`report_schedules`, `document_templates`, `field_mappings` -- all reviewed for FK order (all
reference tables created earlier in migration timestamp order) and portability (no raw SQL besides
the grants backfill, now hardened). No issues beyond the grants migration's partial-failure risk,
fixed above.

**J. Deployment / cross-engine compatibility.** `config/database.php`'s MySQL connection uses
`utf8mb4_unicode_ci` (portable -- NOT `utf8mb4_0900_ai_ci`, MySQL 8's own default collation, which
MariaDB does not support). `DB_CONNECTION=mysql` in `.env.example` -- Laravel's `mysql` driver talks
to MariaDB natively (same wire protocol), no separate driver/config needed. `'engine' => 'InnoDB'`
and `'strict' => true` are both standard, identically-supported settings. Confirmed: this project
requires zero code branching between MySQL and MariaDB targets.

**K/L. Full simulation.** Ran, in order: `migrate:fresh` (fresh schema from zero), `--seed` (all 15
seeders), `key:generate`, `optimize` (config/route/view caching succeeds -- proves no remaining
config-loading issue), `optimize:clear`. Then a live functional smoke test through
`php artisan tinker` exercising the exact feature list requested end-to-end against the real
database (not mocked): Numbering Engine (`generate()` for two modules), Approval Engine (submit →
pending approval created → decide → approved), Notification Center (2 notifications fired on
submit), Activity Log (recorded), Analytics (`AnalyticsService::dataset()` reflecting the just-created
record with zero lag), Document Engine (real PDF rendered, 2506 bytes, no exception), Excel Export
(`AnalyticsDatasetExport` produced real rows), Global Search (executed without error). Then verified
login and dashboard rendering at the raw HTTP level (`curl`, bypassing any browser-tool ambiguity):
`POST /login` → `302` to `/dashboard` with valid session cookies, `GET /dashboard` → `200` with the
correct Inertia `Dashboard/Index` payload. `storage/logs/laravel.log` had zero new entries across
this entire simulation -- no errors anywhere in the full requested sequence.

## Consequences

- Two migrations now self-heal from the specific partial-failure state a crashed prior deploy
  attempt leaves behind, without silently masking a genuinely broken state (they upgrade an
  existing-but-stale table to the correct schema, they don't just skip it).
- All test data created during this audit's verification (numbering sequences, a test Material
  Request, approvals, notifications, activity logs) was deleted afterward -- this ADR reflects what
  was proven working, not data left behind in any environment.
- No new dependency was introduced anywhere in this audit.
