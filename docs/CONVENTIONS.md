# Conventions & Known Pitfalls

House style, and a deliberately honest list of mistakes that have actually happened in this
codebase's history — kept here so they don't get repeated in a slightly different shape.

## Convention update (v1.11.1) — resolve, don't just gate, an unsafe default when the fix is real

`EnforceTenantEntitlement` (v1.11.0) was shipped gated behind `config('saas.enforce_entitlement')`,
default `false`, because its "usable" check conflated "explicitly suspended by an admin" with
"expired by a possibly-stale seeded date" — flipping it on blind risked bricking the production
tenant. v1.11.1 resolved the ROOT problem instead of leaving the gate off forever:
`Subscription::isBlocked()` now hard-blocks ONLY on an explicit `suspended`/`cancelled` status
(always a deliberate Platform Admin action); an expired-by-date or completely missing Subscription
row is `isDegraded()` instead — a warning, never a block. This made it safe to flip the config default
to `true`. **The lesson**: a default-off safety gate on a new enforcement layer is the right move when
you can't verify production data from a coding session, but it's a temporary fix, not the permanent
one — the permanent fix is making the enforcement logic itself provably safe regardless of what the
data says (only react to explicit admin actions, never to an unattended date), so the gate can come
off default-on rather than staying off indefinitely. Still overridable per-install via
`SAAS_ENFORCE_ENTITLEMENT=false` for an operator who wants it fully off regardless.

## Operational runbook — diagnosing "a Department User can still access other departments"

`RestrictDepartmentAccess` (registered globally in `bootstrap/app.php`, re-verified there directly
during the v1.10.8 pass, not assumed) has exactly ONE bypass condition: `! $user->department_key`
(line 60). There is no role-based bypass anywhere in it. So if a user can reach another department's
pages, `department_key` is not actually set to a value on that account — full stop, nothing else in
the chain can cause this. Diagnose via `php artisan tinker` on the production server:

```
$u = \App\Models\User::where('email', 'the-users-email@example.com')->first();
$u->only(['id', 'name', 'email', 'role', 'department_key', 'tenant_id']);
```

- If `department_key` is `null`: the account was created/last edited before v1.10.7 (which added the
  Department Restriction field to Settings → Users), or was edited since but "None" was left
  selected. Fix via the UI: Settings → Users → Edit → set Department Restriction → Save. This is
  NOT retroactive — every account created before v1.10.7 needs this done explicitly, one at a time;
  nothing does it automatically, by design (changing an existing account's access scope should never
  happen silently).
- If a same-session emergency fix is needed before someone can reach Settings, the safe one-line
  tinker equivalent of that same UI action (assign a value already present in `config('departments')`,
  never touch any other field) is: `$u->update(['department_key' => 'hse']);`
- **v1.10.9**: a safer, no-tinker-required alternative now exists —
  `php artisan users:assign-department {email} {department}` (`AssignUserDepartment` command).
  Validates the department key against `config('departments')`, refuses to touch a
  `super_admin`/`platform_admin` account unless `--force` is passed (protects against accidentally
  locking out an administrator), and only ever touches the one named account. Prefer this over raw
  tinker going forward — same effect, less room for a typo'd field name or an accidentally-broad
  query to touch the wrong row.
- If `department_key` is already correctly set and the symptom persists, the next thing to check is
  NOT the RBAC code — it's `git log -1 --format=%H -- public/build/manifest.json` on the server vs.
  `git log -1 --format=%H` (are they from the same deploy?), per the stale-`public/build` pitfall
  documented separately below.

## CRITICAL — `department_key` had no admin-facing UI at all, found during the v1.10.7 RBAC audit

Reported in production: a user assigned "department HSE" could still open every other department. Root
cause traced through the full chain (User → tenant → department_key → RestrictDepartmentAccess →
config/departments.php → routes) and found NOT in any of those enforcement layers — they were already
correct as of v1.10.5's fail-closed fix. The actual bug: **`SettingsController::storeUser()`/
`updateUser()` only ever accepted and validated `role` (`super_admin/hse/hrd/manager/warehouse`) —
there was no `department_key` field anywhere in the form, its validation, or the Users tab's UI at
all.** `role` (what actions a user may perform) and `department_key` (which department's navigation/
routes a user may even reach) are two separate, orthogonal mechanisms — a tenant admin picking "HSE"
as the role reasonably expected that alone to restrict the account to HSE's navigation, and it never
did; every user ever created through the real UI silently stayed `department_key = null`, i.e. a full
Administrator for navigation-restriction purposes regardless of role. Fixed by adding the field to
both the validation (`Rule::in()` against a new `assignableDepartmentKeys()` helper, itself derived
from `config('departments')` minus the two non-assignable 'reports'/'administration' entries) and the
Users tab UI — which also had no Edit dialog at all (`settings.users.update` existed and was routed,
but nothing in the frontend ever called it), added alongside it since fixing Create alone would do
nothing for an already-existing account. **Lesson: a backend mechanism (migration + model column +
middleware enforcement) being fully built does not mean it's actually usable — always check whether
the admin-facing control surface for it exists at all**, the same class of gap as `public/build`
below (a piece of the chain that's easy to verify individually and easy to never notice is completely
missing end-to-end).

