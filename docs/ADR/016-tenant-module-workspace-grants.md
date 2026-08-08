# 016 — Platform-Tenant Module/Workspace Grants (Milestone 3, UAT #4/#5)

## Status

Accepted.

## Problem

`modules` and `workspaces` (Milestone 2, Tasks #42/#43) were flat global catalogs -- every tenant
could see and toggle on ANY module/workspace that existed anywhere in the system. That's wrong for a
real SaaS: which modules/workspaces a tenant's PLAN includes is the platform operator's decision; the
tenant's own Administrator should only control visibility WITHIN that allowance, not the allowance
itself.

## Decision

**Two new grant tables**, `tenant_modules`/`tenant_workspaces` -- plain pivots, presence of a row =
granted. Not a boolean column on `modules`/`workspaces` themselves, since a module can be granted to
some tenants and not others; the grant is inherently a per-tenant fact.

**Three-layer model, one ceiling each:**
1. **Platform grants** (`tenant_modules`/`tenant_workspaces`) -- what a tenant's plan includes at all.
   Managed by Master at `/platform/tenants/{tenant}/grants`.
2. **Tenant visibility** (`enabled_modules` CompanySetting, `workspaces.is_active`, pre-existing,
   unchanged) -- which of the GRANTED set the tenant's own Administrator currently wants visible.
3. **User-level role/module gates** (pre-existing `adminOnly`/`moduleKey` per nav item, unchanged) --
   who among the tenant's own users sees a given visible item.

**Backfill preserves existing behavior exactly.** The one existing "Default Tenant" is granted every
module and workspace that exists today (via `TenantGrantSeeder` for fresh installs, since the
migration's own backfill only covers tenants that exist at migration time -- same ordering issue as
`ModuleSeeder`/`WorkspaceSeeder` themselves, see `DatabaseSeeder`'s doc comment). A brand new tenant
created after this ships starts with ZERO grants, matching the UAT's own stated expectation.

**Enforcement on both ends:**
- `SettingsController::updateModules()`/`updateWorkspaces()` validate against the tenant's granted set,
  not the global catalog -- a Company Admin literally cannot submit a key their tenant wasn't granted.
- `HandleInertiaRequests` restricts `modules.available`/`workspace_catalog` to the granted set, so the
  Company Admin's own Settings UI never even shows an ungranted item to toggle.

## A real bug caught during this same build (not shipped)

Revoking a module's grant via the Platform UI did NOT immediately hide it from the tenant's sidebar --
the `enabled_modules` CompanySetting's stored list still contained the (now-ungranted) key, and the
`modules.enabled` computation only ever UNIONED new defaults into that stored list, never intersected
it against what's currently allowed. Caught via direct browser verification (revoked PPE's grant as
Master, confirmed via `tinker` that the grant was gone, then found "PPE Management" was STILL showing
in the HSE sidebar as Administrator). Fixed by intersecting the final enabled-modules list against the
granted set in `HandleInertiaRequests`, re-verified: the item disappeared from the sidebar immediately
on next page load, no re-save of Module Visibility required.

## Consequences

- `WorkspaceLabelsCard` (Settings > Module Visibility > Department Navigation) now filters to granted
  workspaces only, using a `granted` flag distinct from `is_active` (the Administrator's own on/off
  choice for something they DO have) -- these are deliberately separate booleans, not one overloaded
  flag, so revoking a grant can't be confused with an Administrator's own preference.
- No tenant onboarding/self-signup flow creates grants automatically yet -- every new tenant needs
  Master to visit `/platform/tenants/{tenant}/grants` and grant something before that tenant's
  Administrator can do anything module/workspace-wise. Acceptable today (tenants are still
  provisioned manually); worth automating (e.g. "grant everything in the subscribed Package's
  `features` list") once self-service signup exists.
