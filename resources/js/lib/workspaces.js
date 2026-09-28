import {
    Users, ClipboardEdit, FileBarChart, Settings, FolderKanban, HardHat,
    ClipboardList, PackageSearch, Warehouse, ShoppingCart, Wrench,
    BadgeCheck, DollarSign, Box, LayoutDashboard, CalendarDays,
    AlertTriangle, PackageCheck, Flag, BarChart3, FileDown, GraduationCap, Clock, Eye, ListChecks,
    ShieldAlert, FileWarning, Flame, Lock, UsersRound, Stethoscope, Siren, ClipboardCheck,
    FileStack, FileQuestion, Building2, TrendingUp, Boxes, ArrowRightLeft, UserCheck, FileCheck,
    FlaskConical, Recycle,
    Scale,
    FileSignature,
    // v2.73.0 -- HSE Investigation. A magnifier, not another shield:
    // every other Safety Management item is already a warning glyph, and
    // the thing that distinguishes this one is that somebody is looking
    // into it.
    SearchCheck,
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
/**
 * v2.2.0 (IOMS OS Ecosystem pass, Part 10 -- Language Direction). Every
 * item `name` in this file was translated to Indonesian in v1.11.7
 * ("Bahasa Indonesia Standardization"); that direction is now REVERSED
 * for navigation specifically -- explicit product decision: SIDEBAR /
 * NAVIGATION / MENU stays ENGLISH (the terminology an operator actually
 * scans quickly and the vocabulary already used in training/documents),
 * while PAGE CONTENT (headers, descriptions, empty states, statuses)
 * stays natural Bahasa Indonesia. This file only ever controlled
 * navigation labels -- page-level Indonesian text (e.g. "Belum ada data
 * Man-Hour.", "Menunggu persetujuan.") lives in each Pages/*.jsx file
 * and is UNCHANGED by this pass. Hrefs/route names/moduleKeys were never
 * touched by either language pass -- only the `name` strings.
 */
export const WORKSPACES = [
    {
        key: 'hr',
        // v2.52.0: the FULL English name. "HR" is shorthand a new user has
        // to decode, and companies disagree about it anyway (HR vs HC).
        // The key stays `hr` -- every grant row, route prefix and
        // department map is keyed on it, so renaming the key would be a
        // migration of the authorization model dressed up as a copy edit.
        label: 'People / HRD',
        icon: Users,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true, globalDashboardOnly: true },
            { name: 'My Work', href: 'my-work', icon: ClipboardList, global: true, departmentUserOnly: true },
            { name: 'Overview', href: 'hr.dashboard', icon: LayoutDashboard },
            { name: 'Employees', href: 'employees.index', icon: Users, moduleKey: 'employees' },
            // v1.11.6 (Production Readiness pass, Part 4): "Attendance"
            // was a disabled placeholder with no backing data. Man-Hour
            // is the closest real equivalent this pass actually built
            // (regular/overtime hours per employee per date) -- replaces
            // the placeholder rather than sitting alongside it as a
            // second, confusing "almost the same thing" entry.
            { name: 'Man-Hour', href: 'man-hour.index', icon: ClipboardList },
            { name: 'Leave', href: 'leave-requests.index', icon: CalendarDays },
            // v2.69.0 -- Employee Cases (employee relations / discipline).
            // Sits directly under Employees and Leave because it is about
            // the same person record. Visibility is NOT decided here: the
            // controller gates every action on canManageEmployeeCases(),
            // and this entry only hides a link the server would refuse
            // anyway -- navigation is subordinate to authorization, per
            // ADR/007.
            { name: 'Employee Cases', href: 'employee-cases.index', icon: ShieldAlert },
            // Milestone 4, Workstream A3: Shift & Roster Management --
            // real backend (Shift/EmployeeShiftAssignment/RosterPattern/
            // EmployeeRoster). "Shift/Roster belongs to HR/Workforce
            // Management" per spec -- other modules (HSE fatigue checks,
            // Project manpower) may consume this data later without it
            // moving out of HR.
            { name: 'Shift & Roster', href: 'shifts.master', icon: Clock },
            // Milestone 4, Workstream A2: Training & Competency Management
            // -- real backend now (CompetencyType/EmployeeCompetency),
            // same route/controller reachable from both HR and HSE
            // conceptually, but following the same "one canonical home,
            // not duplicated" precedent as HSE KPI below -- lives here
            // since it's fundamentally employee master data.
            { name: 'Training & Competency', href: 'competency.master', icon: GraduationCap },
            // v1.10.4 correction: the existing KPI implementation
            // (kpi-input.index) was mapped here in v1.10.0, but its
            // routes are already gated to role:super_admin,hse at the
            // route level -- it was always HSE's module, just
            // mis-labeled/mis-placed in nav. Moved to HSE below; this
            // stays a locked placeholder ("KPI HRD" -- a genuinely
            // separate future concept, HR's own KPI tracking, not yet
            // built) rather than a real link.
        ],
    },
    {
        key: 'hse',
        // v2.52.0: the FULL English name. HSE / HSSE / QHSE / EHS all name
        // roughly this function and companies disagree about which is
        // correct; the full name is unambiguous in every one of them. The
        // abbreviation stays perfectly usable in context ("HSE approval",
        // "PTW"), and a tenant that wants its own wording still has the
        // per-tenant label override -- a DISPLAY layer, not a second
        // technical module.
        label: 'Health, Safety & Environment',
        icon: HardHat,
        tier: 'department',
        /**
         * v1.11.7 (Production Readiness Follow-Up, HSE Navigation
         * Finalization). Previously 18 flat items -- confirmed too many
         * for an operational HSE user to scan, per direct feedback.
         * Regrouped around WORKFLOW, not database entities, into the
         * AuthenticatedLayout `children` mechanism (collapsible groups,
         * localStorage-persisted expand state, auto-expands around the
         * active route -- see that component's own doc comment on
         * `containsActiveChild`). That mechanism already existed in the
         * sidebar component but was unused by every workspace before this
         * pass -- reused here, not built new.
         *
         * Every href below is UNCHANGED from the flat list -- this is a
         * pure regrouping, zero new routes, zero renamed routes, zero
         * RBAC change (`applyItemGates()` was fixed this same pass to
         * recurse into `children` so `HSE KPI`'s `adminOnly` and `PPE
         * Management`'s `moduleKey` gates keep working nested).
         *
         * Grouping rationale (benchmarked against HSE Omni Pro's own
         * nav -- see docs/MODULES.md's "HSE Omni Pro UX Benchmark"
         * section for the full comparison table):
         * - Safety Management: the day-to-day reporting/finding loop
         *   (Incident -> Observation -> Inspection -> TBM -> CAPA).
         * - Permit & Work Safety: everything gating whether risky work
         *   is allowed to start (PTW -> Gas Test -> LOTO) plus the two
         *   risk-assessment documents that usually precede a permit
         *   (JSA, HIRADC).
         * - People & PPE: everyone who can be on site and what they're
         *   wearing (PPE, Contractor, Visitor).
         * - HSE Control: configuration/reference + admin-only entries
         *   (Master Data, Document Control, HSE KPI).
         * "Waste Management" and the two disabled placeholders
         * (Training, Reports) were deliberately left top-level rather
         * than force-nested into a 1-item group -- a group containing
         * only itself adds a click with no scanning benefit, and
         * disabled items are not supported by the `children` renderer
         * (it has no disabled-child treatment), so nesting them would
         * have rendered as a broken Link, not a locked row.
         */
        // v2.68.0: this block used to point at resources/js/lib/id.js, the
        // v1.11.7 "standardize on Bahasa Indonesia" terminology map. That
        // policy was superseded by v2.53.0's language hierarchy (English
        // for navigation, feature names, headers, status and action
        // labels), and the file had been imported by nothing for many
        // releases -- its strings did not appear in the built bundle at
        // all. It has been deleted rather than left as a dictionary
        // someone could wire back up. These names are, and stay, English;
        // established acronyms (PPE, JSA, HIRADC, PTW, LOTO, CAPA, TBM,
        // KPI) are kept as-is. hrefs/route names UNCHANGED.
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true, globalDashboardOnly: true },
            { name: 'My Work', href: 'my-work', icon: ClipboardList, global: true, departmentUserOnly: true },
            { name: 'Overview', href: 'hse.dashboard', icon: LayoutDashboard },
            {
                name: 'Safety Management',
                icon: ShieldAlert,
                children: [
                    { name: 'Incident Management', href: 'incidents.index', icon: AlertTriangle },
                    // v2.73.0 -- a SEPARATE entry, because it is a separate
                    // workspace. An investigation is started deliberately by
                    // HSE, worked over days by named people, and answers a
                    // question the incident list cannot: what are we still
                    // investigating. Hiding it inside Incident Management is
                    // what kept it a form. See docs/ADR/036.
                    { name: 'HSE Investigation', href: 'investigations.index', icon: SearchCheck },
                    { name: 'Safety Observation', href: 'safety-observations.index', icon: Eye },
                    // v2.34.0 (Post-Deployment Product Gap pass, Part 6):
                    // was "Inspection" -- the exact same feature/route as
                    // Dashboard's own "Digital Checklist" quick action
                    // (DashboardController::$tiles), but the two labels
                    // never matched, so a user following the Dashboard
                    // tile had no obvious sidebar item to find it again.
                    // Relabeled to match; route/controller/model (still
                    // `HseInspection`) completely unchanged.
                    { name: 'Digital Checklist', href: 'hse-inspections.index', icon: ClipboardCheck },
                    { name: 'TBM', href: 'tbm-meetings.index', icon: UsersRound },
                    { name: 'CAPA', href: 'corrective-actions.index', icon: ClipboardCheck },
                ],
            },
            {
                name: 'Permit & Work Safety',
                icon: Flame,
                children: [
                    { name: 'PTW', href: 'permits-to-work.index', icon: Flame },
                    { name: 'Gas Test', href: 'gas-test-records.index', icon: FlaskConical },
                    { name: 'LOTO', href: 'loto-records.index', icon: Lock },
                    { name: 'JSA', href: 'job-safety-analyses.index', icon: FileWarning },
                    { name: 'HIRADC', href: 'risk-assessments.index', icon: ShieldAlert },
                    // v2.34.0 (Post-Deployment Product Gap pass, Part 4):
                    // PTW Access management (settings.users.ptw-access,
                    // gated `role:super_admin,hse` since v2.19.0) already
                    // existed but had NO sidebar entry point at all --
                    // only reachable by knowing to open Settings > Users
                    // and scroll to its own "Field & PTW Access" card.
                    // Deliberately NOT `adminOnly` (that flag gates on
                    // `isAdmin` alone, which would hide it from HSE --
                    // the second role this capability is explicitly for)
                    // -- visibility here matches its sibling PTW/LOTO/JSA
                    // items; the destination route itself already
                    // enforces the real `role:super_admin,hse` boundary,
                    // unchanged by this pass. No new capability, no
                    // authorization change -- a missing signpost to an
                    // existing one.
                    // v2.71.0: was "PTW Access", which named the permission
                    // rather than the thing an administrator is looking for.
                    // Someone hunting for where field workers' accounts are
                    // managed does not scan for a permission name -- and the
                    // card this opens is titled "Field & PTW Access", so the
                    // menu item and its destination now read as the same
                    // thing, which is the rule the rest of the product
                    // already follows. Route, queryParams and authorization
                    // are untouched.
                ],
            },
            {
                name: 'People & PPE',
                icon: UserCheck,
                children: [
                    // PPE's own Dashboard/Employee PPE/Master/Reports split
                    // lives in PpeTabNav *within* the module, not as
                    // further sidebar sub-items.
                    { name: 'PPE Management', href: 'ppe.dashboard', icon: HardHat, moduleKey: 'ppe' },
                    { name: 'Contractor Management', href: 'contractors.index', icon: UserCheck },
                    { name: 'Visitor Management', href: 'visitors.index', icon: FileCheck },
                ],
            },
            // v1.11.4, HSE Waste Management -- single entry point
            // (`waste.dashboard`); waste-records.*/waste-types.*/
            // waste-storage-locations.* stay reachable from within that
            // page rather than each getting their own sidebar row.
            // Left top-level (not grouped) -- see the class doc comment.
            { name: 'Waste Management', href: 'waste.dashboard', icon: Recycle },
            // v1.11.15 (SaaS Package + Ecosystem pass, Part 6/7): Man-Hour
            // is genuinely shared HR+HSE data (see User::canManageManHour(),
            // same pass) -- HSE needs it directly for safety-KPI input, not
            // only as a read-only number on its own Overview. Same route
            // HR's own sidebar already links to (`man-hour.index`), no
            // duplicate page/controller/table.
            { name: 'Man-Hour', href: 'man-hour.index', icon: Clock },
            // v2.71.0 -- HSE RAISES MATERIAL REQUESTS AND HAD NO WAY IN.
            //
            // `User::canManageMaterialRequests()` has been
            // `isSuperAdmin() || isHse()` since the module shipped, and
            // the sidebar entry existed only under Logistics / PPIC -- so
            // the department the capability was built for had no path to
            // it, and on a Starter or Professional plan (neither grants
            // `logistics`) the route was 403'd outright. Same route, same
            // controller, same permission as the Logistics entry: one
            // module with two doors, exactly like Man-Hour above.
            { name: 'Material Request', href: 'material-requests.index', icon: PackageSearch, moduleKey: 'material_requests' },
            {
                name: 'HSE Control',
                icon: ListChecks,
                children: [
                    // v2.34.0 (Post-Deployment Product Gap pass, Part 5):
                    // was "HSE Master Data" -- confirmed via audit that
                    // this route (Hse/Master.jsx) already contains the
                    // exact equipment/inventory registers the intended
                    // "HSE Inventory" concept describes (Safety Equipment
                    // Register -- APAR/HT/safety cones/etc. as
                    // configurable Equipment Types, P3K/First Aid
                    // Stations, HSE Materials & Consumables) alongside
                    // Hazard Categories and Checklist Templates -- it was
                    // never missing, only generically named. Relabeled to
                    // surface "Equipment & Inventory" explicitly; same
                    // route, same page, same data, nothing rebuilt.
                    // v2.42.0: renamed from "Equipment & Master Data", which read
                    // as the company's general equipment/inventory registry and so
                    // looked like operational Asset/Warehouse data misfiled under
                    // HSE. The content is genuinely HSE-owned -- safety-compliance
                    // equipment (APAR, P3K, HT, safety cones) whose inspection
                    // lifecycle is an HSE responsibility gated by canManageHse(),
                    // plus hazard categories and checklist templates. Moving it to
                    // Asset Management would have put it behind canManageAssets()
                    // and cut HSE off from its own inspection records, so the fix
                    // is the label, not the ownership. General operational
                    // equipment/inventory continues to live in Asset Management
                    // and Warehouse, untouched.
                    { name: 'Safety Equipment & Compliance', href: 'hse.master', icon: ListChecks },
                    // v2.52.0: the legal/normative requirements register every
                    // HSE management system asks for, which previously lived in
                    // someone's spreadsheet.
                    { name: 'Regulations & Standards', href: 'hse-regulations.index', icon: Scale },
                    { name: 'Document Control', href: 'controlled-documents.index', icon: FileStack },
                    // v1.10.4 correction: moved from HR -- same route,
                    // controller, permissions, moduleKey, only the owning
                    // sidebar changed.
                    { name: 'HSE KPI', href: 'kpi-input.index', icon: ClipboardEdit, adminOnly: true, moduleKey: 'kpi_input' },
                ],
            },
            // Training & Competency lives under HR -- same one-canonical-
            // home precedent as HSE KPI above (which moved the other way).
        ],
    },
    /*
     * v2.84.0 -- SEVEN WORKSPACES LEFT THIS FILE, AND NOTHING WAS DELETED.
     *
     * Project Management, Warehouse (as a standalone shell), Procurement,
     * Asset Management, Maintenance, Quality Control and Finance are no
     * longer CUSTOMER-FACING. IOMS sells four operational workspaces --
     * HSE, People / HRD, Logistics / Warehouse and Management -- and a
     * product that lists eleven departments, most of them unreachable on
     * every plan it sells, reads as unfinished rather than focused.
     *
     * WHAT WAS ACTUALLY REMOVED: navigation entries. Their routes,
     * controllers, models, migrations and data are untouched, and
     * `config/departments.php` still maps every one of their route
     * prefixes to its owning department -- so RestrictDepartmentAccess and
     * EnforceTenantEntitlement gate them exactly as before. What changed is
     * that no plan grants them any more (config/plans.php), so the
     * entitlement middleware now refuses them for any provisioned tenant.
     *
     * They are roadmap, recorded in the knowledge base rather than
     * advertised as "Coming Soon" in a sidebar. Warehouse is the one that
     * did not retire: its real capability (Item Master, Inventory, Goods
     * Receipt, Stock Movement) was always inside Logistics, and the tier is
     * now sold and labelled as the one domain it always was.
     *
     * Reinstating one is this comment plus its entry, a config/plans.php
     * line, and a workspaces catalogue row. See ADR 045.
     */
    {
        key: 'logistics',
        // v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- "PPIC"
        // (Production Planning & Inventory Control) is itself already a
        // standard Indonesian-industry acronym, kept as-is.
        label: 'Warehouse Logistics',
        icon: PackageSearch,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true, globalDashboardOnly: true },
            { name: 'My Work', href: 'my-work', icon: ClipboardList, global: true, departmentUserOnly: true },
            { name: 'Overview', href: 'logistics.dashboard', icon: LayoutDashboard },
            { name: 'Material Request', href: 'material-requests.index', icon: PackageSearch, moduleKey: 'material_requests' },
            // Warehouse stays inside Logistics for now, per explicit
            // instruction -- not split into its own department yet, even
            // though a separate (still-disabled) "Warehouse" department
            // also exists below for future use.
            // Milestone 4, Acceleration Part 1B: real backend now
            // (Warehouse/StorageLocation/Stock/StockMovement).
            { name: 'Warehouse', href: 'warehouses.master', icon: Warehouse },
            { name: 'Item Master', href: 'items.index', icon: Box },
            { name: 'Inventory', href: 'stock.index', icon: Boxes },
            { name: 'Goods Receipt', href: 'goods-receipts.index', icon: PackageCheck },
            { name: 'Stock Out / Transfer / Adjustment', href: 'stock.transactions.create', icon: ArrowRightLeft },
            { name: 'Stock Movement History', href: 'stock.movements', icon: ClipboardList },
        ],
    },
    {
        key: 'management',
        label: 'Management',
        icon: TrendingUp,
        tier: 'department',
        items: [
            { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard, global: true, globalDashboardOnly: true },
            { name: 'My Work', href: 'my-work', icon: ClipboardList, global: true, departmentUserOnly: true },
            { name: 'Overview', href: 'management.overview', icon: TrendingUp },
            { name: 'Company KPI', href: 'management.kpi', icon: BarChart3 },
            { name: 'HSE Performance', href: 'management.hse', icon: HardHat },
            { name: 'Workforce', href: 'management.workforce', icon: UsersRound },
            { name: 'Logistics & Inventory', href: 'management.logistics', icon: PackageSearch },
            { name: 'Outstanding Actions', href: 'management.actions', icon: ListChecks },
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
        /*
         * v2.83.0 -- "ADMIN SPACE", and the key deliberately did not move.
         *
         * Every grant row, route-prefix map and entitlement check is keyed
         * on `administration`; renaming the key would be a migration of the
         * authorization model dressed up as a copy edit -- the same reason
         * `hr` is still `hr` while its label reads "Human Resources"
         * (v2.52.0).
         *
         * The label changed because the content did. This is no longer a
         * settings drawer: it is the customer's own administration space --
         * their users, roles, operating units, audit trail and
         * subscription, gathered so that an HSE supervisor never has to
         * scroll past billing to reach a permit. It is NOT Master Admin,
         * which belongs to the IOMS operator and lives at /platform behind
         * a completely separate permission (ADR 044).
         */
        label: 'Admin Space',
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
            /*
             * v2.83.0 -- ORDERED THE WAY ADMINISTRATION IS ACTUALLY DONE:
             * overview, then people, then the organization, then security,
             * then the commercial arrangement.
             *
             * `tenantAdminOnly` is a THIRD gate beside adminOnly/moduleKey,
             * and it exists because `adminOnly` means `is_admin` -- Super
             * Admin OR HSE. HSE has managed Departments and Positions from
             * Settings since v1.x and must keep doing so, but capacity,
             * billing and every account's security posture are the
             * administrator's business. The two audiences were previously
             * indistinguishable in this file, so the narrower rows say so
             * explicitly. Both gates are courtesies: each route behind them
             * re-checks server-side.
             */
            { name: 'Overview', href: 'admin.index', icon: LayoutDashboard, tenantAdminOnly: true },
            { name: 'Users & Access', href: 'settings.index', queryParams: { tab: 'users' }, icon: Users, adminOnly: true },
            { name: 'Roles & Permissions', href: 'settings.index', queryParams: { tab: 'roles' }, icon: Lock, tenantAdminOnly: true },
            { name: 'Departments', href: 'settings.index', queryParams: { tab: 'departments' }, icon: Users, adminOnly: true },
            { name: 'Positions', href: 'settings.index', queryParams: { tab: 'positions' }, icon: Users, adminOnly: true },
            { name: 'Operating Units', href: 'settings.index', queryParams: { tab: 'companies' }, icon: Building2, adminOnly: true },
            // Milestone 3 (Task #50): was a disabled placeholder since
            // v1.9.0 -- now a real link to the Activity Center.
            { name: 'Audit Logs', href: 'activity-center.index', icon: ClipboardList, adminOnly: true },
            /*
             * The tenant's own billing page, which had no sidebar entry at
             * all before this release -- it was reachable only from the
             * Settings subscription panel, which is exactly the kind of
             * administrative concern Admin Space exists to collect. Same
             * route, same controller, same authorization.
             */
            { name: 'Subscription & Billing', href: 'subscription.billing', icon: DollarSign, tenantAdminOnly: true },
            { name: 'Settings', href: 'settings.index', icon: Settings, adminOnly: true },
            { name: 'Module Management', href: 'settings.index', queryParams: { tab: 'modules' }, icon: Settings, adminOnly: true },
        ],
    },
];

