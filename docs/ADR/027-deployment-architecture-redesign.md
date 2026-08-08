# 027 — Deployment Architecture Redesign (One Flow, Every Environment)

## Status

Accepted.

## Problem

Four sequential production incidents (Pail provider committed to git, stale config cache, MariaDB
functional index, partial-migration recovery) were each fixed individually, but the underlying
*process* that let each one reach production unnoticed was never addressed: every deploy required
a person to remember a specific sequence of manual steps, in the right order, and a fifth incident
(two out-of-sync `public/build` directories, causing a white screen from 404'd hashed assets) showed
why that doesn't scale -- it's not sustainable for a commercial SaaS product to require a human to
correctly execute an undocumented, growing list of manual fixes on every release.

## Root cause of the asset-sync incident specifically

Two `public/build` directories existed -- `~/ioms/public/build` (what `npm run build` actually
writes, matching the current `manifest.json`) and `~/public_html/build` (what Apache actually
served) -- because `~/public_html` is a *separate* directory from the Laravel repository's own
`public/` folder, and someone had been manually copying built assets into it after each build. That
copy step is inherently racy and stateful: forget it once, and the live site serves whatever files
happened to be there before, silently, with no error until a user's browser 404s on a hashed
filename the current `manifest.json` no longer references.

**This is not a Laravel or Vite bug.** Laravel's own convention is that `public/` *is* the web root
-- nothing should ever need to be copied into a second directory. The problem was entirely that
`public_html` (cPanel's fixed web-root convention) and `ioms/public` (Laravel's own convention) were
never made to be the same directory.

## Decision: one web root, chosen once, never duplicated

Two equally correct ways to make `public_html` and `ioms/public` the same directory, in order of
preference:

1. **Point the domain's document root directly at `~/ioms/public`** (cPanel: Domains → Document
   Root) -- the cleanest option, when the hosting plan allows changing it.
2. **Symlink `public_html` to `ioms/public`** (`mv ~/public_html ~/public_html.bak-<date> && ln -s
   ~/ioms/public ~/public_html`, renaming rather than deleting the old directory -- reversible until
   you're sure) -- a universal fallback that works even on hosts that pin the primary domain's
   document root and won't let it be changed. Once done, `~/public_html/build` and `~/ioms/public/build`
   are not "kept in sync" -- they are the literal same inode. There is no second copy to fall out of
   sync, on this deploy or any future one. (Confirmed live on this project's own host: the original
   `rm -rf`-based instruction was never actually run there, `public_html` remained a real, separate,
   stale directory, and production served 404s for every current asset until the symlink was applied
   -- see `docs/ADR/029` for the full incident.)

Either choice is a **one-time, per-environment** action, documented in `README.md` § 5, never a step
in the repeating deploy flow. Nothing in the deploy flow copies, syncs, or touches `public_html`
directly -- there is structurally nothing left to go stale.

## Decision: one deploy flow, everywhere

`git pull → composer install → npm run build → deploy → cache → ready`, run by one script
(`deploy.sh`) calling one Artisan command (`app:deploy`, `app/Console/Commands/DeployCommand.php`)
for everything Laravel-specific:

- Clears stale config cache before migrating (closes the incident in ADR-024 permanently, not just
  for the deploy that already broke).
- Runs `migrate --force` -- safe against a previously-interrupted deploy leaving tables behind with
  no migration record (see below).
- Optionally seeds (`--seed`) -- safe on every deploy, not just the first, because every seeder in
  this codebase is idempotent (`firstOrCreate`/`updateOrCreate`/`syncWithoutDetaching`, audited in
  `docs/ADR/026`).
- Links storage (idempotent -- see the code comment on why this deliberately does NOT try to detect
  "already linked" itself, after finding that check unreliable across platforms; `storage:link`
  already handles that gracefully on its own).
- Rebuilds config/route/view/event caches.
- Wrapped in maintenance mode: only lifts if every prior step succeeded. A failure leaves the app
  serving a safe 503, not a half-migrated, half-cached broken state -- verified live (see below).

`deploy.sh` is the thin, environment-specific glue around that: `git pull`, `composer install`,
`npm run build`, then `php artisan app:deploy $ARGS`. The one environment-specific unknown --
where `composer` actually lives on a given host (discovered mid-audit: shared hosting can hide it at
a versioned path like `/opt/alt/php83/usr/bin/composer`, not on `PATH`) -- is resolved via a
`COMPOSER_BIN` environment variable set once in the shell profile, never hardcoded into the
committed script. The same `deploy.sh`/`app:deploy` pair runs unchanged on shared hosting, a VPS, or
a future cloud target; only the one-time setup (web root, `.env`, `COMPOSER_BIN`, cron) differs.

## Decision: migrations must survive a deploy interrupted mid-way

Shared hosting commonly enforces aggressive script-timeout kills. Verified live during this audit
(not theoretical) that this is a real risk: a deploy process killed between a `CREATE TABLE`
succeeding and Laravel recording the migration as run leaves the table behind with no record, and
retrying `migrate` then fails immediately with "table already exists" -- the exact failure class
that blocked deployment twice already (`docs/ADR/025`, `026`), reproduced a third time live for
`create_field_mappings_table` (previously unguarded) during this audit's "upgrade deployment"
simulation.

Rather than patch each migration individually again, `AppServiceProvider::registerSchemaMacros()`
registers `Schema::createIfMissing($table, $callback)` -- a drop-in, no-op-if-already-there
replacement for `Schema::create()`. Applied to every migration whose failure mode is "plain create,
nothing complex" (`approval_flows`, `approval_flow_steps`, `notifications`, `report_schedules`,
`document_templates`, `field_mappings`, `numbering_formats`, `tenant_modules`, `tenant_workspaces`).
`numbering_sequences` and the `tenant_module_workspace_grants` backfill keep their own bespoke
reconciliation logic (schema upgrade / `insertOrIgnore`, from ADR-025/026) since a bare "skip if
exists" isn't safe there -- an existing table might be in the *old*, pre-fix shape, not just already
correct. `docs/CONVENTIONS.md` should point every future migration (Milestone 4 onward) at
`Schema::createIfMissing()` as the default for a new table, so this incident class cannot recur
without a fresh fix each time.

