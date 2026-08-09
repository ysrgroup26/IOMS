# Modules

Per-module reference. For patterns shared *across* modules (the reusable engines, company-scoping,
authorization), see `ARCHITECTURE.md` — this document only covers what's specific to each module.

Each module below lives in exactly one navigation department (called "Workspace" internally in the
code -- `resources/js/lib/workspaces.js` -- but labeled "Department" in the UI as of v1.8.0; see
`ADR/007-workspace-navigation.md`) — noted at the start of its section as **Department: ...**.

## Employees

**Department:** Human Resources.

Core master data: `Employee` model, one per person. Belongs to a `Company`, optionally a
`Department` and `Position` (both nullable — see "profile completion" below).

- **Import**: `EmployeesImport` + `EmployeeImportTemplateExport`, with a Preview step (Smart Master
  Data Detection — see `ARCHITECTURE.md`) before committing anything. Critical fields: Employee ID,
  Full Name (missing either skips the row; duplicate Employee ID also skips). Department is
  tracked but not required — "Unassigned" is a real, supported state. Optional fields (photo,
  phone, email, address, emergency contact) never block a row. Employment Status is read from the
  file and validated against the real enum (`active`/`inactive`/`resigned`), falling back to
  `active` for blank/unrecognized values rather than failing the row. Project (optional) creates a
  `ProjectManpower` assignment if the name matches an existing project in the company.
- **Profile completion**: `Employee::profile_status` is a *computed accessor*
  (`needs_completion` if `department_id` is null, `complete` otherwise), not a stored column —
  fully derivable from existing data, so it can never drift out of sync. `employee_id`/`full_name`
  are enforced `NOT NULL` at the database level, so an already-saved record can never be missing
  either — completion status only ever concerns the department.
- **Export**: `EmployeeExport`, filterable by department/search/company.
- Dashboard surfaces an "Employee Profiles Need Completion" task card (only rendered when the count
  is actually nonzero), linking to the Employees list pre-filtered.
- **Workforce classification** (Milestone 4, Workstream A): `employment_type` — one of
  `pkwtt`/`pkwt`/`daily`/`intern`/`pkl`/`contractor`/`outsource` (`Employee::EMPLOYMENT_TYPES`),
  defaults to `pkwtt` for every pre-existing row. `contract_start_date`/`contract_end_date` apply to
  any non-permanent type. `nik` (national ID) exists again as an optional column — it was
  deliberately dropped in `2026_07_16_100003` when Employee ID alone was judged sufficient; the
  Milestone 4 spec requires it, so it's back, nullable, holding no restored historical data.
- **Intern/PKL detail** (`App\Models\EmployeeInternship`, table `employee_internships`): a
  one-to-one detail extension of `Employee` (not a duplicate employee table — see the Master Data
  Principle), populated only when `employment_type` is `intern`/`pkl`. Holds institution, program,
  mentor, agreement number, placement dates, work location, induction status, insurance/BPJS
  coverage reference, evaluation notes, and completion status. Shown as its own card on the Employee
  Profile page and its own conditional section on the Employee form, never merged into the ordinary
  employee fields.

## Departments & Positions (company-scoped master data)

**Department:** Administration (managed via Settings, not duplicated into Human Resources — see
`ADR/007-workspace-navigation.md`).

