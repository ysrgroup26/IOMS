# 017 — Dynamic Role Management (Milestone 3, UAT #6)

## Status

Accepted.

## Problem

`RolePermissionSeeder` creates exactly 5 fixed roles matching the `role` column's 5 legacy values.
Settings > Roles & Permissions could only edit permissions ON those 5 -- an Administrator could not
create a genuinely new role for their own organization (e.g. "Regional Supervisor").

## Decision

**A Company Admin can now create/delete tenant-scoped custom Spatie roles**, and assign them to users
-- `SettingsController::storeRole()`/`destroyRole()`/`updateUserRoles()`. Built-in role names
(`super_admin`, `hse`, `hrd`, `manager`, `warehouse`) are protected from both creation-collision and
deletion.

**Custom role assignment is ADDITIVE, never replaces the user's base role.** `syncRoles()` replaces a
user's full Spatie role set, so `updateUserRoles()` always keeps `$user->role` (their existing
column-driven capability) in that sync alongside whatever custom role ids were selected --
un-checking every custom role for a user leaves their base capability completely intact.

**Honest about what this does and doesn't control**, stated in the UI itself: a custom role's
permissions are genuinely real (`$user->hasRole()`/`->can()` reflect them immediately) for anything
that checks them -- but the 5 built-in roles' actual behavior throughout the app still runs on the
`role` column + `isX()`/`canX()` methods (ADR-008's RBAC decision), unchanged by this. Creating a
custom role does not yet let a Company Admin, by itself, grant someone real access to e.g. delete
Material Requests -- that still requires the separate, larger RBAC-enforcement migration.

## Two real bugs caught during this same feature's own verification (not shipped)

1. **`SettingsController::index()`'s `users` query had no tenant filter at all** -- a Company Admin's
   own User Management page was listing EVERY user across EVERY tenant, including the Platform Master
   account (`tenant_id` null). Directly visible during manual verification: "Master" appeared in the
   tenant's own Users table. A real cross-tenant data leak the moment a second tenant exists, not just
   a display glitch. Fixed with an explicit `where('tenant_id', ...)`.

2. **`SettingsController::updateUser()` had NO ownership check whatsoever** -- `role:super_admin`
   middleware only restricts by role, not by tenant, so any Administrator could `PUT
   /settings/users/{any id}` and rename/reset the password/change the role of a user belonging to a
   DIFFERENT tenant. `UserPolicy::update()`/`delete()` had the same gap (checked only the acting
   user's role, never whether `$target` was even in the same tenant). Both fixed: an explicit
   `abort_unless($user->tenant_id === $request->user()->tenant_id, 404)` in the controller, and the
   policy now also requires `$user->tenant_id === $target->tenant_id`.

These were found by literally reading the rendered Users table during this feature's own browser
verification pass -- seeing "Master" in a tenant's own user list was the tell.

## Broader audit sweep (same session, same class of bug)

Following the two findings above, `Department`/`Position`/`KpiCategory` update/destroy methods and
`Company` creation were checked for the same pattern and found equally exposed:
- `updateDepartment()`/`destroyDepartment()`/`updatePosition()`/`destroyPosition()`/
  `updateKpiCategory()`/`destroyKpiCategory()` accepted route-bound models with **no** tenant
  ownership check at all (these models carry no `TenantScope` of their own -- only `Company` does,
  per ADR-008's "anchor" design). Fixed with a new `assertCompanyInTenant()` helper that leans on
  `Company::find()` (Eloquent, so TenantScope applies) to 404 for any cross-tenant `company_id`.
- Every `Rule::exists('companies', 'id')` / `Rule::unique('companies', ...)` validation rule in this
  controller queries the **raw** `companies` table, bypassing Eloquent (and therefore TenantScope)
  entirely -- a `company_id` belonging to a different tenant would otherwise validate successfully.
  Closed at the same points, plus `Rule::unique(...)->where('tenant_id', ...)` for company name/code
  uniqueness (now scoped per-tenant, not global).
- `storeCompanyEntity()` never set `tenant_id` on the new `Company` row at all -- since
  `companies.tenant_id` is `NOT NULL` (Milestone 2), "Add Company" from Settings was actually
  **crashing** with a DB error for every Company Admin, not silently leaking. Confirmed via browser
  reproduction before the fix (a real `SQLSTATE` error), then confirmed fixed after (a company named
  "TestCo" created successfully, visible in the Companies list).

## Consequences

- Every other user-management-adjacent controller method should be checked for the same
  "route-restricted-by-role-but-not-by-tenant" pattern before this milestone is called done -- this
  exact class of bug is easy to repeat wherever a `role:super_admin` middleware group was assumed to
  be sufficient authorization on its own.
