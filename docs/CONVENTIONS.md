# Conventions & Known Pitfalls

House style, and a deliberately honest list of mistakes that have actually happened in this
codebase's history — kept here so they don't get repeated in a slightly different shape.

## Migrations

- **Additive, never destructive, for anything that might already hold real data.** When a column's
  shape needs to change (e.g. widening an enum, making a foreign key nullable), write a migration
  that adds/alters — never one that drops and recreates a table as routine logic. See "Known
  Pitfall #1" below for exactly why this matters, not just as a style preference.
- **Backfill existing rows explicitly, don't leave them null-and-hope.** When adding a required
  column to a table that already has rows (e.g. `company_id` on `departments`, then `positions`),
  the migration backfills every existing row to a sensible value *before* enforcing `NOT NULL` —
  usually the most accurate value derivable from existing relationships (a position's own
  department's company, for example), falling back to a documented default company only when
  nothing more specific is derivable.
- **Widening a real MySQL `ENUM`** is done via raw `DB::statement("ALTER TABLE ... MODIFY COLUMN
  ... ENUM(...)")`, not a schema-builder enum helper — Laravel's schema builder has no first-class
  "add one more allowed value" operation. Existing rows with already-valid values are unaffected;
  this only widens what's *allowed* going forward.
- **Verify the actual table name before writing a foreign key.** `->constrained()` without an
  explicit argument guesses the referenced table by stripping `_id` and naively pluralizing the
  column name. Most of the time that's correct (`company_id` → `companies`). It is **not** correct
  for `employee_ppe` (deliberately singular — see `MODULES.md`'s PPE section) or any other
  intentionally non-standard table name. When in doubt, check the model's `protected $table`
  property or the table's original creation migration directly — don't assume standard
  pluralization holds.
- **Double-check new routes land in the right middleware group**, especially when adding routes
  near an existing `role:super_admin`-restricted block. This has happened for real: an entire new
  module's routes were once accidentally nested inside a Super-Admin-only group, silently locking
  out the actual intended users. After adding routes, check indentation/nesting against the
  surrounding `Route::middleware(...)->group(...)` structure, not just that the route exists.
- **Use `Schema::createIfMissing($table, $callback)`, not `Schema::create()`, for every new table.**
  A drop-in replacement (registered as a macro in `AppServiceProvider`) that's a no-op if the table
  already exists. This exists because a deploy interrupted between a `CREATE TABLE` succeeding and
  Laravel recording the migration as run (a real, verified risk on shared hosting's aggressive
  script-timeout kills — see `docs/ADR/027`) leaves the table behind with no migration record;
  retrying then fails with "table already exists," blocking every migration after it. This has
  happened three times in this codebase's history already (`docs/ADR/025`, `026`, `027`) before
  becoming a standing convention instead of a one-off fix each time. If a table might already exist
  in an *old, incompatible* shape rather than just "already correctly created" (e.g. a migration
  that also adds a column or an index in a separate step), don't rely on the macro alone — guard
  each step individually and reconcile the old shape explicitly, the way
  `2026_08_17_100050_create_numbering_engine_tables.php` does for `numbering_sequences`.

## Status / lifecycle enums

- Prefer a single `status` column with an explicit, small enum over multiple boolean flags, for
  anything with more than two meaningful states.
- **Don't create a stored status value that duplicates a status that already exists elsewhere in a
  related record.** The concrete example: Material Request does *not* have a `pending_approval`
  database value, even though the feature spec that requested the workflow described one — it's
  the *label* applied to `submitted` while the associated `Approval` record's own `status` is
  `pending`. Two tables agreeing to always be in lockstep is duplication, not a real distinction.
  Before adding a new status value, check whether a related model already represents the same fact.
- Reuse the shared `StatusBadge` component's canonical color mapping
  (`resources/js/Components/shared/StatusBadge.jsx`) rather than building a new status-to-color map
  per module.
- When a model's own status needs guarded transitions (not just any value settable at any time),
  use the `HasWorkflow` trait rather than hand-rolling `if ($old === 'x' && $new === 'y')` checks
  inline in a controller — see `ARCHITECTURE.md`.

## Roles & permissions

- `users.role` is a plain `VARCHAR`, not a real database `ENUM` — adding a new role (e.g.
  `warehouse`) needs zero migration. Do add it to: the `User` model's `ROLE_*` constants and
  `isX()`/`roleLabel()` methods, the relevant Form Request validation `in:` list, and the Settings
  role dropdown — all four, not just one, or the new role will be creatable in the database but
  invisible or unselectable somewhere in the UI.
- For workflow-style actions (approve/process/override), read from `config/workflow.php`'s role
  lists rather than hardcoding a role check inline — see `ARCHITECTURE.md`'s Authorization section
  for why this file exists and what it's preparing for.
- No RBAC package exists (see `ARCHITECTURE.md`). Don't introduce one without first re-reading
  `ADR/006-material-request-workflow.md`'s reasoning for why it was deliberately deferred, and
  confirming the actual need has changed since.

## Navigation

- **Adding a nav item** (v1.7.0+, workspace-based nav — see `ARCHITECTURE.md`'s Navigation
  Architecture section and `ADR/007-workspace-navigation.md`): add it to the right workspace's
  `items` array in `resources/js/lib/workspaces.js`. If it's a new toggleable module, also add its
  key/label to `config/modules.php`'s `available` registry — that's the whole change; the
  workspace switcher, the sidebar filtering, and the Settings → Modules toggle UI are all generic
  and pick it up automatically. Don't add a new item to `AuthenticatedLayout.jsx` directly — that
  file no longer holds the nav item list, only the rendering.
- **Adding a new workspace**: only do this for a domain that has at least one real, built module —
  don't add an empty workspace as a placeholder (a workspace with zero visible items is hidden by
  `getVisibleWorkspaces()` automatically, so an empty one would just be dead code, not a preview of
  something coming). Check `workspaces.js`'s "FUTURE WORKSPACES" comment block first — the intended
  workspace for most planned domains is already decided there.
- **Don't URL-namespace by workspace.** Existing route names/URLs are the single source of truth
  for what a route is; workspace is purely a frontend grouping over them (see `ADR/007`). A route's
  workspace is derived by matching its name's prefix (the part before the first `.`) against each
  workspace item's own prefix — so if a module ever gets multiple route-name prefixes (unusual, but
  possible for a fast-growing module), each prefix needs its own entry pointing at the same
  workspace, or pages on that second prefix will silently fail to auto-select the workspace.