/**
 * FUTURE WORKSPACES -- roadmap only, and deliberately invisible.
 *
 * Marine Operations, Document Control, Visitor Management, Contractor
 * Management, and the seven that retired in v2.84.0. None of them appears
 * anywhere a customer can see: not in the sidebar, not in the switcher, not
 * on a pricing card, and never as "Coming Soon". They live in the knowledge
 * base, which is where a roadmap belongs.
 */

/**
 * v2.84.0 -- THE COMPANY CHROME, SHARED BY EVERY WORKSPACE.
 *
 * Reports, Analytics and Report Center are cross-module surfaces. They are
 * not a department and no plan may withhold them (Workspace::globalKeys()),
 * but they still have to be REACHABLE -- and after v2.84.0 a Starter or
 * Professional customer never sees a "no workspace active" sidebar, because
 * they never get the Global Company Dashboard. Left where they were, the
 * three pages would have become unreachable for the two plans that sell the
 * most focused product.
 *
 * So they are appended to every operational workspace as one collapsible
 * group, and shown again on the Global Dashboard's own company nav. Each
 * item carries `global: true`, which keeps `registerPrefixes()` from giving
 * four different workspaces joint ownership of the `reports` prefix -- the
 * `reports` entry in WORKSPACES stays the single owner, exactly as before.
 */
