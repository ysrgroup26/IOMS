# 008 — Tenancy Foundation (Milestone 2)

## Status

Accepted (v2.0.0, Milestone 2). Architecture Frozen — see "Architecture Freeze" note at the end.

## Problem

IOMS's existing `Company` model (GAJ, Maintenance) is an internal business unit within a single
deployment, not a SaaS tenant — its own docblocks and `TenantContext`/`IdentifyTenant` confirm this
(Super Admin intentionally sees across all Companies). Turning IOMS into a real multi-tenant SaaS
platform (per the approved SaaS Blueprint) needs a genuine tenant concept *above* Company, without
rewriting the ~15 tables and every controller that already scope correctly through `company_id`.

## Decision

**Shared database + a `tenant_id` column, not database-per-tenant.** One MySQL database, `tenants`
table, `tenant_id` foreign key added to the tables that need it. Simpler to operate, migrate, and
back up at this stage than provisioning a database per customer; the isolation guarantee comes from
query scoping (below), which is sufficient at this milestone's scale.

**`Tenant` sits above `Company`: `Tenant hasMany Company`.** `Company` keeps its existing meaning
(internal business unit) completely unchanged; a `Tenant` is the new top-level paying-customer
concept. Existing code that reasons about Companies (`TenantContext`, department/position scoping,
every `company_id` FK chain) needed zero changes in meaning.

**Only `companies.tenant_id` and `users.tenant_id` are added — not a `tenant_id` on every table.**
Every operational table (departments, positions, employees, tasks, incidents, leave_requests,
milestones, goods_receipts, material_requests, ...) already scopes through `company_id`, directly or
transitively (e.g. `Milestone belongsTo Project` which has `company_id`). Adding a parallel
`tenant_id` to all of them would be redundant, driftable data, not a safety improvement.
`companies.tenant_id` is the single anchor; `App\Models\Company`'s own global scope
(`App\Models\Scopes\TenantScope`) is what actually enforces isolation for everything beneath it.

**`TenantScope` fails closed.** With no resolved tenant (e.g. a guest request, or a CLI context that
never ran `ResolveTenant`), the scope filters to `tenant_id = -1` — matching nothing — rather than
showing all tenants' data. Correct default for a security boundary; the cost is that anything running
outside an HTTP request (artisan tinker, a queued job, a seeder) must explicitly resolve/bind a
tenant first or every Company-scoped query silently returns empty. `DatabaseSeeder` does this once,
up front, for exactly this reason — see its own doc comment.

**`users.tenant_id` is nullable *forever*, not backfilled to `NOT NULL` the way `companies.tenant_id`
was.** `NULL` is a real, permanent, intentional state: **Platform Super Admin** — someone who works
for the platform operator, not for any customer tenant (`User::isPlatformAdmin()`). Every
pre-Milestone-2 account was backfilled to one "Default Tenant" instead of becoming a platform admin,
preserving existing login behavior exactly. A genuinely new Platform Super Admin account
(`platform@ioms.local`, role `platform_admin`) is created deliberately by `PlatformAdminSeeder`, never
an accidental side effect of the column existing — any code path that creates a `User` (seeders,
`SettingsController::storeUser`) must set `tenant_id` explicitly, or that account silently becomes an
unintended Platform Super Admin and fails every Company-scoped query closed for itself. This exact
mistake was made and caught during this milestone's own verification pass (`UserSeeder` and
`SettingsController::storeUser` both needed the explicit fix) — see `docs/CONVENTIONS.md`.