Both belong to a required `company_id` (positions' was added later than departments'; see
`ARCHITECTURE.md`). Both have `description` (optional) and `is_active` (not a separate `status`
column — `is_active` already covers that concept; don't add a redundant one). A name only needs to
be unique within its own company. Managed via Settings, filterable there by company using the
same per-request query-param convention as everywhere else.

## Projects

**Department:** Project Management.

`Project` model, belongs to a `Company`. Employee assignment is a genuine many-to-many via
`ProjectManpower` (`project_id`, `employee_id`, `assigned_date`, `added_by`) — not a direct column
on `Employee` — since an employee can be on more than one project over time and history matters.

## Daily Reports

**Department:** Project Management (not HSE — a judgment call, see below).

`DailyReport` (`app/Models/DailyReport.php`), belongs to a `Project`. A project may have several
Daily Reports on the same date (different departments/shifts reporting separately); each report
represents a *department* (free-text `department_name`, no master list), not an individual, and has
no manpower or PPE fields (those live in `ProjectManpower`/`EmployeePpe`). Has `activities`
(ordered, `DailyReportActivity`) and `photos` (`DailyReportPhoto`). Creating a report writes a
summary event to `project_timeline_events`, so the Project Timeline is *derived* from this module
rather than re-entered separately — this is why it's placed in the Project Management workspace
rather than HSE, even though reports originated as HSE-Officer-authored: structurally it's a
project activity/progress log, not an HSE-specific record. See
`ADR/007-workspace-navigation.md` for the full reasoning if this placement needs revisiting.

## PPE (Personal Protective Equipment) Management

**Department:** HSE.

The most structurally involved existing module — worth extra care before changing.

- **`PpeType`** — master data (types of PPE, e.g. helmet, gloves, safety shoes). Size support is
  explicitly deferred (no size tracking exists anywhere yet, including in PPE Replacement Request —
  don't fabricate size data if asked to display it; it genuinely isn't there).
- **`EmployeePpe`** — the actual issued-PPE record, one per assignment. **The database table is
  named `employee_ppe` (singular)**, an intentional deviation from Laravel's default pluralization
  — this has caused a real migration bug before (a foreign key using bare `->constrained()` guessed
  `employee_ppes` and failed). Always pass the table name explicitly:
  `->constrained('employee_ppe')`. Status lifecycle: `issued -> in_use -> replacement_requested ->
  replacement_approved -> replacement_completed -> archived` (plus `expired`, derived from
  `expiry_date`).
- **Employee PPE list page** (`Ppe/Employees.jsx`) is deliberately a minimal, employee-level
  selector (Name / Department / count) — it does not, and per its own design intent should not,
  support selecting individual PPE line items.
- **Replacement Due page** (`Ppe/ReplacementDue.jsx`) is a *separate, dedicated, item-level* page
  (one row per overdue PPE record, not per employee) built specifically because the employee-level
  list above can't support multi-selecting individual items without abandoning its own minimal
  scope. This is the only place a PPE Replacement Request can be created from.
- **PPE Replacement Request** — an MVP workflow (not the full Approval/Workflow Engine treatment
  Material Request got): multi-select overdue items, auto-populate Employee/Employee ID-NIK/
  Department/PPE Item from the existing `EmployeePpe` relationship (read live, not duplicated onto
  the request), capture `project_id` and `quantity` directly on the request (a point-in-time fact,
  not re-derived later from an employee's possibly-since-changed project assignments). Creating a
  request flips the selected items' status to `replacement_requested`.
- PDF generation for replacement requests goes through the shared `PdfGeneratorService`.

## KPI Input & Reporting

**Department:** HSE (moved from Human Resources in v1.10.4 — its routes were already gated
`role:super_admin,hse`; the sidebar/department mapping had disagreed with that since v1.10.0. HR
keeps a locked "HR KPI" placeholder for a genuinely separate, not-yet-built future concept — see
`ADR/007`'s v1.10.4 section).

- **`KpiCategory`** — master data for what's being measured.
- **KPI Input** — the data-entry flow, one row per employee per category per period.
- **`KpiReportService`** — assembles report data (grouped by department, one row per employee, one
  column per KPI category) — this is the actual business logic; `KpiReportExport` just renders it
  to Excel.
- **`KpiReportExport`** implements `ReportExportInterface` as the current default/generic export —
  see `ARCHITECTURE.md`'s Report Export Architecture section for how a real company-specific
  template would plug in later without touching `ReportController`.

## Material Request

**Department:** Logistics / PPIC. Pending approvals also surface in **Work Center**
(`ADR/007`'s v1.8.0 section) for whoever is entitled to decide them, regardless of their own
department — the module itself still has exactly one implementation, Work Center only links into it.

The most complete example of the reusable-engine pattern — read this section together with
`ARCHITECTURE.md`'s engine descriptions and `ADR/006-material-request-workflow.md`.

- **Full lifecycle**: `draft -> submitted -> approved -> processing -> completed`, with
  `rejected`/`cancelled` branches. Enforced by `HasWorkflow`'s `$transitions` map on the
  `MaterialRequest` model — invalid transitions throw a descriptive error, they don't silently fail
  or get caught only by UI convention.
- **"Pending Approval" is not a stored status** — it's how `submitted` is *labeled* in the UI while
  the associated `Approval` record's own status is `pending`. Don't add a literal
  `pending_approval` database value; it would duplicate what `Approval.status` already represents.
  See `ADR/006` for the full reasoning.
- **Roles**: Employee (creates/edits/submits, gated by `canManageMaterialRequests()`), Supervisor
  (`manager` role — approves/rejects, via the generic Approval Engine), Warehouse (new role,
  processes/completes), Company Admin (`super_admin`, can always override, including the narrow
  "reopen a Rejected request back to Draft" path that's never shown as a standard user action).
- **Dynamic action buttons** on the Show page render based on both current status and the viewing
  user's permission — Draft shows Edit, Submitted shows the reusable `ApprovalActions` component,
  Approved shows Start Processing, Processing shows Complete, Rejected shows the rejection reason
  read-only (with the override-only Reopen path for admins), Completed/Cancelled are fully
  read-only.
- **Dynamic item table** with per-item optional reference image upload, both on create and edit
  (edit correctly cleans up orphaned images for removed rows).
- **Department-agnostic by design** — built for HSE first, but nothing in the schema or queries
  assumes HSE; `department_id` is a plain nullable foreign key.
- PDF generation and Activity Timeline both go through the shared engines described in
  `ARCHITECTURE.md` — this module doesn't have its own parallel PDF or logging logic.

## Task Engine

**Department:** none — still no sidebar/department entry of its own (`tasks.*` isn't in any
department's `items` array). As of v1.8.0 it's reachable a different way: tasks assigned to the
current user surface in **Work Center** (`ADR/007`'s v1.8.0 section), the global cross-department
action center, via `Task`'s own `assignedTo`/`openStatus` scopes. A dedicated department entry (most
likely under Project Management) is still a future recommendation, not something this redesign did.

## Leave

**Department:** Human Resources (v1.10.0).

Second real consumer of the Universal Approval Engine (`HasApprovals`) and Workflow Engine
(`HasWorkflow`), after Material Request — proves both are genuinely reusable across modules, not
Material-Request-specific. `LeaveRequest` (`app/Models/LeaveRequest.php`): `draft -> submitted ->
approved/rejected -> cancelled`, deliberately no "processing" step (approved leave doesn't need a
warehouse-style hand-off, it's simply granted) and no line items. Creation/editing gated by
`User::canManageLeaveRequests()` — mirrors `canManageMaterialRequests()`'s "operational staff create
on behalf of" pattern, since there's no employee self-service login in this app. Numbered
`LR-{year}-{00001}`, same convention as `MR-`/`TSK-`/`INC-`/`GR-`.

## Incident Management

**Department:** HSE (v1.10.0).

`Incident` (`app/Models/Incident.php`): Workflow Engine only (`reported -> investigating ->
closed`), deliberately no Approval Engine — closing an incident is an HSE operational decision, not
something that needs a separate approver the way Material Request/Leave do. Fields: severity
(minor/moderate/major/critical), category (injury/near_miss/property_damage/environmental/other),
optional project link. Gated by `User::canManageIncidents()`. Numbered `INC-{year}-{00001}`.

## Milestones

**Department:** Project Management (v1.10.0).

`Milestone` (`app/Models/Milestone.php`), `belongsTo(Project)`. Deliberately the simplest of the four
v1.10.0 modules — a plain `status` field (pending/in_progress/completed/delayed), not a workflow
state machine; a milestone is a tracked date, not a multi-step approval. `is_overdue` is a computed
accessor (status != completed AND target_date is past), same "derive, don't duplicate" pattern as
`Task::is_overdue`. One Index page with inline Add/Edit dialogs (`resources/js/Pages/Milestones/Index.jsx`)
rather than separate Create/Show pages — matches Settings' Companies/Departments tabs, since a
milestone is a handful of fields against an already-existing Project. Gated by
`User::canManageMilestones()`, which reuses the existing `canManageProjects()` rather than inventing
a parallel permission.

## Goods Receipt

**Department:** Logistics / PPIC (v1.10.0).

`GoodsReceipt` + `GoodsReceiptItem` (`app/Models/GoodsReceipt.php`, `GoodsReceiptItem.php`). Records
materials arriving against an already-approved/processing/completed Material Request — deliberately
no workflow or approval of its own; a receipt is a one-time record of what arrived, not a lifecycle.
Dynamic item table on create (same add/remove-row pattern as Material Request, no image uploads —
unneeded here), no edit route — a receipt is treated as an immutable record once saved. Gated by
`User::canManageGoodsReceipts()`, mapped to the `warehouse` role (the same role this app introduced
specifically for *processing* Material Requests — receiving goods is the same responsibility).
Numbered `GR-{year}-{00001}`.

## Department Dashboards (HR, HSE, Project Management, Logistics)

v1.10.0. Each CORE department now has its own real Dashboard (`HrDashboardController`,
`HseDashboardController`, `ProjectManagementDashboardController`, `LogisticsDashboardController` +
`resources/js/Pages/{Hr,Hse,ProjectManagement,Logistics}/Dashboard.jsx`), reachable as that
department's first sidebar item and routed at `{department-key}.dashboard`. Every widget on every one
of these pages is backed by real, already-queryable data — each controller's own doc comment lists
exactly which spec-requested widgets were deliberately left out because no data model backs them yet
(Attendance, Recruitment, Training Due, Contract/Document Expiry for HR; Safe Man Hours, PTW,
Inspection, Risk Assessment, Safety Meeting, Training Due, Calendar for HSE; Project Calendar for PM;
Low Stock, Outgoing Goods, Stock Summary for Logistics). These are not silently dropped — they'll
appear once their own modules are built, following the same "real data only" rule the rest of this
codebase already holds to (see Dashboard/Index.jsx's own comment about not fabricating Pending
Tasks/Approvals before the Task/Approval Engines existed).

The global, cross-department `Dashboard` (`dashboard` route, the app's landing page since v1.9.0,
`DashboardController`) is unchanged and distinct from these — it's the org-wide executive rollup, not
a department-scoped operational view.

## Future Departments (Coming Soon)

Warehouse, Procurement, Asset Management, Maintenance, Quality Control, Finance. Kept visible in the
Department selector per explicit instruction ("do not remove them"), each with one real link to a
shared `ComingSoon` page (`app/Http/Controllers/ComingSoonController.php`,
`resources/js/Pages/ComingSoon.jsx`) rather than a disabled sidebar row — there's genuinely somewhere
to go now, even though no real module exists yet. Each department gets its OWN route name
(`{department-key}.coming-soon`, e.g. `warehouse.coming-soon`) pointing at the same controller/page —
not one shared route name — because `workspaces.js`'s active-department detection keys off the
route-name prefix; six items sharing one route name would all collide onto whichever department
happened to be resolved last. See `ADR/007`'s v1.10.0 section.

`Task` model, deliberately generic/polymorphic (`related_module` + `related_record_id`) so any
future module can attach tasks to its own records without the `tasks` table or `TaskService`
needing to know about that module specifically. Sequential numbering (`TSK-{year}-{00001}`) —
Material Request and PPE Replacement Request's own numbering (`MR-`, `PRR-`) follow the same
convention. Deliberately minimal for now (no comments/attachments/history on tasks themselves yet).

## Settings

**Department:** Administration. As of v1.9.0, Administration's sidebar has one item per tab
(Users, Departments, Positions, Companies, Module Management) plus a plain "Settings" item — all six
route to this SAME page (`settings.index`) with a different `?tab=` query param, reusing its existing
`?tab=` deep-linking rather than splitting it into separate pages. See
`ADR/007-workspace-navigation.md`'s v1.9.0 section for why, and how active-tab highlighting works
across multiple sidebar items sharing one route.

Covers: Company profile/branding, Departments, Positions, KPI Categories, Users (with roles),
module enable/disable toggles (`config/modules.php`'s registry, persisted via `CompanySetting`),
database backup/restore. Two permission tiers: Super Admin + HSE for "operational" settings
(Departments/Positions/KPI Categories), Super Admin only for "system" settings (Companies, Users,
backup/restore, branding) — enforced both by route middleware and by controller-level checks as
defense in depth.

**`CompanySetting`** is the generic key-value settings store other parts of Settings persist
through (`enabled_modules`, branding fields, etc.) — read `CONVENTIONS.md`'s pitfalls list before
touching its caching behavior; it has caused two real bugs already.

## Reports (global)

**Department:** Reports.

`ReportController` — the company-wide report landing page (`index`) plus `exportExcel` (via
`ReportTemplateResolver`, see the Report Export Architecture section below) and `exportPdf`. Kept as
its own workspace, separate from any module's own embedded reports (e.g. PPE's Reports tab lives
inside the HSE workspace's PPE module, not duplicated here) — this is the cross-cutting,
company-wide report surface, not a per-module one.

## Reusable engines (Approval, Workflow, Timeline, Import, PDF, Report Export)

These aren't a "module" with their own page — they're cross-cutting infrastructure consumed by the
modules above. Fully described in `ARCHITECTURE.md`, not repeated here.
