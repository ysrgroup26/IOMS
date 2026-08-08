import {
    Users, ClipboardEdit, FileBarChart, Settings, FolderKanban, HardHat,
    ClipboardList, PackageSearch, Warehouse, ShoppingCart, Wrench,
    BadgeCheck, DollarSign, Box, LayoutDashboard, CalendarDays,
    AlertTriangle, PackageCheck, Flag, BarChart3, FileDown,
} from 'lucide-react';

/**
 * Department Navigation Architecture (v1.10.2 -- see
 * docs/ADR/007-workspace-navigation.md's v1.10.2 section). Still called
 * "Workspace" internally (this file, `getVisibleWorkspaces()`) -- only
 * the user-facing label is "Department" as of v1.8.0.
 *
 * v1.10.2 -- Global Dashboard vs. Department Overview, made explicit:
 *   - The Global Dashboard (`dashboard` route) is NOT in this array at
 *     all anymore. It's not a department and was never meant to be
 *     switchable -- it's a permanently pinned top-bar link
 *     (AuthenticatedLayout's TopBar), reachable independent of whichever
 *     department is currently active.
 *   - Each core department's own dashboard item is now labeled
 *     "Overview" (not "Dashboard") to make the distinction impossible to
 *     miss in the UI itself: Dashboard = company-wide, Overview =
 *     this-department-only. The route names themselves are unchanged
 *     (`hr.dashboard`, `hse.dashboard`, ...) -- only the user-facing
 *     label changed, matching the same "internal name can differ from
 *     UI label" precedent as Workspace/Department itself.
 *   - Each core department ALSO gets its own leading item literally
 *     named "Dashboard", linking back to the global `dashboard` route --
 *     `global: true` marks it as NOT owned by this department (excluded
 *     from `PREFIX_TO_WORKSPACE` below), so visiting the Global Dashboard
 *     never falsely highlights whichever department happened to define
 *     this link last.
 *   - Reports and Administration are no longer offered inside the
 *     Department Selector dropdown -- they aren't departments, and mixing
 *     them into that list undermined "the Department Selector contains
 *     only Departments." They're still fully functional, reached instead
 *     through the sidebar's "Global navigation" state (see
 *     `getGlobalNavItems()` below), which is what the sidebar shows
 *     whenever no department is currently active (i.e. on the Global
 *     Dashboard, Reports, or Settings pages).
 *   - Department Users (`user.department_key` set) never see the
 *     Department Selector at all and never see Global navigation --
 *     `getSelectableDepartments()` collapses to their one assigned
 *     department. See `app/Models/User.php`'s own note on `department_key`
 *     for why this is opt-in and changes nothing for any existing user.
 */
