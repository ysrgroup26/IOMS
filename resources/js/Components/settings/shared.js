/* v2.141.0 -- split out of Pages/Settings/Index.jsx (M0, Settings architecture). Behaviour unchanged. */
import { WORKSPACES } from '@/lib/workspaces';

// Milestone 3 (UAT #1/#3/#7 -- identity clarity): matches
// User::roleLabel()'s own mapping exactly -- see that method's doc
// comment for why "super_admin" reads "Administrator" here, not
// "Super Admin". Only the label changed; the stored role value is
// unchanged.
export const ROLE_LABELS = {
    super_admin: 'Administrator',
    hse: 'HSE',
    hrd: 'HRD',
    manager: 'Manager',
    warehouse: 'Warehouse',
};


// Each toggleable module belongs to exactly one workspace (see
// resources/js/lib/workspaces.js) -- built once, keyed by moduleKey, so
// ModulesTab can group its checkboxes under the same workspace headers
// the sidebar switcher uses, without duplicating the workspace->module
// mapping in two places.
export const MODULE_WORKSPACE = WORKSPACES.reduce((map, workspace) => {
    for (const item of workspace.items) {
        if (item.moduleKey) map[item.moduleKey] = workspace;
    }
    return map;
}, {});


/**
 * Milestone 2 (RBAC UI, Task #45). Edit which permissions each
 * tenant-side Role carries -- `spatie/laravel-permission` roles seeded
 * by RolePermissionSeeder with a sensible default set per role. This is
 * genuinely functional (writes to the `role_has_permissions` pivot,
 * `->hasRole()`/`->can()` reflect it immediately), but no existing
 * controller checks permissions yet -- every controller still runs on
 * the `role` column + isX()/canX() methods (see
 * docs/ADR/008-tenancy-foundation.md). Said plainly in the UI itself so
 * an admin editing this doesn't assume it already changes what a role
 * can do in the app today.
 */
// Milestone 3 (UAT #6): matches SettingsController::storeRole()/destroyRole()'s
// own built-in-name guard -- these 5 can't be renamed away or deleted
// from this UI, everything else is a genuinely custom, Company-Admin-
// created role.
export const BUILT_IN_ROLES = ['super_admin', 'hse', 'hrd', 'manager', 'warehouse'];


export const APPROVAL_ROLE_OPTIONS = ['super_admin', 'hse', 'hrd', 'manager', 'warehouse'];


// v1.10.7. Department options for the "restrict this user to one
// department" field -- derived from the SAME `WORKSPACES` array the
// sidebar itself uses, filtered to `tier === 'department'` (this
// naturally excludes Reports/Administration, which aren't real
// assignable departments, without needing a second hardcoded list to
// keep in sync -- config('departments')'s own `assignableDepartmentKeys()`
// on the backend does the equivalent exclusion explicitly).
/*
 * v2.96.0 -- THE PLAN DECIDES WHICH DEPARTMENTS CAN BE ASSIGNED.
 *
 * This was built from the hardcoded WORKSPACES registry, which lists every
 * department the APPLICATION has rather than the ones this ORGANIZATION
 * bought. A Starter tenant was therefore offered People, Warehouse
 * Logistics and Management in the dropdown; the assignment saved, and the
 * routes then refused the user it was given to.
 *
 * `assignableDepartments` comes from the server, through the same
 * EntitlementService the route gate and the sidebar read, so the form
 * cannot offer a department the request would reject. Kept as a fallback
 * only for the labels of departments already assigned (see labelFor).
 */
export const ALL_DEPARTMENT_OPTIONS = WORKSPACES.filter((w) => w.tier === 'department').map((w) => ({ key: w.key, label: w.label }));


export const departmentLabel = (key) => ALL_DEPARTMENT_OPTIONS.find((d) => d.key === key)?.label ?? key;
