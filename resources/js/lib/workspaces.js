import {
    Users, ClipboardEdit, FileBarChart, Settings, FolderKanban, HardHat,
    ClipboardList, PackageSearch, Warehouse, ShoppingCart, Wrench,
    BadgeCheck, DollarSign, Box, LayoutDashboard, CalendarDays,
    AlertTriangle, PackageCheck, Flag, BarChart3, FileDown, GraduationCap, Clock, Eye, ListChecks,
    ShieldAlert, FileWarning, Flame, Lock, UsersRound, Stethoscope, Siren, ClipboardCheck,
    FileStack, FileQuestion, Building2, TrendingUp, Boxes, ArrowRightLeft, UserCheck, FileCheck,
    FlaskConical, Recycle,
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
        // v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- "HRD" is
        // the term Indonesian companies actually use for this department
        // (not a forced translation of "Human Resources"), matching the
        // terminology map in resources/js/lib/id.js. Every item name
        // below is user-facing text only -- hrefs/route names UNCHANGED.
        label: 'HRD',
        icon: Users,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Ringkasan', href: 'hr.dashboard', icon: LayoutDashboard },
            { name: 'Karyawan', href: 'employees.index', icon: Users, moduleKey: 'employees' },
            // v1.11.6 (Production Readiness pass, Part 4): "Attendance"
            // was a disabled placeholder with no backing data. Man-Hour
            // is the closest real equivalent this pass actually built
            // (regular/overtime hours per employee per date) -- replaces
            // the placeholder rather than sitting alongside it as a
            // second, confusing "almost the same thing" entry.
            { name: 'Man-Hour', href: 'man-hour.index', icon: ClipboardList },
            { name: 'Cuti', href: 'leave-requests.index', icon: CalendarDays },
            // Milestone 4, Workstream A3: Shift & Roster Management --
            // real backend (Shift/EmployeeShiftAssignment/RosterPattern/
            // EmployeeRoster). "Shift/Roster belongs to HR/Workforce
            // Management" per spec -- other modules (HSE fatigue checks,
            // Project manpower) may consume this data later without it
            // moving out of HR.
            { name: 'Shift & Jadwal Kerja', href: 'shifts.master', icon: Clock },
            { name: 'Rekrutmen', icon: Users, disabled: true },
            { name: 'Kinerja', icon: BadgeCheck, disabled: true },
            // Milestone 4, Workstream A2: Training & Competency Management
            // -- real backend now (CompetencyType/EmployeeCompetency),
            // same route/controller reachable from both HR and HSE
            // conceptually, but following the same "one canonical home,
            // not duplicated" precedent as HSE KPI below -- lives here
            // since it's fundamentally employee master data.
            { name: 'Pelatihan & Kompetensi', href: 'competency.master', icon: GraduationCap },
            // v1.10.4 correction: the existing KPI implementation
            // (kpi-input.index) was mapped here in v1.10.0, but its
            // routes are already gated to role:super_admin,hse at the
            // route level -- it was always HSE's module, just
            // mis-labeled/mis-placed in nav. Moved to HSE below; this
            // stays a locked placeholder ("KPI HRD" -- a genuinely
            // separate future concept, HR's own KPI tracking, not yet
            // built) rather than a real link.
            { name: 'KPI HRD', icon: ClipboardEdit, disabled: true },
            { name: 'Dokumen', icon: ClipboardList, disabled: true },
            { name: 'Laporan', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'hse',
        label: 'HSE',
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
        // v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- every item
        // name below translated per resources/js/lib/id.js's terminology
        // map; hrefs/route names UNCHANGED. Established acronyms (PPE,
        // JSA, HIRADC, PTW, LOTO, CAPA, TBM, KPI) kept as-is rather than
        // forced into unnatural Indonesian, matching that file's own rules.
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Ringkasan', href: 'hse.dashboard', icon: LayoutDashboard },
            {
                name: 'Manajemen Keselamatan',
                icon: ShieldAlert,
                children: [
                    { name: 'Manajemen Insiden', href: 'incidents.index', icon: AlertTriangle },
                    { name: 'Observasi Keselamatan', href: 'safety-observations.index', icon: Eye },
                    { name: 'Inspeksi', href: 'hse-inspections.index', icon: ClipboardCheck },
                    { name: 'Rapat Keselamatan (TBM)', href: 'tbm-meetings.index', icon: UsersRound },
                    { name: 'Tindakan Perbaikan (CAPA)', href: 'corrective-actions.index', icon: ClipboardCheck },
                ],
            },
            {
                name: 'Izin & Keselamatan Kerja',
                icon: Flame,
                children: [
                    { name: 'Izin Kerja (PTW)', href: 'permits-to-work.index', icon: Flame },
                    { name: 'Uji Gas', href: 'gas-test-records.index', icon: FlaskConical },
                    { name: 'LOTO', href: 'loto-records.index', icon: Lock },
                    { name: 'JSA', href: 'job-safety-analyses.index', icon: FileWarning },
                    { name: 'HIRADC / Penilaian Risiko', href: 'risk-assessments.index', icon: ShieldAlert },
                ],
            },
            {
                name: 'Personel & APD',
                icon: UserCheck,
                children: [
                    // PPE's own Dashboard/Employee PPE/Master/Reports split
                    // lives in PpeTabNav *within* the module, not as
                    // further sidebar sub-items.
                    { name: 'Manajemen APD', href: 'ppe.dashboard', icon: HardHat, moduleKey: 'ppe' },
                    { name: 'Manajemen Kontraktor', href: 'contractors.index', icon: UserCheck },
                    { name: 'Manajemen Pengunjung', href: 'visitors.index', icon: FileCheck },
                ],
            },
            // v1.11.4, HSE Waste Management -- single entry point
            // (`waste.dashboard`); waste-records.*/waste-types.*/
            // waste-storage-locations.* stay reachable from within that
            // page rather than each getting their own sidebar row.
            // Left top-level (not grouped) -- see the class doc comment.
            { name: 'Pengelolaan Limbah', href: 'waste.dashboard', icon: Recycle },
            {
                name: 'Kontrol HSE',
                icon: ListChecks,
                children: [
                    { name: 'Data Master HSE', href: 'hse.master', icon: ListChecks },
                    { name: 'Kontrol Dokumen', href: 'controlled-documents.index', icon: FileStack },
                    // v1.10.4 correction: moved from HR -- same route,
                    // controller, permissions, moduleKey, only the owning
                    // sidebar changed.
                    { name: 'KPI HSE', href: 'kpi-input.index', icon: ClipboardEdit, adminOnly: true, moduleKey: 'kpi_input' },
                ],
            },
            // Training & Competency lives under HR -- same one-canonical-
            // home precedent as HSE KPI above (which moved the other way).
            { name: 'Pelatihan', icon: ClipboardList, disabled: true },
            { name: 'Laporan', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'project-management',
        // v1.11.7 (Bahasa Indonesia Standardization, Part 4).
        label: 'Manajemen Proyek',
        icon: FolderKanban,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Ringkasan', href: 'project-management.dashboard', icon: LayoutDashboard },
            { name: 'Proyek', href: 'projects.index', icon: FolderKanban, moduleKey: 'projects' },
            // Daily Reports lives here, not HSE: it's a per-project
            // activity/progress log -- see ADR-007 for the reasoning.
            { name: 'Laporan Harian', href: 'daily-reports.index', icon: ClipboardList, moduleKey: 'daily_reports' },
            // Milestone 4, Acceleration Part 3: real backend now
            // (ProjectActivity) -- distinct from a DailyReportActivity
            // free-text log line: this is a real owner+progress+status
            // record, feeding the Avg. Activity Progress dashboard widget.
            // Left disabled here (not a dead route -- `projects.activities`
            // requires a {project} param, so it's reached from within a
            // Project's own page, not as a standalone sidebar destination;
            // same reasoning as Attendance/Training above).
            { name: 'Aktivitas', icon: ClipboardList, disabled: true },
            { name: 'Milestone', href: 'milestones.index', icon: Flag },
            { name: 'Dokumen', icon: ClipboardList, disabled: true },
            { name: 'Laporan', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'logistics',
        // v1.11.7 (Bahasa Indonesia Standardization, Part 4) -- "PPIC"
        // (Production Planning & Inventory Control) is itself already a
        // standard Indonesian-industry acronym, kept as-is.
        label: 'Logistik / PPIC',
        icon: PackageSearch,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Ringkasan', href: 'logistics.dashboard', icon: LayoutDashboard },
            { name: 'Permintaan Material', href: 'material-requests.index', icon: PackageSearch, moduleKey: 'material_requests' },
            // Warehouse stays inside Logistics for now, per explicit
            // instruction -- not split into its own department yet, even
            // though a separate (still-disabled) "Warehouse" department
            // also exists below for future use.
            // Milestone 4, Acceleration Part 1B: real backend now
            // (Warehouse/StorageLocation/Stock/StockMovement).
            { name: 'Gudang', href: 'warehouses.master', icon: Warehouse },
            { name: 'Data Master Barang', href: 'items.index', icon: Box },
            { name: 'Inventaris', href: 'stock.index', icon: Boxes },
            { name: 'Penerimaan Barang', href: 'goods-receipts.index', icon: PackageCheck },
            { name: 'Keluar / Transfer / Penyesuaian Stok', href: 'stock.transactions.create', icon: ArrowRightLeft },
            { name: 'Riwayat Pergerakan Stok', href: 'stock.movements', icon: ClipboardList },
            { name: 'Dokumen', icon: ClipboardList, disabled: true },
            { name: 'Laporan', icon: FileBarChart, disabled: true },
        ],
    },
    // v1.10.6 correction, updated v1.11.3.2 (Priority Pass Part 9):
    // 'warehouse' is deliberately NOT a "nothing built yet" placeholder
    // like Finance -- Warehouse functionality is fully real (Stock/
    // StockMovement/GoodsReceipt/Item), it just stays inside the
    // Logistics / PPIC department's own RBAC group for now (explicit
    // earlier instruction: "Warehouse stays inside Logistics, not split
    // into its own department key yet" -- `config/departments.php` still
    // maps every `warehouses.*`/`items.*`/`stock.*`/`goods-receipts.*`
    // prefix to `logistics`, unchanged). This workspace's item list
    // deliberately stays to JUST its one entry point (Overview, now
    // pointing at the real `warehouses.dashboard` instead of the
    // warehouse register/config page) rather than re-listing Logistics's
    // own Item Master/Inventory/Goods Receipt/etc. items a second time --
    // `PREFIX_TO_WORKSPACE` below requires each route prefix to be owned
    // by exactly ONE workspace (its own doc comment, a real invariant,
    // not decorative -- duplicating those prefixes here was tried and
    // reverted in this same pass once it was noticed it would silently
    // steal active-workspace highlighting away from Logistics/PPIC for
    // its own users). Warehouse's Overview page itself still surfaces
    // ModuleCard shortcuts to all of those pages -- the navigation depth
    // just isn't duplicated in the sidebar item list too.
    //
    // Note (pre-existing, not introduced by this pass): Logistics's own
    // item list also links `warehouses.master` under the same
    // `warehouses` prefix this workspace's Overview now shares via
    // `warehouses.dashboard`. Since this workspace is declared AFTER
    // Logistics in WORKSPACES, PREFIX_TO_WORKSPACE's reduce means THIS
    // workspace wins the active-highlight for any `warehouses.*` route --
    // that was already true before this pass (Logistics's own "Warehouse"
    // item already lost that contest to this workspace's prior
    // `warehouses.master` Overview link). Purely a which-sidebar-item-
    // highlights-as-active cosmetic detail, decided by array order here,
    // NOT a security/RBAC concern -- that's governed entirely by
    // config/departments.php, unrelated to this file.
    {
        key: 'warehouse',
        // v1.11.7 (Bahasa Indonesia Standardization, Part 4).
        label: 'Gudang',
        icon: Warehouse,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            { name: 'Ringkasan', href: 'warehouses.dashboard', icon: Warehouse },
        ],
    },
    {
        key: 'procurement',
        label: 'Procurement',
        icon: ShoppingCart,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            // Milestone 4, Workstream C: real backend now (Vendor,
            // PurchaseRequisition, Rfq/VendorQuotation, PurchaseOrder) --
            // a genuine cross-department procurement engine, not owned by
            // any single requesting department (HSE/Maintenance/Project/
            // etc. all raise Material Requests that Procurement can turn
            // into a PR from here).
            { name: 'Overview', href: 'procurement.dashboard', icon: LayoutDashboard },
            { name: 'Purchase Requisition', href: 'purchase-requisitions.index', icon: FileStack },
            { name: 'RFQ', href: 'rfqs.index', icon: FileQuestion },
            { name: 'Purchase Order', href: 'purchase-orders.index', icon: ShoppingCart },
            { name: 'Vendor / Supplier', href: 'vendors.index', icon: Building2 },
            { name: 'Vendor Performance', href: 'procurement.vendor-performance', icon: TrendingUp },
        ],
    },
    {
        key: 'asset-management',
        label: 'Asset Management',
        icon: Box,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            // v1.11.3 (Global Dashboard/Overview UX Rework, Part 4) -- this
            // department had no Overview at all before this pass.
            { name: 'Overview', href: 'asset-management.dashboard', icon: LayoutDashboard },
            // Milestone 4, Acceleration Part 1C: real backend now (Asset +
            // AssetTransaction) -- full Purchase->Receive->Register->
            // Assign->Operate->Inspect->Maintain->Retire lifecycle.
            { name: 'Assets', href: 'assets.index', icon: Box },
            { name: 'Documents', icon: ClipboardList, disabled: true },
            { name: 'Reports', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'maintenance',
        label: 'Maintenance',
        icon: Wrench,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            // v1.11.3 (Global Dashboard/Overview UX Rework, Part 4) -- this
            // department had no Overview at all before this pass.
            { name: 'Overview', href: 'maintenance.dashboard', icon: LayoutDashboard },
            // Milestone 4, Acceleration Part 2: real backend now
            // (MaintenanceRequest + WorkOrder). Request -> Approved ->
            // Work Order -> Execution -> Completed, spare parts posted via
            // the SAME StockService the Warehouse module itself uses.
            { name: 'Maintenance Requests', href: 'maintenance-requests.index', icon: ClipboardList },
            { name: 'Work Orders', href: 'work-orders.index', icon: Wrench },
            { name: 'Documents', icon: ClipboardList, disabled: true },
            { name: 'Reports', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'quality-control',
        label: 'Quality Control',
        icon: BadgeCheck,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
            // v1.11.3 (Global Dashboard/Overview UX Rework, Part 4) -- this
            // department had no Overview at all before this pass.
            { name: 'Overview', href: 'quality-control.dashboard', icon: LayoutDashboard },
            // Milestone 4, Acceleration Part 3: real backend now
            // (InspectionRequest + InspectionResult + Ncr). NCR raises a
            // real CorrectiveAction (reused, not duplicated -- same
            // polymorphic CAPA pattern as HSE's own findings).
            { name: 'Inspection Requests', href: 'inspection-requests.index', icon: ClipboardCheck },
            { name: 'NCR', href: 'ncrs.index', icon: FileWarning },
            { name: 'Documents', icon: ClipboardList, disabled: true },
            { name: 'Reports', icon: FileBarChart, disabled: true },
        ],
    },
    {
        key: 'finance',
        label: 'Finance',
        icon: DollarSign,
        tier: 'department',
        items: [
            { name: 'Dasbor', href: 'dashboard', icon: LayoutDashboard, global: true },
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
function applyItemGates(items, isAdmin, modules) {
    return items
        .filter((item) =>
            (!item.adminOnly || isAdmin) && (!item.moduleKey || modules.includes(item.moduleKey))
        )
        .map((item) => (item.children ? { ...item, children: applyItemGates(item.children, isAdmin, modules) } : item))
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
// v1.11.7: recurses into `item.children` too. Before this pass no
// workspace used `children` so the flat-only loop was never wrong, but
// HSE's new grouped items (incidents.index, safety-observations.index,
// etc.) would otherwise silently stop mapping to the 'hse' workspace key
// the moment they moved into a group -- breaking active-workspace
// detection (department switcher, sidebar highlighting, breadcrumb) for
// every regrouped route. Fixed here rather than only for HSE, so any
// future workspace that adopts `children` gets this for free.
function registerPrefixes(map, items, workspaceKey) {
    for (const item of items) {
        if (item.children) {
            registerPrefixes(map, item.children, workspaceKey);
            continue;
        }
        if (!item.href || item.global) continue;
        map[item.href.split('.')[0]] = workspaceKey;
    }
}

const PREFIX_TO_WORKSPACE = WORKSPACES.reduce((map, workspace) => {
    registerPrefixes(map, workspace.items, workspace.key);
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