export const WORKSPACES = [
    {
        key: 'hr',
        label: 'Human Resources',
        icon: Users,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'hr.dashboard', icon: LayoutDashboard },
            { name: 'Employees', href: 'employees.index', icon: Users, moduleKey: 'employees' },
            { name: 'Attendance', icon: ClipboardList, disabled: true },
            { name: 'Leave', href: 'leave-requests.index', icon: CalendarDays },
            { name: 'Recruitment', icon: Users, disabled: true },
            { name: 'Performance', icon: BadgeCheck, disabled: true },
            { name: 'Training', icon: ClipboardList, disabled: true },
            // v1.10.4 correction: the existing KPI implementation
            // (kpi-input.index) was mapped here in v1.10.0, but its
            // routes are already gated to role:super_admin,hse at the
            // route level -- it was always HSE's module, just
            // mis-labeled/mis-placed in nav. Moved to HSE below; this
            // stays a locked placeholder ("HR KPI" -- a genuinely
            // separate future concept, HR's own KPI tracking, not yet
            // built) rather than a real link.
            { name: 'HR KPI', icon: ClipboardEdit, disabled: true },
            { name: 'Documents', icon: ClipboardList, disabled: true },
            { name: 'Reports', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'hse',
        label: 'HSE',
        icon: HardHat,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'hse.dashboard', icon: LayoutDashboard },
            // PPE's own Dashboard/Employee PPE/Master/Reports split lives
            // in PpeTabNav *within* the module, not as sidebar sub-items.
            { name: 'PPE Management', href: 'ppe.dashboard', icon: HardHat, moduleKey: 'ppe' },
            { name: 'Permit To Work', icon: ClipboardList, disabled: true },
            { name: 'Incident Management', href: 'incidents.index', icon: AlertTriangle },
            { name: 'Inspection', icon: ClipboardList, disabled: true },
            { name: 'Risk Assessment', icon: ClipboardList, disabled: true },
            { name: 'Safety Meeting (TBM)', icon: Users, disabled: true },
            { name: 'Training', icon: ClipboardList, disabled: true },
            // v1.10.4 correction: moved from HR (see HR's own note above)
            // -- same route, same controller, same permissions, same
            // moduleKey. Nothing about the implementation changed, only
            // which department's sidebar links to it.
            { name: 'HSE KPI', href: 'kpi-input.index', icon: ClipboardEdit, adminOnly: true, moduleKey: 'kpi_input' },
            { name: 'Documents', icon: ClipboardList, disabled: true },
            { name: 'Reports', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'project-management',
        label: 'Project Management',
        icon: FolderKanban,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'project-management.dashboard', icon: LayoutDashboard },
            { name: 'Projects', href: 'projects.index', icon: FolderKanban, moduleKey: 'projects' },
            // Daily Reports lives here, not HSE: it's a per-project
            // activity/progress log -- see ADR-007 for the reasoning.
            { name: 'Daily Reports', href: 'daily-reports.index', icon: ClipboardList, moduleKey: 'daily_reports' },
            { name: 'Activities', icon: ClipboardList, disabled: true },
            { name: 'Milestones', href: 'milestones.index', icon: Flag },
            { name: 'Documents', icon: ClipboardList, disabled: true },
            { name: 'Reports', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'logistics',
        label: 'Logistics / PPIC',
        icon: PackageSearch,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'logistics.dashboard', icon: LayoutDashboard },
            { name: 'Material Requests', href: 'material-requests.index', icon: PackageSearch, moduleKey: 'material_requests' },
            // Warehouse stays inside Logistics for now, per explicit
            // instruction -- not split into its own department yet, even
            // though a separate (still-disabled) "Warehouse" department
            // also exists below for future use.
            { name: 'Warehouse', icon: Warehouse, disabled: true },
            { name: 'Inventory', icon: Box, disabled: true },
            { name: 'Goods Receipt', href: 'goods-receipts.index', icon: PackageCheck },
            { name: 'Goods Issue', icon: ClipboardList, disabled: true },
            { name: 'Stock Movement', icon: ClipboardList, disabled: true },
            { name: 'Documents', icon: ClipboardList, disabled: true },
            { name: 'Reports', icon: FileBarChart, disabled: true },
        ],
    },
    // Future Departments: kept visible in the selector (explicit
    // instruction: "do not remove them"), each with a single real link to
    // the shared ComingSoon page rather than a disabled row -- there IS
    // somewhere real to go now, even though nothing is built yet.
    {
        key: 'warehouse',
        label: 'Warehouse',
        icon: Warehouse,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'warehouse.coming-soon', icon: Warehouse },
        ],
    },
    {
        key: 'procurement',
        label: 'Procurement',
        icon: ShoppingCart,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'procurement.coming-soon', icon: ShoppingCart },
        ],
    },
    {
        key: 'asset-management',
        label: 'Asset Management',
        icon: Box,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'asset-management.coming-soon', icon: Box },
        ],
    },
    {
        key: 'maintenance',
        label: 'Maintenance',
        icon: Wrench,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'maintenance.coming-soon', icon: Wrench },
        ],
    },
    {
        key: 'quality-control',
        label: 'Quality Control',
        icon: BadgeCheck,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'quality-control.coming-soon', icon: BadgeCheck },
        ],
    },
    {
        key: 'finance',
        label: 'Finance',
        icon: DollarSign,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Overview', href: 'finance.coming-soon', icon: DollarSign },
        ],
    },
    // Reports and Administration: NOT departments, NOT offered in the
    // Department Selector (see this file's top-of-file note) -- reached
    // via the sidebar's "Global navigation" state instead
    // (getGlobalNavItems()). Kept as WORKSPACES entries purely so
    // getWorkspaceKeyForRoute() and the existing two-gate visibility
    // filter continue to work unchanged for their routes.
    {
        key: 'reports',
        label: 'Reports',
        icon: FileBarChart,
        tier: 'global',
        items: [
            { name: 'Reports', href: 'reports.index', icon: FileBarChart, moduleKey: 'reports' },
            // Milestone 3 (Task #64, Analytics Framework): no moduleKey --
            // it's a cross-module reporting surface over whatever datasets
            // ARE currently enabled, not gated by any single module toggle
            // itself (mirrors Work Center's own "no moduleKey" reasoning).
            { name: 'Analytics', href: 'analytics.index', icon: BarChart3 },
            // Milestone 3 (Task #65, Report Center): same "no moduleKey"
            // reasoning -- it's a generic download/schedule surface over
            // the same dataset registry Analytics reads from.
            { name: 'Report Center', href: 'report-center.index', icon: FileDown },
        ],
    },
    {
        key: 'administration',
        label: 'Administration',
        icon: Settings,
        core: true,
        tier: 'global',
        items: [
            // v1.10.3 bugfix: this array used to start with a disabled
            // "Dashboard" placeholder item -- a leftover from an early
            // draft of this workspace, never actually meaningful (Users/
            // Departments/Positions/Companies/Settings/Module Management
            // below are Administration's real content). It had the
            // unintended effect of rendering something literally named
            // "Dashboard" as locked, directly contradicting "Dashboard
            // must never be disabled." Removed outright rather than
            // renamed -- Administration doesn't need its own dashboard
            // concept.
            //
            // Settings/Index.jsx is one tabbed page (already supports
            // ?tab= deep-linking) -- these route to the SAME real page,
            // not new pages, matching "no duplicate modules" and "reuse
            // existing components" over building six new routes.
            { name: 'Users', href: 'settings.index', queryParams: { tab: 'users' }, icon: Users, adminOnly: true },
            { name: 'Departments', href: 'settings.index', queryParams: { tab: 'departments' }, icon: Users, adminOnly: true },
            { name: 'Positions', href: 'settings.index', queryParams: { tab: 'positions' }, icon: Users, adminOnly: true },
            { name: 'Companies', href: 'settings.index', queryParams: { tab: 'companies' }, icon: Users, adminOnly: true },
            { name: 'Settings', href: 'settings.index', icon: Settings, adminOnly: true },
            { name: 'Module Management', href: 'settings.index', queryParams: { tab: 'modules' }, icon: Settings, adminOnly: true },
            // Milestone 3 (Task #50): was a disabled placeholder since
            // v1.9.0 -- now a real link to the Activity Center.
            { name: 'Audit Logs', href: 'activity-center.index', icon: ClipboardList, adminOnly: true },
        ],
    },
];