## CRITICAL — confirmed stale `public/build`, found during the v1.10.6 department audit

This project deliberately commits its compiled Vite frontend to git (`.gitignore` has
`# /public/build`, intentionally commented out — see that line's own git history, commit
`e52779e "Add production build assets"`), because `docs/ADR/027-deployment-architecture-redesign.md`'s
documented flow is `git pull → composer install → npm run build → deploy → cache → ready`, run via
`deploy.sh`/`php artisan app:deploy`.

**`public/build/manifest.json`'s last commit is `873f65f` ("Milestone 4, Workstream A3: Shift &
Roster Management")** — verified directly via `git log --oneline -1 -- public/build/manifest.json`,
not assumed. Every commit since then (all of HSE Workstream B, Procurement Workstream C, and the
entire Acceleration Mode — dozens of commits, the majority of this project's Milestone 4 work) has
touched `resources/js/*` without a single corresponding rebuild of `public/build/*`.

**This fully explains a real production symptom reported after the previous two audit passes**:
Warehouse, Asset Management, Maintenance, Quality Control, and Procurement all appeared to show only
"Overview" / the generic "This department is on the IOMS roadmap but hasn't been built yet." Coming
Soon page in production, and HSE appeared to have several modules "locked" — even though the actual
source code (verified repeatedly, across two prior audit passes) has real, working pages for all of
it. **None of that was a code bug.** The production server is very likely still serving a frontend
bundle compiled before any of that work existed, regardless of what commit `HEAD` actually points to.
No amount of further editing in `resources/js/` will change what's served until the bundle is
actually rebuilt.

**No code fix is possible for this from within a coding session** — a bundle rebuild is an
operational/deploy action, not a source change, and no `npm`/`node` binary has ever been available in
this project's own AI-coding-session environment (confirmed repeatedly). The fix is to actually run
the documented deploy flow (`deploy.sh` or, at minimum, `npm run build && git add public/build && git
commit` followed by pulling that commit to production) against the current `main` branch. Until that
happens, treat any "X isn't showing up in production" report with this as the FIRST hypothesis to
rule out — check `git log -1 -- public/build/manifest.json` before assuming a code-level navigation/
RBAC/entitlement bug, the way the two prior audit passes (reasonably, but incorrectly in hindsight)
did.

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
- **The same naive-pluralization risk applies to the *model*, not just its foreign keys — verified
  the hard way in production.** `PermitToWork` had no `protected $table` at all, so Eloquent
  inferred one from the class name: snake_case (`permit_to_work`) then pluralize only the last word
  (`work` → `works`) → `permit_to_works`. The actual migration
  (`2026_08_20_100067_create_permits_to_work_table`) — and the FKs in `gas_test_records`/
  `loto_records` that correctly `->constrained('permits_to_work')` — all use `permits_to_work`
  (pluralized as a whole compound noun, matching how a person would actually say it). The mismatch
  shipped silently because nothing exercised `PermitToWork::query()` in any environment that would
  have surfaced it before a real HSE Dashboard widget (`HseDashboardController`'s `openPermitsCount`)
  hit it in production: `SQLSTATE[42S02]: Base table 'permit_to_works' doesn't exist`. Fixed with an
  explicit `protected $table = 'permits_to_work';` — the existing, already-migrated production table
  is the source of truth; the model was wrong, not the schema. **Whenever a model's class name is a
  multi-word compound noun (`XToY`, `XOfY`, or similar), don't trust Eloquent's default table-name
  inference — check it against the model's own creation migration explicitly**, the same "verify,
  don't assume" instinct as the `->constrained()` pitfall directly above.
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
  B1 — every widget query, found while adding the Safety Observation widget, then again B16 for the
  Open Permits/Overdue Equipment/Overdue P3K/Open CAPA widgets), `IncidentController` (Workstream
  B14 — found and fixed while extending it for Investigation/CAPA), `GoodsReceiptController`
  (Workstream C5 — found and fixed while extending it for Purchase Order integration; this one had
  no `company_id` of its own at all, so the fix derives ownership through whichever parent record —
  `MaterialRequest` or `PurchaseOrder` — the receipt is linked to, via `whereHas()` for the list
  query and a dedicated `assertInCurrentTenant()` for the route-model-bound actions). Flagged, not yet fixed
  (background task, to avoid silently expanding an unrelated change's scope): `PpeController` (the
  KpiCategory-class leak). See `docs/ADR/008-tenancy-foundation.md`'s Consequences section for
  the underlying design tradeoff.

- **v1.10.5 Final Integration Pass — the per-instance ownership guard flagged above was fixed** for
  `CompetencyTypeController`/`ShiftController`/`RosterPatternController` (`update()`/`destroy()` now
  call a private `assertInCurrentTenant()` before touching the route-bound record, matching every
  other Milestone 4 controller).

- **v1.10.5 — `EmployeeController` had the SAME missing-ownership-guard bug, at larger scope and
  higher severity, in the app's original pre-Milestone-4 resource.** `show()`/`edit()`/`update()`/
  `destroy()` had no `assertInCurrentTenant()` at all — only a role check (`authorize('update'|
  'delete', ...)`, i.e. `isAdmin()`), never a check that the route-bound `$employee` actually belongs
  to the current tenant. Any admin from Tenant A could view, edit, or delete any employee record in
  the system by guessing/incrementing the id. Fixed with the same guard used everywhere else.
  **Separately, and more severe: `EmployeeController::index()` and `EmployeeExport` had no BASE
  tenant filter on the underlying query at all** (`Employee::inCompany($companyId)` is a no-op when
  `$companyId` is null, and `Employee` carries no `TenantScope` of its own — only `Company` does).
  Omitting the optional `?company_id=` filter — the default landing state of both the Employee list
  page and the Export button — returned/exported **every tenant's entire employee roster**, not a
  single-record IDOR but a full cross-tenant data leak. Fixed by adding an unconditional
  `whereIn('employees.company_id', Company::query()->pluck('id'))` as the base of both queries, with
  the optional `$companyId` filter still narrowing further within that set (a `$companyId` from
  another tenant now combines to zero rows rather than leaking that tenant's data, so it needs no
  separate validation). `StoreEmployeeRequest`/`UpdateEmployeeRequest`'s raw
  `exists:companies,id`/`exists:departments,id`/`exists:positions,id` were also replaced with
  tenant-scoped `Rule::in()`, matching every Milestone 4 FormRequest. **Flagged, not yet fixed**
  (background task): the same raw-`exists:` pattern is still present in roughly 20 other
  pre-Milestone-4 FormRequests/controllers (Project, MaterialRequest, KpiCategory, KpiRecord, Task,
  DailyReport, LeaveRequest, Milestone, PPE, Settings' Department/Position creation) — large enough
  in surface area that fixing all of it in the same pass that found it would have meaningfully raised
  regression risk with no test runner available to catch mistakes; deliberately scoped as a separate
  follow-up instead of silently expanded here.

- **v1.10.5 — `App\Http\Middleware\RestrictDepartmentAccess` flipped from fail-open to fail-closed.**
  It used to allow any route-name prefix not found in `config/departments.php` ("the map is a curated
  allow-list, not exhaustive, so an unmapped route is more likely an oversight than something to lock
  down"). In practice this meant `config/departments.php`'s `hse` array had gone stale (still only
  `ppe`/`incidents`/`kpi-input`/`kpi-records`/`hse`, predating Workstream B entirely) and every HSE
  route Workstream B actually added was reachable by direct URL from a Department User assigned to
  *any other* department — "unmapped" was silently meaning "unrestricted," discovered only by
  deliberately auditing for it, not by a bug report. `config/departments.php` is now treated as
  exhaustive (cross-checked against every route-name prefix in `routes/web.php` directly), and the
  middleware denies by default; a route genuinely needed by every department regardless of assignment
  goes in the middleware's own small `UNIVERSAL_PREFIXES` list instead of being left out of the map by
  omission. Administrators (`department_key = null`) are entirely unaffected either way — this
  middleware only ever runs for the opt-in Department User mechanism.

- **A previously-flagged "route-name collision" re-checked and found NOT to be real (v1.10.5).**
  This entry used to claim `routes/web.php` registered the name `dashboard` twice -- once for tenant
  users, once under the Platform Super Admin `/platform` group. Re-auditing the actual route
  definitions directly (not just the route-name strings written at each `->name(...)` call site)
  shows the platform group is declared as `Route::middleware(['auth', 'role:platform_admin'])
  ->prefix('platform')->name('platform.')->group(...)` -- the group's own `->name('platform.')`
  prefixes every child route's name, so `Route::get('/', ...)->name('dashboard')` inside it actually
  resolves to `platform.dashboard`, not `dashboard`. There is no collision: `route('dashboard')`
  always resolves to the one tenant-facing route. The earlier note either predates that group
  gaining its `->name('platform.')` prefix, or was written by reading the literal `->name(...)`
  argument text rather than the fully-resolved route name -- worth remembering as its own lesson:
  **when auditing for route-name collisions, always account for group-level `->name()` prefixing,
  not just the string literal passed to each individual route.**

- **Any place that sets Spatie's permission team id must use the same `0` sentinel for a Platform
  Super Admin (`tenant_id` null) that `RolePermissionSeeder` used when assigning their role** — the
  `model_has_roles`/`model_has_permissions` pivot tables require a non-null team id in their own
  primary key, so passing `null` through (the naive `$tenant?->id` translation of
  `User::isPlatformAdmin()`'s convention) makes `->hasRole()`/`->can()` silently return false for
  every Platform Super Admin at runtime, even though the seeder's own `tinker` check looked correct.
  This was caught and fixed in `App\Http\Middleware\ResolveTenant` during the same milestone that
  introduced it — see `docs/ADR/008-tenancy-foundation.md`.

- **Milestone 4, Acceleration Mode — three more tenant-scoping leaks found and fixed live:**
  `LogisticsDashboardController` and `ProjectManagementDashboardController` (found while adding new
  Warehouse/Project widgets — both had ZERO company filtering anywhere, fixed via
  `DashboardStatsService::resolveCompanyIds(null)`), and `GoodsReceiptController` a second time (found
  while adding `warehouse_id`/`item_id` handling for Warehouse integration — same parent-derived
  `assertInCurrentTenant()` pattern as its Workstream C5 fix above, just exercised through a new code
  path). Also **`NcrController::store()`**: derived `company_id` via `Company::query()->value('id')`
  (picks whichever company happens to be first) instead of a validated field — fixed by adding
  `'company_id' => ['required', Rule::in($tenantCompanyIds)]`. Same lesson each time: a leak doesn't
  announce itself until a controller is genuinely reopened to extend it — audit the query scoping of
  any controller you're about to touch, even one that looks finished.

- **Stored-vs-computed balance convention** (established Acceleration Part 1B, `Stock` vs.
  `PurchaseOrderItem::delivered_quantity`): a value gets a REAL stored column, updated atomically
  inside `DB::transaction()` + `lockForUpdate()`, when it's touched by an unboundedly large number of
  events over the record's lifetime (a warehouse `Stock` balance — movements never stop). A value
  stays *computed live* on every read when it sums a small, bounded set of rows scoped to one parent
  (`PurchaseOrderItem::getDeliveredQuantityAttribute()` sums that PO line's own GRN rows only). Don't
  default to "always store" or "always compute" — pick based on which side of that boundary the value
  actually falls on, and say so in a doc comment either way (both examples above do).

- **`StockMovement` always-positive-quantity convention** (Acceleration Part 1B): the `quantity`
  column on a movement row is ALWAYS positive; direction is entirely determined by `type`, checked via
  `StockMovement::isInbound()` against a const `INBOUND_TYPES` array — never a signed quantity that
  has to independently agree with the type. This class of bug is real: Stock Opname's variance can be
  negative (shrinkage) or positive (overage), and the first draft of `StockTransactionController::
  opname()` passed that signed variance straight through as `quantity` under a type that was
  (incorrectly) listed as always-inbound — caught in review before shipping, fixed by recording
  opname variances as real `ADJUSTMENT_IN`/`ADJUSTMENT_OUT` with `abs($variance)` instead of a
  dedicated opname type. If you add a new movement type, ask whether its underlying quantity can ever
  be negative before deciding whether it needs its own type or should map onto an existing
  `ADJUSTMENT_IN`/`OUT` pair like opname does.

## Verification discipline (and its real limits)

- **Update, v1.10.7**: `npm`/`node` became available in the AI coding session environment for the
  first time this pass (confirmed via `node -v`/`npm -v`, not assumed — `node_modules` was already
  installed) — `npm run build` was actually run and succeeded (1897 modules transformed, valid
  `manifest.json`/hashed assets produced), a REAL compile-time check, not the static balance-checking
  substitute described below. `php`/`composer` remain unavailable in this same environment, so
  `php artisan migrate`/`route:list`/PHPUnit still cannot be run from here — PHP-side verification
  for this pass is still the static kind described below. Don't assume either capability going into
  a future session without checking again; environment availability isn't stable across sessions.
- Prior to the above, this project's development had, in practice, never included actually running `php artisan
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