const COMPANY_REPORTS_GROUP = {
    name: 'Reports & Analytics',
    icon: FileBarChart,
    children: [
        { name: 'Reports', href: 'reports.index', icon: FileBarChart, moduleKey: 'reports', global: true },
        { name: 'Analytics', href: 'analytics.index', icon: BarChart3, global: true },
        { name: 'Report Center', href: 'report-center.index', icon: FileDown, global: true },
    ],
};

/** The two workspace keys that are the application's own chrome, never sold. */
const GLOBAL_NAV_KEYS = ['reports', 'administration'];

/** Admin Space is a SPACE, not a department -- it is never in the switcher. */
export const ADMIN_SPACE_KEY = 'administration';

function isDepartmentTier(workspace) {
    return workspace.tier === 'department';
}

/**
 * Applies the same two-gate filter every item already had (adminOnly
 * against `isAdmin`, moduleKey against the enabled-modules list).
 *
 * v1.11.7 (HSE Navigation Finalization): now recurses into `item.children`
 * too. Before this, an `adminOnly`/`moduleKey`-gated item nested inside a
 * collapsible group would never be filtered at all -- this function only
 * ever inspected the flat top-level array, and the sidebar's own
 * `children` rendering (AuthenticatedLayout.jsx) applies no gating of its
 * own. Not a live bug yet (no workspace used `children` before this
 * pass), but would have become one the moment HSE's own `HSE KPI`
 * (adminOnly) was grouped -- fixed here instead of narrowly working
 * around it in one workspace.
 */