**Platform Super Admin has no special tenant-data access by default.** `isPlatformAdmin()` only means
"no tenant" — it does not imply `isSuperAdmin()` or any of the existing `isX()`/`canX()` checks, all of
which remain keyed off the tenant-side `role` values. A platform admin logging into the normal app
today sees an empty, correctly-scoped dashboard (0 companies, 0 employees), which is correct: their
actual surface is the platform-level `/platform/*` area (Task #44, not yet built), not any tenant's
operational data.

**RBAC via `spatie/laravel-permission`, teams feature repurposed as tenant scoping**, not a
hand-rolled permission table and not `stancl/tenancy` for the tenancy mechanism itself (manual
implementation was chosen — see the Blueprint decision log). `config/permission.php`'s
`team_foreign_key` is renamed from `team_id` to `tenant_id`; `ResolveTenant` calls
`PermissionRegistrar::setPermissionsTeamId()` every request so role/permission checks are
automatically tenant-scoped too. `User` gained `HasRoles` but the existing `role` column and
`isX()/canX()` methods remain the live authorization path unchanged — migrating call sites to
permission-based checks is a deliberately separate, later step, not bundled into this one.

**Permission catalog is `module.action` strings (`config/permission_catalog.php`), no `.scope`
suffix yet.** `RolePermissionSeeder` creates the full catalog (global, not tenant-scoped —
permissions are the platform's shared vocabulary) and, per tenant, one Role per existing `role`
column value with a permission set matching that role's current `isX()/canX()` capabilities as
closely as possible, then assigns it to every seeded user. This makes `->hasRole()`/`->can()`
genuinely usable starting now (for Task #45's Role/Permission management UI in particular) without
changing what any controller actually enforces today.

**Platform Super Admin's role uses a `0` team-id sentinel, not `null`.** Spatie's
`model_has_roles`/`model_has_permissions` pivot tables require a non-null team id as part of their
own primary key even though `roles.tenant_id` itself is nullable — so `User::isPlatformAdmin()`'s
`tenant_id === null` convention can't be reused directly for role assignment. `0` is used instead:
never a real tenant id (auto-increment starts at 1) and distinct from `TenantScope`'s own `-1`
"unresolved tenant" sentinel, so the two can never collide.

**No URL namespacing, no separate tenant-resolution-by-subdomain yet.** Tenant is resolved from the
authenticated user (`$user->tenant`), not from the request host/path. Subdomain-per-tenant routing is
a plausible future addition but out of scope for this milestone — the SaaS Blueprint's tenant
hierarchy needed to exist and enforce isolation before routing/UX around it is worth building.

**Legacy `roles` table dropped**, not migrated. `2024_01_01_000002_create_roles_table.php`'s table and
its `App\Models\Role`/`RoleSeeder` collided by name with Spatie's own `roles` table and were confirmed
(by grep) to be pure dead/decorative reference data — `users.role` was always the real auth source, this
table was never read anywhere else. Dropped outright rather than renamed/reconciled.

**`Package` (a pricing/feature tier) and `Subscription` (a Tenant's subscription period) exist as
structure only at this stage** — no payment gateway integration, no invoicing. `Subscription` is a
history table (one row per period, `Tenant::subscription()` is `hasOne ... latestOfMany()`) rather
than a single mutable row per Tenant, so plan changes/renewals stay auditable. `Package` is not
tenant-scoped — it's the platform operator's own catalog (Starter/Professional/Enterprise seeded by
default), manageable only from the future Platform Super Admin surface (Task #44).

**`Workspace` (a DB metadata catalog for `resources/js/lib/workspaces.js`'s WORKSPACES array) overrides
label/icon/order/active-state only, never structure.** Same boundary as `Module` above: a workspace's
`items` (real routes, `moduleKey`/`adminOnly` gates, `disabled` placeholders) stay in code, because an
item is fundamentally tied to an already-built page -- letting the DB define arbitrary routes would
let an admin configure a broken link, not a working feature. `resources/js/lib/workspaces.js`'s
`applyCatalog()` merges the `workspace_catalog` Inertia prop onto the hardcoded array; a
missing/not-yet-seeded row for a key falls back to that workspace's hardcoded default, so the merge is
purely additive -- passing no catalog reproduces the exact pre-Milestone-2 behavior. Settings →
Module Visibility gained a "Department Navigation" section (rename/reorder/hide existing departments)
writing to this table via `SettingsController::updateWorkspaces()`.

**`/platform/*` (Task #44) is a separate route group and a separate frontend layout**
(`PlatformLayout.jsx`, not `AuthenticatedLayout`), gated by `role:platform_admin`. Login redirects a
Platform Super Admin here instead of `route('dashboard')` (which would only ever show them an empty,
correctly-scoped-to-nothing tenant view). `PlatformController` lists/manages `Tenant` rows (status:
trial/active/suspended/expired) and surfaces `Package`/`Subscription` counts -- explicitly bypasses
`TenantScope` on its `companies` count query (`withoutGlobalScope`), since that scope's fail-closed
behavior is correct for tenant-side pages but wrong here: this controller's entire purpose is
cross-tenant visibility for the platform operator. Caught as a real bug during this same milestone's
verification pass (every tenant showed "0 companies" before the fix).

**Settings → "Roles & Permissions" (Task #45)** lets a Company Admin edit each tenant-side role's
permission set (`SettingsController::updateRolePermissions()`, scoped to the requesting admin's own
`tenant_id` -- a role belonging to another tenant, or the platform_admin role, 404s rather than being
editable, even if its id is guessed). Genuinely functional (writes to
`role_has_permissions`, `->hasRole()`/`->can()` reflect it immediately) but explicitly labeled in the
UI as not yet controlling any actual page's behavior, consistent with the RBAC decision above. Caught
a real bug during verification: `Role::get()->map(fn ($r) => $r->permissions...)` triggered
`LazyLoadingViolationException` (`Model::preventLazyLoading()` is on for non-production, see
`AppServiceProvider::boot()`) -- fixed with an explicit `->with('permissions')` eager-load.

## Consequences

- Every future `User::create()` call site (new registration flow, invite flow, etc.) must set
  `tenant_id` explicitly — there is no default that makes this safe automatically. Documented as a
  known pitfall in `docs/CONVENTIONS.md`.
- Dashboard/report widgets that query operational tables (KPI records, incidents, etc.) directly,
  without going through a `Company`-scoped relationship, are **not** currently filtered by tenant —
  only `Company` itself carries the enforced global scope. This is a non-issue today (exactly one
  tenant exists in production), but becomes a real cross-tenant data leak risk the moment a second
  tenant is onboarded. Flagged here explicitly as required follow-up work before this app is sold to
  more than one paying customer — likely a `BelongsToCompany`-style trait/global scope applied to
  every Company-scoped model directly, rather than relying on relationship traversal through
  `Company`.
- Artisan Tinker/CLI sessions see empty Company-scoped results unless a tenant is bound manually
  (`app(App\Support\CurrentTenant::class)->set($tenant)`) — this is intended fail-closed behavior, not
  a bug, but worth knowing before assuming `Company::count()` in `tinker` reflects reality.

## Architecture Freeze

As of this milestone, the tenancy foundation above is **frozen** per explicit direction: no further
architectural alternatives should be proposed for it unless a genuine technical blocker makes the
current design unworkable. Remaining Milestone 2 work (Package/Subscription, Dynamic Module/Workspace,
Platform Super Admin UI, Role/Permission management UI) builds on top of this foundation as-is.