## Verified (simulated, not assumed)

- **Fresh deployment**: `migrate:fresh --seed` from empty, then `app:deploy --seed
  --no-maintenance` -- clean, all four caches built.
- **Upgrade deployment**: deleted one migration's tracking row while its table remained (simulating
  the exact "process killed mid-deploy" scenario) -- `migrate --force` completed with **DONE**, no
  error, after applying `Schema::createIfMissing()`. Reproduced the *un-guarded* failure first
  (confirmed the bug is real, not hypothetical), then confirmed the fix.
- **Failed deployment**: injected a migration with deliberately invalid SQL, ran `app:deploy` --
  exit code `1`, real `SQLSTATE[42000]` surfaced to the console, and `app()->isDownForMaintenance()`
  confirmed **true** afterward (app correctly stayed down, not silently "ready"). Removed the broken
  migration, re-ran `app:deploy` -- succeeded, `isDownForMaintenance()` confirmed **false**
  (recovered cleanly).
- **Partial migration recovery**: re-confirmed the numbering-engine and tenant-grants self-healing
  paths from ADR-025/026 still work unchanged under the new `Schema::createIfMissing()` macro.
- **Asset rebuild / manifest sync**: modified a source file, ran `npm run build` -- confirmed the
  compiled JS filename changed and `manifest.json` updated to reference it, proving Vite's
  cache-busting itself was never the problem; the two-directory duplication was.
- **Production cache**: `php artisan app:deploy --no-maintenance` rebuilds config/route/view/event
  caches with zero errors from a clean DB state.

All test data and injected failures were removed after verification; the repository is in the same
functional state it was before this audit, plus the fixes described above.

## Consequences

- No manual copy, cache-clear, asset-replace, or migration-recovery step should ever be needed again
  for a deploy that only changes application code -- every one of those was a symptom of either a
  missing idempotency guard (now systemic via the macro) or the duplicated web root (now structurally
  impossible after the one-time symlink/document-root fix).
- The one-time setup (web root, `.env`, `COMPOSER_BIN`, cron, `chmod +x deploy.sh`) is documented in
  `README.md` § 5 and must still be done once per new environment (a new VPS, a future second
  tenant's dedicated instance, etc.) -- this ADR does not eliminate that, only the *repeated* manual
  work on every deploy after it.
- `Schema::createIfMissing()` does not, and should not, blanket-replace every `Schema::create()` in
  the codebase's history -- only ones where "already exists" genuinely means "safe to skip." A
  migration whose table might exist in an *old, incompatible* shape (like `numbering_sequences`)
  needs its own reconciliation logic, not this macro alone.