function applyItemGates(items, isAdmin, modules, isDepartmentUser = false, isTenantAdmin = false, hasGlobalDashboard = true) {
    return items
        .filter((item) =>
            (!item.adminOnly || isAdmin)
            /* v2.84.0 fifth gate. Every workspace repeats a link back to the
               GLOBAL COMPANY DASHBOARD, which only Business has. On any other
               plan `/dashboard` redirects to the workspace the user is
               already in, so the link was a loop sitting at the top of every
               rail -- browser-verified on a Starter tenant. */
            && (!item.globalDashboardOnly || hasGlobalDashboard)
            // v2.83.0 fourth gate -- see Admin Space's items for why
            // `adminOnly` (Super Admin OR HSE) was too wide for capacity,
            // billing and security rows.
            && (!item.tenantAdminOnly || isTenantAdmin)
            && (!item.moduleKey || modules.includes(item.moduleKey))
            // v2.42.0 third gate. 'My Work' (Field Home) is the task-first
            // page Department Users used to be silently given INSTEAD of the
            // Dashboard; it is only meaningful for that audience, so an
            // administrator browsing the same workspace does not see it.
            && (!item.departmentUserOnly || isDepartmentUser)
        )
        .map((item) => (item.children ? { ...item, children: applyItemGates(item.children, isAdmin, modules, isDepartmentUser, isTenantAdmin, hasGlobalDashboard) } : item))
        // A group whose every child got gated out (no combination does
        // this today -- every HSE group keeps at least one ungated child
        // -- but defensive against a future edit that adds one) renders
        // nothing useful; drop it rather than show an empty expandable row.
        .filter((item) => !item.children || item.children.length > 0);
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
    AlertTriangle, PackageCheck, Flag, BarChart3, FileDown, ClipboardCheck,
    // v2.83.0 -- Management's own icon. A workspace whose `workspaces` row
    // names an icon missing from this map silently falls back to the
    // hardcoded one, so omitting it would not have crashed -- it would
    // have quietly ignored the catalogue, which is worse.
    TrendingUp,
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
    const isDepartmentUser = Boolean(user?.department_key);
    const isTenantAdmin = Boolean(user?.is_tenant_admin);

    /*
     * v2.84.0 -- ONE ENTITLEMENT ANSWER, FOR EVERY WORKSPACE ALIKE.
     *
     * `workspace_access` is the server's own map (EntitlementService::
     * userCanUseWorkspace: plan grant AND department assignment), so the
     * sidebar cannot offer a door the route would close. It replaces
     * v2.83.0's `can_view_management` -- a per-workspace boolean for
     * exactly ONE workspace, which is how Management ended up asking a
     * different question from Logistics and returning 403 to accounts the
     * customer had paid for.
     *
     * Absent (an older cached page, or a caller that passes no user) means
     * "do not filter", so this can never hide a workspace because a prop
     * failed to arrive. The route gate is the boundary either way.
     */
    const access = user?.workspace_access;
    // Absent means "do not filter", for the same reason as `access` above.
    const hasGlobalDashboard = user?.has_global_dashboard !== false;

    return applyCatalog(WORKSPACES, workspaceCatalog)
        .map((workspace) => ({ ...workspace, items: applyItemGates(workspace.items, isAdmin, modules, isDepartmentUser, isTenantAdmin, hasGlobalDashboard) }))
        .filter((workspace) => workspace.items.length > 0)
        .filter((workspace) => ! isDepartmentTier(workspace) || ! access || access[workspace.key] === true)
        // Every operational workspace carries the company chrome. Admin
        // Space deliberately does NOT -- reporting is not administration,
        // and mixing the two is what made the old Admin Space read as one
        // giant application dashboard.
        .map((workspace) => (isDepartmentTier(workspace)
            ? { ...workspace, items: [...workspace.items, withGatedChildren(COMPANY_REPORTS_GROUP, isAdmin, modules, isDepartmentUser, isTenantAdmin, hasGlobalDashboard)] }
            : workspace))
        /*
         * v2.83.0 -- Management is a CAPABILITY as well as an entitlement.
         *
         * The workspace catalogue already hides a workspace the tenant's
         * PLAN does not grant (`applyCatalog`, driven by
         * EntitlementService::grantedWorkspaceKeys). It says nothing about
         * whether this PERSON is management, and an HSE supervisor in a
         * Business tenant is not -- ManagementController 403s them. Hiding
         * the entry is the courtesy half of that; the controller is the
         * boundary.
         */
        .filter((workspace) => workspace.items.length > 0);
}