/**
 * FUTURE WORKSPACES -- domains with no entry above at all yet (deeper
 * roadmap items beyond even the Future Departments already scaffolded):
 * Marine Operations, Document Control, Visitor Management, Contractor
 * Management. Add a new WORKSPACES entry the same way the v1.9.0
 * placeholder departments were added when one of these is ready to be
 * previewed in navigation.
 */

const GLOBAL_NAV_KEYS = ['reports', 'administration'];

function isDepartmentTier(workspace) {
    return workspace.tier === 'department';
}

/** Applies the same two-gate filter every item already had (adminOnly against `isAdmin`, moduleKey against the enabled-modules list). */
function applyItemGates(items, isAdmin, modules) {
    return items.filter((item) =>
        (!item.adminOnly || isAdmin) && (!item.moduleKey || modules.includes(item.moduleKey))
    );
}

// Milestone 2 (Dynamic Workspace system, Task #43). Maps the `icon`
// string a `workspaces` DB row can carry back to the actual lucide-react
// component -- only the components this file already imports are valid
// values (see the migration's own doc comment for why the DB only
// overrides label/icon/order/active-state, not structure).
const ICON_MAP = {
    Users, ClipboardEdit, FileBarChart, Settings, FolderKanban, HardHat,
    ClipboardList, PackageSearch, Warehouse, ShoppingCart, Wrench,
    BadgeCheck, DollarSign, Box, LayoutDashboard, CalendarDays,
    AlertTriangle, PackageCheck, Flag,
};

/**
 * Merges the `workspace_catalog` Inertia prop (keyed by workspace `key`,
 * shared by HandleInertiaRequests) onto the hardcoded WORKSPACES array --
 * label/icon/order/active-state only. A missing/absent row for a given
 * key (not-yet-seeded install, or a workspace added to code before its
 * catalog row exists) falls back to that workspace's hardcoded default,
 * so this is purely additive: passing no catalog at all reproduces
 * today's exact behavior.
 */