## Caching

- **Be genuinely cautious with `Cache::rememberForever()` for anything whose default value is
  derived from mutable `config()`.** `CompanySetting::get()` originally cached
  `enabled_modules`'s computed default this way; the very first time it ran (before a since-added
  module existed in config) it permanently cached the old list, with nothing to invalidate it when
  the config file itself changed later. Fixing that introduced a *second* bug (the cache key
  changed but the corresponding `set()`'s cache-`forget()` call wasn't updated to match, so saving
  a setting stopped actually invalidating anything). The eventual fix: for a small, rarely-changed,
  cheap-to-query setting, just read the database directly rather than cache it at all — not every
  read needs `rememberForever`, and the settings most likely to need one (something read on every
  single request, like enabled modules) are exactly the ones where getting the invalidation subtly
  wrong is most damaging.
- If you do cache something, make sure whatever busts the cache is actually reachable from every
  code path that changes the underlying value — not just the one you're thinking about right now.

## Company scoping

- The convention, everywhere in this app, is a per-request query parameter (`?company_id=`) — not
  a persistent session/"active company" concept, because none exists. Match this convention for
  any new filterable list rather than inventing a different mechanism.
- A model that's genuinely company-specific master data (departments, positions, and similar)
  should have a required, direct `company_id` — even if it's also reachable transitively through
  another relationship (a position's department, for instance) — because a direct column avoids a
  join for the extremely common "show me X for this company" query, and because it lets the record
  exist correctly even before the more specific relationship is chosen.

## Tenancy scoping (Milestone 2)

- **Every `User::create()` call site must set `tenant_id` explicitly.** It has no safe default —
  `null` is a real, permanent state (Platform Super Admin, see `User::isPlatformAdmin()` and
  `docs/ADR/008-tenancy-foundation.md`), not a data gap that "just happens" to get backfilled. This
  bug was made and fixed twice in the same milestone: `UserSeeder` (new accounts created during
  `db:seed` on a fresh install, since the migration's own backfill only covers users that already
  existed *at migration time*, not ones a later seeder creates) and
  `SettingsController::storeUser()` (new accounts created from Settings → Users in the running app).
  Both silently created accounts that were accidentally Platform Super Admins, which then failed
  every Company-scoped query closed for themselves (`App\Models\Scopes\TenantScope`'s fail-closed
  behavior). If you add another place that creates a `User`, set `tenant_id` there too — don't
  assume a migration backfill will cover it.
- **`TenantScope` (on `Company`) fails closed outside an HTTP request.** `php artisan tinker`, a
  queued job, or a seeder that runs before `DatabaseSeeder` binds a tenant will all see
  `Company::count()` return 0 even when rows exist — this is correct, not a bug, but easy to
  mistake for one while debugging. Bind a tenant manually first:
  `app(App\Support\CurrentTenant::class)->set($tenant)`.
- **`Company`'s global scope does not automatically protect every table beneath it.** Only queries
  that actually go through a scoped `Company` relationship are protected. A controller/dashboard
  widget that queries an operational table directly (KPI records, incidents, etc.) without joining
  through `Company` is not tenant-filtered. This stopped being a theoretical/single-tenant-only
  concern once real multiple tenants existed in production (Master → Tenant Management,
  Milestone 4) — see the growing list of real instances below, each found the same way: reading the
  controller carefully while building or extending something nearby, not by a dedicated audit pass.
  `DashboardStatsService::resolveCompanyIds(?int $companyId): array` (`Company::query()->pluck('id')`,
  falling back to the current tenant's full company list when no specific one is selected — critically,
  an empty list for a company-less tenant is a real "match zero rows" `whereIn`, not "match everything")
  is the one reusable, TenantScope-safe helper every new instance should call, not reimplement.
  Found and fixed: `DashboardStatsService` itself (a brand-new, company-less tenant's Dashboard
  showing another tenant's KPI/employee data), `HseDashboardController` (Milestone 4, Workstream
  B1 — every widget query, found while adding the Safety Observation widget). Flagged, not yet
  fixed (background tasks, to avoid silently expanding an unrelated change's scope):
  `IncidentController::index()`/`show()`, `PpeController` (the KpiCategory-class leak), a per-instance
  ownership guard missing from `CompetencyTypeController`/`ShiftController`/`RosterPatternController`'s
  `update()`/`destroy()` (their `Store`/`UpdateXRequest` only validates the *submitted* `company_id`,
  never checks the *existing* route-model-bound record's own tenant). See
  `docs/ADR/008-tenancy-foundation.md`'s Consequences section for the underlying design tradeoff.

- **Any place that sets Spatie's permission team id must use the same `0` sentinel for a Platform
  Super Admin (`tenant_id` null) that `RolePermissionSeeder` used when assigning their role** — the
  `model_has_roles`/`model_has_permissions` pivot tables require a non-null team id in their own
  primary key, so passing `null` through (the naive `$tenant?->id` translation of
  `User::isPlatformAdmin()`'s convention) makes `->hasRole()`/`->can()` silently return false for
  every Platform Super Admin at runtime, even though the seeder's own `tinker` check looked correct.
  This was caught and fixed in `App\Http\Middleware\ResolveTenant` during the same milestone that
  introduced it — see `docs/ADR/008-tenancy-foundation.md`.

## Verification discipline (and its real limits)

- This project's development has, in practice, never included actually running `php artisan
  migrate`, `npm run build`, or the application in a browser — verification has been static:
  balance/brace checking on every edited file, cross-referencing every `route()` call against
  `routes/web.php`, and matching every Inertia page's destructured props against exactly what its
  controller passes. This has caught real, would-have-shipped bugs, but it is fundamentally not the
  same as a runtime test, and treating "carefully verified statically" as "confirmed working" would
  be overclaiming. When reporting on work like this, say so plainly rather than implying more
  certainty than the verification method actually supports.
- Two genuinely severe bugs were caught this way that a build-and-move-on approach might have
  shipped: the Super-Admin-only route-group mistake, and the settings cache staleness. Both are
  detailed above specifically so the pattern is recognizable next time, not just the individual
  fixes.

## "Verify first" as a default posture

Before building something that sounds like a new feature, check whether the underlying mechanism
already exists and only the surface is missing. The concrete example: `ActivityLog` (a polymorphic
activity-recording model) already existed and was already used 32+ times across controllers before
anyone built a way to *view* that data — the real gap was a viewing component, not the recording
mechanism, and building a second, parallel logging system would have been pure duplication. This
kind of check is cheap (a few minutes of reading) relative to the cost of maintaining a duplicate
system afterward.