/** Applies the same item gates to a shared group before it is appended. */
function withGatedChildren(group, isAdmin, modules, isDepartmentUser, isTenantAdmin, hasGlobalDashboard) {
    return { ...group, children: applyItemGates(group.children, isAdmin, modules, isDepartmentUser, isTenantAdmin, hasGlobalDashboard) };
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

/**
 * v2.84.0 -- ADMIN SPACE'S OWN NAVIGATION, AND NOTHING ELSE.
 *
 * The defect this replaces: the sidebar's old "global navigation" state
 * merged Reports + Administration into one list, so entering Admin Space
 * showed Reports, Analytics and Report Center alongside Users, Roles and
 * Billing -- administration and cross-module reporting presented as one
 * undifferentiated application dashboard. They are different things for
 * different people. Reporting now belongs to the operational workspaces
 * (COMPANY_REPORTS_GROUP); this returns administration only.
 */
export function getAdminSpaceItems(user, enabledModules, workspaceCatalog) {
    return getVisibleWorkspaces(user, enabledModules, workspaceCatalog)
        .filter((workspace) => workspace.key === ADMIN_SPACE_KEY)
        .flatMap((workspace) => workspace.items);
}

/**
 * The GLOBAL COMPANY DASHBOARD's own navigation -- the only state in which
 * no workspace is active and that is correct rather than a fallback.
 *
 * Business only, because the dashboard is. Deliberately SHORT: the company
 * command centre links to the workspaces, it does not reproduce them.
 */
export function getCompanyNavItems(user, enabledModules, workspaceCatalog) {
    const reporting = getVisibleWorkspaces(user, enabledModules, workspaceCatalog)
        .filter((workspace) => workspace.key === 'reports')
        .flatMap((workspace) => workspace.items);

    return [
        { name: 'Dashboard', href: 'dashboard', icon: LayoutDashboard },
        { name: 'Calendar', href: 'calendar.index', icon: CalendarDays },
        { name: 'Work Center', href: 'work-center.index', icon: ClipboardCheck },
        ...reporting,
    ];
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
// v1.11.7: recurses into `item.children` too. Before this pass no
// workspace used `children` so the flat-only loop was never wrong, but
// HSE's new grouped items (incidents.index, safety-observations.index,
// etc.) would otherwise silently stop mapping to the 'hse' workspace key
// the moment they moved into a group -- breaking active-workspace
// detection (department switcher, sidebar highlighting, breadcrumb) for
// every regrouped route. Fixed here rather than only for HSE, so any
// future workspace that adopts `children` gets this for free.
/**
 * v2.71.0: records EVERY owner of a prefix, not just the last one to
 * declare it. A genuinely shared capability appears in more than one
 * department's menu on purpose -- Man-Hour is shared HR/HSE data, and
 * Material Request is raised by every department -- and collapsing that
 * to one winner meant the sidebar jumped to whichever workspace happened
 * to be declared last in this file. Single-owner prefixes, which is
 * almost all of them, behave exactly as before.
 */
function registerPrefixes(map, items, workspaceKey) {
    for (const item of items) {
        if (item.children) {
            registerPrefixes(map, item.children, workspaceKey);
            continue;
        }
        if (!item.href || item.global) continue;

        const prefix = item.href.split('.')[0];
        const owners = map[prefix] ?? (map[prefix] = []);

        // The same prefix can appear several times within ONE workspace
        // (Administration declares `settings.index` repeatedly with
        // different queryParams); that is not shared ownership.
        if (!owners.includes(workspaceKey)) owners.push(workspaceKey);
    }
}

const PREFIX_TO_WORKSPACES = WORKSPACES.reduce((map, workspace) => {
    registerPrefixes(map, workspace.items, workspace.key);
    return map;
}, {});

/**
 * Reverse lookup: given a route name (e.g. 'ppe.employees'), which
 * workspace owns it. Returns null for the Global Dashboard itself
 * (`dashboard` route) and for any route with no owning workspace at all
 * -- both correctly mean "no department is active," which is exactly
 * when the sidebar should fall back to Global navigation.
 *
 * `preferredKey` only ever matters for a route owned by more than one
 * department, where it resolves the tie in favour of the workspace the
 * user is already working in. Passing it can never move a single-owner
 * route to a department that does not own it.
 */
export function getWorkspaceKeyForRoute(routeName, preferredKey = null) {
    if (!routeName) return null;

    const owners = PREFIX_TO_WORKSPACES[routeName.split('.')[0]];
    if (!owners?.length) return null;

    return preferredKey && owners.includes(preferredKey) ? preferredKey : owners[0];
}

/** Whether a resolved workspace key belongs to a department (as opposed to 'reports'/'administration' or no match at all). */
export function isDepartmentWorkspaceKey(key) {
    return WORKSPACES.some((w) => w.key === key && isDepartmentTier(w));
}