function applyCatalog(workspaces, catalog) {
    if (!catalog || Object.keys(catalog).length === 0) return workspaces;

    return workspaces
        .map((workspace, index) => {
            const override = catalog[workspace.key];
            if (!override) return { ...workspace, __order: 999 + index };

            return {
                ...workspace,
                label: override.label ?? workspace.label,
                icon: ICON_MAP[override.icon] ?? workspace.icon,
                __active: override.is_active,
                __order: override.sort_order ?? (999 + index),
            };
        })
        .filter((workspace) => workspace.__active !== false)
        .sort((a, b) => a.__order - b.__order);
}

/**
 * Full gated workspace list (departments AND reports/administration) --
 * used for route-ownership lookups and anywhere the distinction between
 * "department" and "global nav" doesn't matter. `disabled` items have no
 * `moduleKey`, so they always pass this gate and are always visible (as
 * disabled rows); they're a structural preview, not something a module
 * toggle controls. A workspace disappears only if ALL of its items get
 * filtered out, OR its DB catalog row has `is_active: false` (Task #43).
 */
export function getVisibleWorkspaces(user, enabledModules, workspaceCatalog) {
    const isAdmin = user?.is_admin;
    const modules = enabledModules ?? [];

    return applyCatalog(WORKSPACES, workspaceCatalog)
        .map((workspace) => ({ ...workspace, items: applyItemGates(workspace.items, isAdmin, modules) }))
        .filter((workspace) => workspace.items.length > 0);
}

/**
 * What the Department Selector actually offers (v1.10.2) -- department-
 * tier workspaces only, never Reports/Administration. If the user has a
 * `department_key` (a Department User, not an Administrator), this
 * collapses to just their one assigned department -- see
 * `app/Models/User.php`'s note on `department_key` for the full
 * reasoning. An Administrator (`department_key` null) sees every visible
 * department, exactly like `getVisibleWorkspaces()` did before.
 */
export function getSelectableDepartments(user, enabledModules, workspaceCatalog) {
    const departments = getVisibleWorkspaces(user, enabledModules, workspaceCatalog).filter(isDepartmentTier);

    if (!user?.department_key) return departments;

    return departments.filter((workspace) => workspace.key === user.department_key);
}

/**
 * The sidebar's "Global navigation" state (v1.10.2) -- shown whenever no
 * department is currently active (Global Dashboard, Reports, Settings).
 * A flat merge of Reports + Administration's own gated items, since both
 * are small enough that splitting them into their own sub-headers isn't
 * worth the extra visual weight. Department Users never see this at all
 * (enforced by the caller checking `user.department_key` first, not by
 * this function, since "what Global nav contains" and "who gets to see
 * it" are separate questions).
 */
export function getGlobalNavItems(user, enabledModules, workspaceCatalog) {
    return getVisibleWorkspaces(user, enabledModules, workspaceCatalog)
        .filter((workspace) => GLOBAL_NAV_KEYS.includes(workspace.key))
        .flatMap((workspace) => workspace.items);
}

// Keyed by route-name prefix, real items only -- disabled items have no
// `href` so they're skipped here rather than crashing on
// `item.href.split(...)`. Items marked `global: true` (the "Dashboard"
// link repeated inside every department) are ALSO skipped -- they point
// at a route (`dashboard`) that isn't owned by any one department, and
// letting the last department to define it "win" would falsely highlight
// that department every time someone visits the Global Dashboard. A
// route-name prefix can legitimately belong to more than one item within
// the same workspace (e.g. Administration's several `settings.index`
// items with different `queryParams`); the map only needs the workspace
// key, so re-assigning the same value is harmless. The Future
// Departments' `{department}.coming-soon` route names are exactly why
// every OTHER prefix here must also stay unique per workspace.
const PREFIX_TO_WORKSPACE = WORKSPACES.reduce((map, workspace) => {
    for (const item of workspace.items) {
        if (!item.href || item.global) continue;
        map[item.href.split('.')[0]] = workspace.key;
    }
    return map;
}, {});

/**
 * Reverse lookup: given a route name (e.g. 'ppe.employees'), which
 * workspace owns it. Returns null for the Global Dashboard itself
 * (`dashboard` route) and for any route with no owning workspace at all
 * -- both correctly mean "no department is active," which is exactly
 * when the sidebar should fall back to Global navigation.
 */
export function getWorkspaceKeyForRoute(routeName) {
    if (!routeName) return null;
    return PREFIX_TO_WORKSPACE[routeName.split('.')[0]] ?? null;
}

/** Whether a resolved workspace key belongs to a department (as opposed to 'reports'/'administration' or no match at all). */
export function isDepartmentWorkspaceKey(key) {
    return WORKSPACES.some((w) => w.key === key && isDepartmentTier(w));
}
