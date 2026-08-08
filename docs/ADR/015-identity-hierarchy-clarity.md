# 015 — Identity Hierarchy Clarity: Master vs Administrator (Milestone 3, UAT)

## Status

Accepted.

## Problem

UAT testing surfaced genuine confusion between the platform operator's own account and a tenant's own
full-access account -- both were labeled some variant of "Super Admin" in the UI (`roleLabel()`
returned "Super Admin" for the tenant-side role, "Platform Super Admin" for the platform-side role; the
seeded tenant admin account was literally named "Super Admin", so the header's "Good Morning,
{first name}" greeting showed "Good Morning, Super"). Milestone 2 already built the *correct*
architecture (a genuinely separate `platform_admin` role, `User::isPlatformAdmin()`, a separate
`/platform/*` surface -- see ADR-008) -- what was missing was making that distinction legible in the
UI itself.

## Decision

**Two words, used consistently everywhere:**
- **Master** -- the platform operator (`role = platform_admin`, `tenant_id` null). Manages Tenants,
  Packages, Subscriptions, and now Module/Workspace grants (ADR-016) -- never a tenant's own
  operational data (ADR-008's TenantScope already enforces this technically; this ADR just names it
  correctly).
- **Administrator** -- a tenant's own full-access account (`role = super_admin`, tenant-scoped). Full
  control over their own tenant: Users, Roles, Permissions, Branding, Workspace, Modules (within
  Platform's granted set), Approval Flow, Notification, Numbering, Document Templates, and more.

**Only the LABEL changed, not the stored value.** `role` column values (`super_admin`,
`platform_admin`) are unchanged everywhere -- `config/workflow.php`, every `role:super_admin` route
middleware, `RolePermissionSeeder`'s Spatie role names, `isSuperAdmin()`/`isPlatformAdmin()` method
names. Renaming the stored string would cascade into dozens of files for zero behavioral gain; the
actual problem was display text, not data modeling.

**Fixed at every place a label was already flowing through**, rather than scattered hardcoded strings:
- `User::roleLabel()` -- the one method every label ultimately depends on.
- `resources/js/Pages/Settings/Index.jsx`'s own `ROLE_LABELS` map (User Management tab) and the
  Create/Edit User role dropdown.
- Seeded account display names: `admin@ioms.local` is now named "Administrator" (was "Super Admin" --
  the literal string the header greeting was showing), `platform@ioms.local` is now named "Master".
- `AuthenticatedLayout`'s profile menu now shows the role label directly in the header (previously
  only visible after opening the dropdown) -- first name AND role badge together, always visible.
- `PlatformLayout`'s top badge changed from generic "Platform" to "Master"; the user's own name in the
  header now shows "(Master)" alongside it.

## Consequences

- Any future UI text that names a role should go through `roleLabel()` / the matching frontend
  `ROLE_LABELS` map, not a new hardcoded string -- otherwise this exact confusion recurs piecemeal.
- Existing production installs that already have a user named literally "Super Admin" keep that name
  (the seeder change only affects fresh installs/idempotent-skip accounts) -- a real deployment should
  rename that account's `name` field directly if the same confusion applies there.
