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

## Training & Competency Management

**Department:** Human Resources (`competency.master` — see workspaces.js's own comment on why this
lives under HR rather than duplicated into HSE too, mirroring how HSE KPI moved the other direction).

Milestone 4, Workstream A2. Answers "what job can this person safely and legally perform" via three
pieces:

- **`CompetencyType`** (table `competency_types`) — the training/certification catalog (e.g.
  "Working at Height", "SIO Crane"). One table for both Training and Certification (distinguished by
  `type`), not two near-duplicate tables. `company_id` is **required**, deliberately unlike
  `KpiCategory`'s own company_id-nullable-means-global pattern (`2026_07_20_100017`, which predates
  Tenant existing at all and is a confirmed, separately-flagged cross-tenant leak) — following
  Department/Position's own safer convention instead means this table is automatically tenant-safe
  via `Company`'s own `TenantScope`, no separate global-row case to get wrong. `validity_months`
  null means "never expires".
- **`EmployeeCompetency`** (table `employee_competencies`) — one row per employee per competency
  actually achieved. `effective_status` (`no_expiry`/`valid`/`expiring_soon`/`expired`) and
  `days_remaining` are computed accessors mirroring `EmployeePpe`'s own expiry-tracking pattern
  exactly (same 30-day window). `expiry_date` auto-computes from `achieved_date` +
  `competency_type.validity_months` on create if left blank. Managed inline on the Employee Profile
  page (Training & Certification card + Add dialog), not a separate top-level CRUD flow.
- **`position_competency_requirements`** — many-to-many pivot: which competencies a Position
  requires. Configured from the Competency Master page's Add/Edit dialog (checkbox list of
  positions), not yet surfaced as a gap-analysis view on the Employee side.
- **`competency.expiring-soon`** — cross-employee expiry monitoring (the real, data-backed HR
  Reporting KPI), scoped server-side to the current tenant's own companies only.

**IDOR discipline** (found and fixed live during this feature's own verification, not assumed):
`Store/UpdateCompetencyTypeRequest`'s `company_id`/`required_position_ids` and
`Store/UpdateEmployeeCompetencyRequest`'s `competency_type_id` all use `Rule::in()` against a
tenant-safe id list (`Company::query()->pluck('id')`, itself `TenantScope`-safe) rather than a plain
`exists:table,id` rule — Laravel's `exists` rule is a raw DB query that does **not** go through
Eloquent scopes, so a naive `exists:companies,id` would have validated another tenant's company id
just as happily as the current tenant's own. `EmployeeCompetencyController` additionally asserts the
target `Employee`'s `company_id` is in that same tenant-safe list before every write (`abort(404)`,
not 403, to avoid confirming a foreign employee id exists) — because `Employee` route-model-binding
itself has **no** tenant check anywhere in this codebase (a separately-flagged, broader pre-existing
gap affecting `EmployeeController` and others, not fixed here; this controller doesn't rely on it).

## Shift & Roster Management

**Department:** Human Resources (`shifts.master`).

Milestone 4, Workstream A3. Four pieces, all following the exact conventions established for
Training & Competency (Workstream A2) above:

- **`Shift`** (table `shifts`) — the shift catalog (Morning/Afternoon/Night, or whatever a tenant
  actually runs). `company_id` required, not nullable — same reasoning as `CompetencyType`.
  `is_night_shift`/`working_hours` are computed accessors (never stored) — a shift crosses midnight
  whenever its end time is earlier in the clock than its start; working hours = span minus break,
  correctly handling that overnight wraparound. `restHoursBefore()` computes the rest-hour gap
  between one shift's end and another's start on the following day — the real, computable building
  block a future consecutive-shift fatigue check would use, deliberately not a full monitoring
  engine (that needs real roster/attendance history to walk).
- **`EmployeeShiftAssignment`** (table `employee_shift_assignments`) — dated shift assignment
  history per employee (which shift, effective/end date, status), same one-to-many-history shape as
  `Subscription`/`EmployeeCompetency` rather than a single mutable "current shift" column.
- **`RosterPattern`** (table `roster_patterns`) — configurable rotation cycle master (e.g. 6-on/1-off
  site rotation). `dutyTypeOn(cycleStart, targetDate)` is the actual rotation math.
- **`EmployeeRoster`** (table `employee_rosters`) — the per-employee schedule: shift, rotation
  pattern, and site/project deployment (`project_id` reuses the *existing* `projects` table — Master
  Data Principle, no duplicate site/project concept — `site_name` is a free-text fallback for
  deployments with no Project record) over a date range. Deliberately does **not** generate one row
  per calendar day; `EmployeeRoster::dutyTypeOn(date)` computes on/off duty for any date on demand,
  delegating the cycle math to `RosterPattern`.
- **`rosters.overview`** — cross-employee "who is on/off duty right now" report, mirrors
  `competency.expiring-soon`'s role and tenant-safety pattern exactly.

**Bugs found and fixed live during this feature's own verification** (not assumed):
- Carbon 3 (Laravel 12) changed `diffInMinutes()` to return a **signed** value by default (Carbon 2
  defaulted to absolute) — `Shift::getWorkingHoursAttribute()` was computing "0 h" for a real
  23:00-07:00 shift instead of 7h because the signed result went negative before `max(...,0)` clamped
  it away. Fixed with `abs()` in both `getWorkingHoursAttribute()` and `restHoursBefore()`; confirmed
  live in the browser before and after the fix.
- `RosterPattern::dutyTypeOn()` called `$cycleStart->startOfDay()` directly on the passed-in Carbon
  parameter, which **mutates the caller's own instance** (Carbon objects are mutable by default) --
  fixed with `->copy()` first. Also guarded the day-difference with `abs()` for the same signed-diff
  reason as above.

Same IDOR guard pattern as Competency: `Store/UpdateShiftRequest`, `Store/UpdateRosterPatternRequest`,
and the `Employee*Request` classes all validate `company_id`/`shift_id`/`roster_pattern_id`/
`project_id` via `Rule::in()` over tenant-scoped id lists, never a raw `exists:table,id`.
`EmployeeShiftAssignmentController`/`EmployeeRosterController` both assert the target `Employee`
belongs to the current tenant before every write, same as `EmployeeCompetencyController`.

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

**Tenant-leak fix (was flagged as a separate background task, now resolved):**
`IncidentController::index()`/`show()`/`store()` used to query `Incident` with no company scoping
at all — the same bug class already fixed in `DashboardStatsService`/`HseDashboardController`. Fixed
in Workstream B14 while extending this same controller for Incident Investigation/CAPA — see the
"Incident Investigation + CAPA" section below for the full fix description.

## HSE Master Data (Hazard Category)

**Department:** HSE (Milestone 4, Workstream B0).

`HazardCategory` (`app/Models/HazardCategory.php`): tenant-scoped catalog (`company_id` required,
`restrictOnDelete()`) — mirrors `CompetencyType`/`Shift`'s own convention, not `KpiCategory`'s
nullable-means-global one. One setup page, `Hse/Master.jsx` (`hse.master` route), deliberately named
so future HSE masters (Safety Equipment types, Safety Material types, etc. — Workstream B10/B11)
land here as additional sections rather than each growing its own route. Gated by `User::isAdmin()`
for write, viewable by everyone (matches PPE/Competency/Shift's own viewable-by-all pattern).
`HazardCategoryController::update()`/`destroy()` include a per-instance
`assertInCurrentTenant()` ownership guard on the route-model-bound record — see this module's own
"why" note below (a real gap discovered in the sibling `CompetencyType`/`Shift`/`RosterPattern`
controllers while building this one, flagged separately rather than fixed silently).

## Safety Observation

**Department:** HSE (Milestone 4, Workstream B1).

`SafetyObservation` (`app/Models/SafetyObservation.php`): HSE's second real module beyond
PPE/Incident. Workflow Engine only (same choice as Incident — closing/verifying an observation is
an operational HSE decision, not a multi-party approval), full 7-state lifecycle: `draft -> open ->
assigned -> in_progress -> pending_verification -> closed`/`cancelled` (pending_verification can
also bounce back to in_progress, a "reopen"/rejected-verification path). `draft` is fully modeled
and transition-guarded but not reachable from the web Create form — the form reports directly into
`open`, the same one-click-report UX Incident already established; `draft` exists for a future
entry point (e.g. a mobile app saving a draft before submitting).

Fields: type (unsafe_act/unsafe_condition/positive — a fixed 3-way set, kept as a validated string
column + model constants, not a master table, same precedent as Incident's own category/severity),
severity (reuses Incident::SEVERITIES' exact scale — one consistent HSE severity vocabulary),
optional `hazard_category_id` (the one field that genuinely needed a tenant-configurable master —
see HSE Master Data above), free-text `location` (no Area/Location master exists anywhere in this
codebase — confirmed by audit before building this — so this matches `Incident.location`'s own
precedent rather than inventing one). `reported_by`/`assigned_to`/`closed_by` all point at `users`,
not `employees` (nobody who isn't a logged-in system User can be assigned a follow-up or notified of
a status change in this codebase today). The `reported_by` column name (not `observer_id`) is
deliberate — `HasWorkflow::notificationRecipient()` reads that exact column name for free, so
status-change notifications work without overriding it; "Observer" is purely the UI label.

Photo evidence: `SafetyObservationPhoto` (`app/Models/SafetyObservationPhoto.php`), a dedicated
child-row-per-photo model mirroring `DailyReportPhoto` exactly (not a single `photo_path` scalar
column) — uploaded via the shared `MultiImageUpload` component, stored under
`uploads/safety-observations` on the `public` disk.

Corrective action: `CorrectiveAction` (`app/Models/CorrectiveAction.php`) — a genuinely reusable
CAPA building block, **not** Safety-Observation-only. Polymorphic `source` (`source_type`/
`source_id`) so Incident (Workstream B14), HSE Inspection (B2), HIRADC (B4), etc. can attach their
own corrective actions to this SAME table later instead of each module growing its own
closure/follow-up columns — directly satisfies Workstream B15's "CAPA should be reusable across HSE
sources" requirement. `company_id` is stored directly on the row (copied from the source at
creation time) rather than derived by joining through the polymorphic relation on every query, so
the table stays uniformly tenant-safe regardless of which module created a given row.
`SafetyObservationController::transition()` creates one automatically the first time an observation
is moved to "Assigned" (using the observation's own `immediate_action` text as the default action
description), and marks it `verified` when the observation is closed. Not yet exposed as its own
standalone CAPA management page/route — that's explicitly deferred to whenever Workstream B15 is
built for real, not fabricated now to look more complete than it is.

Gated by `User::canManageSafetyObservations()` (same `isSuperAdmin() || isHse()` gate as
`canManageIncidents()` — there is no separate self-service "any employee" login in this app to grant
a broader creation-only permission to, unlike a literal reading of the spec's "General Employee:
Submit Safety Observation" RBAC table, which assumes an employee-login concept this codebase doesn't
have). Numbered `HSE-OBS-{year}-{00001}` via the existing Numbering Engine (new
`NumberGeneratorService::DEFAULTS['safety_observation']` entry — no bespoke numbering method).

**Tenant isolation, built in from the start (not retrofitted):** `SafetyObservation` has no
automatic `TenantScope` (only `Company` does), so `SafetyObservationController::index()` explicitly
`whereIn('company_id', ...)`-scopes its query, and `show()`/`transition()`/`destroyPhoto()` all call
a private `assertObservationInCurrentTenant()` guard (404-not-403, same pattern as
`EmployeeCompetencyController::assertEmployeeInCurrentTenant()`) before touching the route-model-
bound record. All foreign ids in `StoreSafetyObservationRequest` use `Rule::in()` over
tenant-scoped id collections, never a raw `exists:` rule.

**`HseDashboardController` tenant-leak fix (found while extending it to add this module's own
widget):** every existing query in that controller (`Incident`/`Project`/`EmployeePpe`) had NO
company scoping at all — the same bug class already fixed in `DashboardStatsService`. Fixed by
injecting `DashboardStatsService` and reusing its `resolveCompanyIds()` helper rather than a second
copy of the same logic; the new Safety Observation widgets (`openSafetyObservationsCount`,
`recentSafetyObservations`) were built tenant-safe from the start using the same helper.

## HIRADC / Risk Assessment

**Department:** HSE (Milestone 4, Workstream B4).

`RiskAssessment` (`app/Models/RiskAssessment.php`): document-level sign-off lifecycle via
`HasWorkflow` (`draft -> submitted -> approved -> archived`, plus `cancelled`/rejected-back-to-draft
branches) — same operational-sign-off choice as Incident/Safety Observation, not the Approval
Engine. `items` (activity/hazard/existing_control/likelihood/severity/additional_control/
residual_likelihood/residual_severity/pic/target_date) is a JSON column, not a child table —
these rows are always edited/viewed as one ordered document and nothing else in this codebase
queries an individual row today, unlike Safety Observation's photos or Gas Test's readings. Numbered
`HIRADC-{year}-{00001}`. Overrides `notificationRecipient()` to point at `preparer()` since the
model's own `prepared_by` column doesn't match `HasWorkflow`'s default `requested_by`/`reported_by`/
`created_by` convention.

**v1.10.9 finding**: `likelihood`/`severity`/`residual_likelihood`/`residual_severity` were captured
in the data model and validated on the backend from the start, but NOTHING anywhere in the codebase
ever computed or displayed a risk score/level from them (confirmed via a repo-wide search before
writing the fix — zero matches for any risk-level computation) — the risk matrix this module's own
migration doc comment describes was never actually finished. Fixed as part of the same pass that
built JSA's own matrix (see JSA's section below) — both now share ONE engine
(`resources/js/lib/riskMatrix.js`), not two.

## JSA (Job Safety Analysis)

**Department:** HSE (Milestone 4, Workstream B5, risk matrix added v1.10.9).

`JobSafetyAnalysis` (`app/Models/JobSafetyAnalysis.php`): identical shape/reasoning to
`RiskAssessment` above — same lifecycle, `steps` and `required_ppe` are both JSON. Numbered
`JSA-{year}-{00001}`.

**v1.10.9 (HSE Domain Hardening)**: `steps` extended with `consequence`, `likelihood`, `severity`,
`additional_controls`, `residual_likelihood`, `residual_severity`, `pic` — a pure JSON-shape/
application-level change (no migration; existing JSA records simply had these keys absent, handled
explicitly on load by merging each saved step onto a blank-field default rather than trusting the
saved keys, so old records open with real editable 1/1 defaults instead of breaking). Initial and
residual risk are computed live from likelihood × severity via the **same shared**
`resources/js/lib/riskMatrix.js` HIRADC now also uses — a standard 5x5 matrix (LOW 1-4 / MEDIUM 5-9 /
HIGH 10-14 / EXTREME 15-25), documented in that file as the one place this scale is ever defined.
Score/level are never stored — computed fresh on every render from the two raw inputs, same
"computed, not stored" principle as `Asset::is_overdue`/`PurchaseOrderItem::delivered_quantity`.
`JobSafetyAnalyses/Form.jsx`/`Show.jsx` moved from a per-row table to a stacked card-per-step layout
for the new matrix fields — a 13-column table was judged to cross into "spreadsheet, not an
operational tool" for a document genuinely meant to be filled out and read on a jobsite.
`RiskAssessments/Form.jsx`/`Show.jsx` kept their existing table layout (already established, already
used before this release) and simply gained the new Initial Risk/Residual L/S/Residual Risk columns
— less crowded to begin with (activity/hazard/existing_control vs. JSA's task_step/hazard/
consequence/control_measure), so a table stayed readable there without the same rework.

## Permit To Work

**Department:** HSE (Milestone 4, Workstream B6).

`PermitToWork` (`app/Models/PermitToWork.php`): fuller sign-off lifecycle via `HasWorkflow`
(`draft -> submitted -> approved -> active -> closed`, plus `rejected`/`cancelled` branches).
Optional links to an approved `RiskAssessment`/`JobSafetyAnalysis` (reused, not duplicated).
`required_qualification` is a **plain free-text label**, deliberately NOT a foreign key into
`competency_types` and NEVER auto-checked against any employee's actual certificates — this is a
hard, explicit requirement from the Workstream B spec: "PTW MUST NOT automatically inspect every
certificate belonging to an employee. Instead support optional 'Required Qualification'
configuration per permit/work type." HSE decides per-permit whether one applies and what it says;
the system never blocks issuance on it. Already gets automatic status-change notifications for free
via `HasWorkflow` (its `requester()`/`requested_by` naming already matches the trait's own
convention, no override needed). Numbered `PTW-{year}-{00001}`.

Backing table is `permits_to_work` (from its creation migration,
`2026_08_20_100067_create_permits_to_work_table`), NOT Eloquent's naive default
(`permit_to_works`, from pluralizing only the last word of the snake-cased class name) — the model
carries an explicit `protected $table = 'permits_to_work';` for exactly this reason. This was a real
production bug (`HseDashboardController`'s `openPermitsCount` widget threw `SQLSTATE[42S02]: Base
table 'permit_to_works' doesn't exist`) fixed by correcting the model to match the already-migrated
table, not the other way around — see `CONVENTIONS.md`'s Migrations pitfalls for the general rule
this established.

**v2.4.0 (PTW UX + Field Operations pass, Phase 1)** — the first module brought to a
production-grade UX/document pass, others to follow in later phases per that directive's own
sequencing:
- **Create form** (`PermitsToWork/Form.jsx`) reworked around progressive disclosure: required work
  info + a distinct "Safety Controls" section always visible, `required_qualification`/Linked
  HIRADC/Linked JSA moved into a new collapsed "Optional / Advanced" section
  (`Components/shared/CollapsibleSection.jsx` — the first collapsible-section primitive in this
  codebase; none existed before, confirmed via audit). No field/validation/route changed — purely a
  layout regroup, same `StorePermitToWorkRequest` rules as before.
- **`store()` fixed to actually submit, not just save a Draft**: previously created the permit in
  `STATUS_DRAFT` and stopped, even though the Create form's only button says "Submit PTW" — HSE
  could never see it as pending without a separate manual "Submit" click on the Show page first. Now
  immediately calls `transitionTo(STATUS_SUBMITTED, ...)` right after creation, reusing the existing
  `HasWorkflow` state machine (no bypass, no new status) so Create → Pending Approval is one action,
  matching the product's own acceptance-test description of this flow.
- **PDF document generation wired in**: `PermitToWorkController::pdf()` (route
  `permits-to-work.pdf`) + `resources/views/pdf/permit-to-work.blade.php`, following the EXACT same
  `PdfGeneratorService`/`DocumentEngine` pattern `MaterialRequestController::pdf()` already uses
  (optional per-tenant letterhead via `DocumentEngine::resolveTemplate('permit_to_work', ...)`, null
  until a Company Admin creates one). PTW had been explicitly named a "future" consumer in
  `PdfGeneratorService`'s own doc comment since v1.6.8 — this is that follow-up. "Download PDF" and
  "Print" (opens the same inline PDF; the browser's own PDF viewer provides Print from there, no
  second rendering pipeline) are visible to anyone who can view the permit, not gated to HSE, since a
  Foreman must be able to download/share their own permit.
- **Reject now requires a reason** (`transition()`'s `reason` field, `required_if:status,rejected`),
  passed through to the existing `HasWorkflow::transitionTo()` `$meta['comments']` parameter that
  `notifyStatusChange()` already reads — a rejected requester's notification now actually explains
  why.
- `StatusBadge` gained a `closed` mapping (previously fell through to the generic default, making a
  finished PTW visually indistinguishable from an in-progress one).
- Every grid in `Form.jsx` now has an explicit `sm:` responsive fallback (previously stayed 2 columns
  at any width — "desktop shrunk," not actually responsive).

**v2.6.0 (PTW Document View pass)** — closed the real product gap the v2.4.0 pass left open: PTW was
still only ever shown as application data/detail cards on `Show.jsx`; there was no in-browser
presentation that read like an actual HSE permit document.
- New route `permits-to-work.document` → `PermitToWorkController::document()` → new page
  `PermitsToWork/Document.jsx`. A SEPARATE page from `Show.jsx`, not a redesign of it — Show keeps its
  role as the workflow/action page (Approve/Reject/Cancel/Gas Test entry), Document is the
  read-oriented, shareable, print/PDF-ready presentation. `document()` loads the exact same relations
  and resolves the exact same `DocumentEngine` template/branding as `pdf()` — one data shape, two
  renderers (this HTML page, and the existing `pdf/permit-to-work.blade.php`), so the browser document
  and the downloaded PDF can never drift apart on what they show.
- Sections: Company Header, Document Information (project/site/location/type/validity/applicant),
  Work Information, Hazards/Risk Controls (precautions + linked HIRADC/JSA — reused relations, not
  duplicated), Gas Test (same data `Show.jsx`'s own Gas Test card already reads), Authorization
  (requester/HSE approver/area authority/closer, reflecting the actual workflow state — "Menunggu
  Persetujuan" only while genuinely `submitted`, a rejection reason block only when genuinely
  `rejected`), a 3-column signature block (blank unless a real person is actually associated with that
  role — never a fabricated signature), and a document footer. Deliberately NO "Required PPE" section
  — `PermitToWork` has no PPE field, and the explicit instruction was not to invent a database field
  just for a visual section.
- Rejection reason: not a column on `PermitToWork` — recorded via `transition()`'s existing `reason`
  field (v2.4.0) into `ActivityLog.meta` (the same trail `Show.jsx`'s Activity timeline already reads).
  `document()` pulls the latest `rejected`-action log's `meta.comments` for display; correctly shows
  nothing for a PTW rejected before v2.4.0 added that field, rather than fabricating a reason.
- Print uses the browser's own print dialog, isolated to just the document via a standard
  `@media print { body * { visibility: hidden } #ptw-print-area { visibility: visible } }` rule
  scoped to this page only — no second rendering pipeline, no new dependency. Download PDF is
  unchanged, reusing the exact same route/service from v2.4.0.
- `Show.jsx` gained a new primary "View PTW Document" action (solid button, visually dominant over the
  existing secondary Download PDF/Print actions next to it) — not gated by `canManage`, same reasoning
  as Download PDF/Print: a Foreman must be able to open/share their own permit's document.
- Security: `document()` uses the identical `assertInCurrentTenant()` 404 guard as `show()`/`pdf()` —
  no new authorization surface, no gate weakened.

**v2.8.0 (PTW Mobile / Task-First pass, Phase 3B)** — audited every PTW page fresh (not assuming
prior-pass findings still held) from a field-user perspective; fixed what the audit actually found,
left what already worked alone.
- **`PermitsToWork/Index.jsx`**: previously one enterprise `<Table>` for every viewport, Status as the
  rightmost of 6 columns — on a phone this meant scrolling horizontally just to see status, the one
  thing that matters most. Added a genuine mobile card list (`md:hidden`) alongside the existing
  desktop table (`hidden md:block`, completely unchanged) — same `permits` data, a second
  presentation for narrow viewports: PTW number + status together up top (no scrolling), permit type,
  work description, location + start date, one obvious "View PTW" action.
- **A real, pre-existing gap closed**: `PermitToWork::$transitions[REJECTED]` has always allowed
  `[draft, cancelled]` — a genuine "resubmit" path already modeled in the backend — but `transition()`'s
  own validation `Rule::in([...])` never included `STATUS_DRAFT`, and `Show.jsx` never rendered a
  button for it. A rejected permit's UI had ZERO available action besides Cancel, despite the state
  machine supporting resubmission. Fixed on both ends: `STATUS_DRAFT` added to the allow-list, and a
  "Resubmit" button added to `Show.jsx` for `rejected` status — moves back to Draft, after which the
  existing Draft "Submit" button carries it to Submitted again (the two-step path the model actually
  defines; no direct `rejected -> submitted` shortcut was invented).
- **Rejection reason surfaced on `Show.jsx` itself**, not just the Document view — previously only
  reachable via the Activity timeline card (secondary, off-screen on mobile without scrolling) or the
  separate Document page. `PermitToWorkController::rejectionReasonFor()` extracted out of `document()`
  (same lookup, now shared, not duplicated) so `show()` can pass it too.
- Mobile grid fixes: the Requested By/HSE Approver/Closed By row (`grid-cols-3`, no fallback) and the
  Gas Test "Add Reading" form (`grid-cols-2`, no true 1-column mobile fallback) both now stack properly
  on a phone (`grid-cols-1 sm:grid-cols-... `).
- Explicitly NOT changed, confirmed already correct via this pass's fresh audit: PTW Create form
  (progressive disclosure, responsive grids, natural-Indonesian placeholders — all already in place
  from Phase 1), the one-step Submit-to-Pending-Approval behavior, StatusBadge's status vocabulary
  (kept in English per "follow existing IOMS language conventions rather than introducing inconsistent
  translations everywhere" — only helper text/banners are Indonesian, matching the rest of this
  module).
- No database change. No new route. No RBAC change — `Rule::in()` widened by exactly one already-legal
  status value; every existing authorization check (`canManageHse()`, `assertInCurrentTenant()`)
  unchanged.

**v2.9.0 (Field/Foreman Experience pass, Phase 3C — My PTW)** — a second, field-oriented view over the
SAME `permits_to_work` table the enterprise `PermitsToWork/Index.jsx` already lists. No new table, no
new model, no duplicated PTW logic.
- **Ownership**: confirmed via a fresh audit that `requested_by` (→ `PermitToWork::requester()`) is the
  ONLY existing ownership field on this model — no new column/relationship introduced. `My PTW`
  (`PermitToWorkController::myIndex()`, route `permits-to-work.mine` → `/permits-to-work/mine`,
  registered ahead of the `{permitToWork}` wildcard so it can't be swallowed as an ID) filters
  server-side on `requested_by = $request->user()->id` in addition to the same tenant scope `index()`
  already applies — there is no client-suppliable "whose permits" parameter anywhere, so this can never
  be used to browse another user's permits by guessing a query string.
- **Frontend**: new `PermitsToWork/MyIndex.jsx` — always a compact card list (never the dense
  enterprise table, on any viewport, per the explicit product direction), a horizontally-scrollable
  filter-tab row (All/Pending/Approved/Active/Rejected/Closed, exact real status values — no synthetic
  grouping invented), and a real per-status count badge on each tab from one grouped `COUNT(*) ...
  GROUP BY status` query. Create PTW still goes to the exact same `permits-to-work.create` form from
  Phase 1; every card's action goes to the exact same `Show.jsx` (View PTW Document/Download PDF/
  Print/Resubmit, all from Phases 2/3B) — no duplicated detail logic, no second PTW form.
- **Rejected cards** show the rejection reason inline and a direct "Resubmit" button (posts to the same
  `permits-to-work.transition` → `draft`, the identical real transition Phase 3B added to `Show.jsx`) —
  reasons for the current page's rejected items are fetched with ONE batched `ActivityLog` query
  (`whereIn('subject_id', $rejectedIds)`), not one query per card.
- **Field Home integration** (`DashboardController::fieldHome()`): the "My PTW" tile now points at
  `permits-to-work.mine` instead of the enterprise `permits-to-work.index`, and its description shows
  real pending/active counts ("N menunggu persetujuan, M aktif") from the same tenant+requester-scoped
  query, or a generic fallback when there's nothing to report — never a fabricated number.
- **Role limitation, unchanged from Phase 3A**: there is still no dedicated Foreman/field role.
  `isDepartmentUser()` remains the same documented MVP proxy this pass builds on top of, not a new
  concept — see `DashboardController::index()`'s own doc comment.
- **Enterprise experience untouched**: `PermitsToWork/Index.jsx` (the table, its Type/Status filters,
  its own `permits-to-work.index` route) was NOT modified — HSE/Admin users keep exactly the view they
  already had.
- **Deferred to a later phase, not built now**: no changes to Digital Checklist/Safety Observation/
  Incident field entry points beyond what Phase 3A's Field Home tiles already provide, no offline/PWA/
  push infrastructure, no new role.

**v2.10.0 (PTW Document Polish pass, Phase 3D)** — audited the Document view against the PDF fresh
(re-read both from disk, not assumed complete) and found two real, confirmed gaps, both fixed; no new
document/table/route.
- **Browser Document vs. PDF parity gap, closed**: the browser Document view has shown the rejection
  reason banner since v2.6.0, but `pdf()` never received or rendered it at all — a rejected permit's
  PDF and its browser document materially differed. Fixed by passing the SAME
  `rejectionReasonFor()` helper's result (already extracted in Phase 3B, shared with `show()`) into
  `pdf()`'s render data too, and added the identical banner to the Blade template.
- **HIRADC/JSA were under-showing already-loaded data**: both outputs previously rendered only the
  bare reference number ("HIRADC: 12"). `document()`/`pdf()` already eager-load the FULL
  `RiskAssessment`/`JobSafetyAnalysis` models (no column restriction) — `title`/`job_title` were
  sitting unused in the response. Both outputs now show `{number} -- {title}`, no new query.
- **Print output made dark-mode-safe**: this app's dark mode is class-based
  (`tailwind.config.js` `darkMode: ['class']`), meaning the `.dark` class on `<html>` stays present
  during a browser print — every `dark:` utility on the Document page would otherwise still apply to
  the printed page, risking unreadable (e.g. near-white-on-white) output for anyone printing with dark
  mode on. The print media rule now forces plain black-on-white unconditionally inside the print area,
  independent of the viewer's theme.
- **`@page { size: A4; margin: 14mm; }`** added — previously relied entirely on the browser's own
  default print margins/orientation. **`break-inside: avoid`** added to every section
  (`data-print-section`) and the signature block, so a section can no longer split awkwardly across a
  page boundary.
- **Confirmed correct, not changed**: Gas Test's per-reading O2/LEL/H2S/CO columns already match the
  real schema (one row per test event, four gas readings inline — there is no discrete "gas type" or
  "threshold/limit" column in `gas_test_records` to show, so none was fabricated). The
  "Area Authority / PIC" signature block will still always render blank — `area_authority_id` has no
  writer anywhere in this codebase (re-confirmed by this pass's own audit) — an honest blank block for
  an unwired field, not a bug; no Area Authority workflow was invented to fill it.
- No database change. No new route. No RBAC/authorization change — `pdf()`/`document()` still use the
  identical `assertInCurrentTenant()` guard; only the data passed to the existing render calls changed.

### PTW Field Workflow Foundation + Controlled PTW Access (v2.17.0)

**Employee vs User Account vs PTW Access** — three separate concepts, confirmed distinct by this
pass's own fresh audit (not assumed):
- **`Employee`** — HR/HSE personnel record (name, department, position, active status). No login. No
  `user_id`/relation to `User` anywhere in this codebase — an Employee can exist with no account at
  all, and most do.
- **`User`** — one login = one person (this codebase's existing, unchanged rule; this pass does not
  introduce shared/generic accounts). Has a `role` (Super Admin/HSE/HRD/Manager/Warehouse).
- **`ptw_access`** (new, `users.ptw_access`, boolean, default false) — a per-User grant, independent
  of `role`. `User::canCreatePtw()` is `canManageHse() || ptw_access` — an HSE-role user keeps the PTW
  access they always had; `ptw_access` is the mechanism for a specific, individually-authorized
  non-HSE Field/Operations user to also create a PTW, exactly as many-employees /
  selected-user-accounts / limited-PTW-access is meant to work.

**Requester** — already correctly server-derived before this pass (confirmed by audit, not assumed):
`PermitToWorkController::store()` sets `requested_by = $request->user()->id` unconditionally;
`StorePermitToWorkRequest::rules()` has no `requested_by` key at all, so nothing in `$request->
validated()` could ever override it even if the frontend tried. No change was needed here — this
pass only widened WHO can reach `store()` (`canCreatePtw()` instead of `canManageHse()` alone), never
loosened what it records as Requester.

**PTW User Quota** — `packages.max_ptw_users` (nullable, `null` = unlimited/custom, same convention
as the existing `max_users`/`max_companies` columns on that table). `EntitlementService::
ptwUserQuota()`/`ptwUsersUsedCount()`/`canEnablePtwAccess()` are the enforcement surface;
`SettingsController::updatePtwAccess()` is the only place `ptw_access` can flip false→true, inside a
DB transaction with `lockForUpdate()` on the tenant's currently-enabled users, so two concurrent
"enable" requests can't jointly exceed the quota. Baseline seeded: Starter = 15 users / 15 PTW users,
Professional = 50 / 50 (a proportionate, NOT final, working default — see `PackageSeeder`'s own
comment), Enterprise = null / null.

**`max_users` vs `max_ptw_users` (fixed in v2.17.1)** — `max_ptw_users` is a SUBSET ceiling on
`max_users`, never a separate or larger pool ("of this package's User Account allowance, how many may
ALSO be PTW-enabled"). v2.17.0 originally shipped Starter with `max_ptw_users=15` against
`max_users=10` — flagged honestly in that pass rather than silently shipped as correct. v2.17.1 fixed
it by raising Starter's `max_users` to 15 (matching the explicit "Starter = 15 PTW users" baseline,
per the correction directive's own stated preference), and `PlatformController::validatePlan()` now
server-enforces `max_ptw_users <= max_users` (whenever both are set) for any future Plan edit.

**PTW Access management UX (v2.19.0 correction)** — canonical location: **Settings → Users → Field &
PTW Access**, never the HSE operational modules or the HSE Dashboard. Fixed a real gap: `route:list`
showed `settings.users.ptw-access` gated `role:super_admin` only, STRICTER than
`SettingsController::updatePtwAccess()`'s own `canManageHse()` check — meaning an HSE user could never
actually reach it despite the controller already allowing it (see `docs/CONVENTIONS.md`'s new pitfall
entry). Moved to its own `role:super_admin,hse` route group. Frontend: `SettingsController::index()`
now shares `can.manage_ptw_access` (= `canManageHse()`); the Users tab opens for `canManageUsers ||
canPtwAccess`, but `UsersTab` renders two independent cards — `UserManagementCard` (create/edit/
delete/role changes) stays Super-Admin-only, `FieldPtwAccessCard` (the toggle + "PTW Access X / Y
users" quota banner) opens to HSE too. An HSE user reaches exactly PTW Access, never user CRUD or role
changes; an ordinary Field/Foreman user (even with `ptw_access = true`) cannot reach Settings → Users
at all.

**PIC / Supervisor vs Requester vs Workforce** — three distinct people/concepts on one `PermitToWork`
row, never conflated:
- **Requester** (`requested_by` → `User`) — who submitted the PTW (existing, unchanged).
- **PIC / Supervisor Lapangan** (`pic_employee_id` → `Employee`, new, optional) — who's responsible
  for the work in the field. References `Employee`, not `User` — a PIC is not necessarily an IOMS
  login. Distinct from the pre-existing, still-unwired `area_authority_id` (`User`-based, no writer
  anywhere in this codebase, unchanged by this pass) — that field was relabeled from "Area Authority /
  PIC" to plain "Area Authority" in the Document view/PDF once PIC became a real, populated field of
  its own, so the two don't read as the same role.
- **Workforce** (`permit_to_work_personnel` pivot → many `Employee`, new, optional) — the permit's
  overall planned crew, always selected from real Employee records, never free text. Deliberately NOT
  duplicated into JSA — `JobSafetyAnalysis` has no personnel/manpower concept in this codebase at all
  (confirmed by audit), and this pass does not add one, per "don't force manpower into JSA simply
  because PTW now has it."

Both PIC and Workforce selectors are tenant-scoped and active-only
(`Employee::whereIn('company_id', $tenantCompanyIds)->active()`), enforced server-side in
`StorePermitToWorkRequest::rules()` via `Rule::in()` against that exact allow-list — a client-supplied
Employee ID from another tenant is rejected regardless of what the frontend sends.

**Field Home** — `DashboardController::fieldHome()`'s "Create PTW" tile was gated on `canManageHse()`
only, meaning the tile Field Home most needs to offer was invisible to the exact audience Field Home
exists for (non-HSE Field/Operations users) unless they also happened to hold the HSE role. Now gated
on the shared `canCreatePtw()` — the same check the backend route itself enforces, so this can never
grant tile visibility beyond what the server actually allows. No other Field Home change — it still
offers exactly PTW / My PTW / Today's Jobs / My Tasks, per the standing "Field Home stays minimal"
rule; no HSE Dashboard content was added.

**Document/PDF parity**: a new "Workforce" section (PIC + personnel list + total count) was added to
both `PermitsToWork/Document.jsx` and `pdf/permit-to-work.blade.php` identically — same data, same
wording, an unset PIC or empty Workforce list renders an honest "-"/"Belum ada personel", never
fabricated.

**PTW Experience & Visual Polish pass (v2.20.0)** — presentation only, no data/workflow/RBAC change.
`PermitsToWork/Document.jsx` and `pdf/permit-to-work.blade.php` were both reworked around a numbered
information architecture (01 Work Information → 02 Safety Controls → 03 Workforce → 04 Authorization →
05 Supporting Documents, the last only rendered when a HIRADC/JSA is actually linked) with a dominant
document-title block (permit type as the largest element, PTW number secondary) instead of the
previous flat sequence of same-weight `Section`s. PIC/Workforce/Requester now render via the new
`PersonChip` component (see `docs/CONVENTIONS.md`) instead of a plain "- Name" text list, on both
Document and `Show.jsx`. The pre-existing, still-unwired "Area Authority" signature block is
unchanged — still honestly blank, no Area Authority workflow was invented. `PermitsToWork/Form.jsx`
gained small numbered step badges (01–04) on its existing section headers, same visual language as
Document's own numbering, reinforcing the guided-workflow feel without changing any field or
validation rule. No PTW Index change this pass (v2.17.1's Project/PIC/Workforce columns already
covered Part 10's ask). No database, controller, or PDF-engine change — same `PdfGeneratorService`/
`DocumentEngine` pipeline, same data shape both renderers already shared.

**PTW Index visibility (v2.17.1)**: `PermitToWorkController::index()` now eager-loads
`pic:id,full_name` and `withCount('personnel')` alongside the existing `project`/`requester` eager
loads — still exactly one query per page of results, no N+1. `PermitsToWork/Index.jsx`'s desktop
table gained Project/PIC/Workforce columns (the shared `Table` component already self-contains
horizontal scroll via its own wrapper, so a wider table scrolls within itself, not the page); the
mobile card list gained an optional PIC/Workforce line, shown only when actually set.

## Gas Test

**Department:** HSE (Milestone 4, Workstream B7; location/stage added v1.10.9).

`GasTestRecord` (`app/Models/GasTestRecord.php`): real child table of `PermitToWork`
(`permit_to_work_id` required, not nullable, unlike `LotoRecord` — a reading is meaningless without
the permit it was taken for) — unlike HIRADC/JSA's JSON documents, multiple gas readings ARE
genuinely time-series/individually meaningful (a permit is periodically re-tested through its
duration/scope), so this is a real child table, not JSON.

Fields: O2/LEL/H2S/CO readings, pass/fail `result`, plus (v1.10.9) `location` (plain nullable
string — where the reading was actually taken, e.g. "Tank TK-001" or "Cargo Hold No. 2"; pre-filled
from the parent permit's own `location` but independently editable, since a single PTW's scope can
span more than one sub-location) and `stage` (`GasTestRecord::STAGES` — initial/re_test/final; every
reading is its own row, never overwritten, so one PTW can carry Initial 08:00 → Re-Test 10:30 →
Re-Test 13:00 → Final 16:00 as four distinct records). `location` was deliberately NOT modeled as a
foreign key to `Asset` or any other model — audited first (searched for Location/Area/Site/Facility
concepts codebase-wide; only `Asset` and `StorageLocation` exist, neither fits: a gas-test location
is very often not a registered Asset, and StorageLocation is warehouse-bin-specific) — plain text,
mirroring `permits_to_work.location`'s own already-established convention exactly.

Two entry points into the exact same `permits-to-work.gas-tests.store` action, one model, one table:
(1) the PTW Show page's own embedded "Add Reading" form (the original entry point, location
pre-filled from the permit); (2) a company-wide, cross-permit `GasTestRecords/Index.jsx` page
(`gas-test-records.index`) with its own "Add Gas Test" dialog (PTW selected via dropdown, location
pre-filled from whichever permit is picked). Both entry points capture location + stage identically.
Creation/deletion still only ever happens through `GasTestRecordController::store()`/`destroy()` —
no second creation mechanism.

## LOTO (Lockout/Tagout)

**Department:** HSE (Milestone 4, Workstream B8).

`LotoRecord` (`app/Models/LotoRecord.php`): deliberately simpler than `PermitToWork` — no
`HasWorkflow` state machine, just `isolated -> removed` (applied_by/applied_at,
removed_by/removed_at), matching how lockout/tagout is actually operated. Optionally linked to a
Permit To Work. Numbered `LOTO-{year}-{00001}`.

## Field / Foreman Experience — Phase 3E–3H completion (v2.11.0)

Completes the Field/Foreman Experience effort started in Phase 3A (Field Home landing page) and
carried through 3B–3D (PTW mobile/task-first UX, My PTW, PTW Document polish). Every module below was
audited fresh from disk before any change — nothing here rebuilds an existing engine (Digital
Checklist, CAPA linkage, Incident investigation separation, LOTO's own simple two-state lifecycle all
predate this pass and are unchanged); only real, confirmed field-usability gaps were fixed.

**Phase 3E — Digital Checklist** (`HseInspections/Form.jsx`): the checklist item entry, previously a
wide `<Table>` (`overflow-x-auto`, `min-w-[220px]` cells) forcing horizontal scroll on any phone, with
a 3-option dropdown per item to mark a result. Replaced with a stacked card per item — a checklist is
inherently a linear list to work through, not tabular data needing column comparison, so this reads
naturally on desktop too. Marking a result is now a one-tap 3-button toggle (OK / Not OK / N/A)
instead of opening a dropdown. No change to what's collected/validated/stored — same
`checklist_items` shape, same `HseInspectionController::store()`, same Load Template mechanism, same
honest item-count/Not-OK indicator (from a prior pass), same `raiseFinding()` CAPA linkage on
`Show.jsx`.

**Phase 3F — Safety Observation** (`SafetyObservations/Form.jsx`, `Index.jsx`): two header grids
(Date & Time/Location, Hazard Category/Severity) were bare `grid-cols-2` with no mobile fallback —
now `grid-cols-1 sm:grid-cols-2`, matching every other field form in this codebase. `Index.jsx`'s
empty state was English ("No safety observations recorded") with no action button, inconsistent with
every other module's already-Indonesian, action-equipped empty state — brought in line
("Belum ada observasi." + a "Report Observation" button). The quick-vs-investigation separation
(`store()` for the fast initial report, `transition()`'s automatic CAPA creation on assignment) was
already correct and untouched.

**Phase 3G — Incident** (`Incidents/Show.jsx`): the read-only investigation view (shown to a user
without `canManage`) displayed Root Cause and Findings but silently omitted Recommendations, even
though the editable form directly above it already collects all three — a field user without edit
rights could never see it. Fixed to show all three fields. `Form.jsx`'s mobile grids were already
fixed in an earlier pass and were re-confirmed correct, not re-touched.

**Phase 3H — JSA / HIRADC / Gas Test / LOTO field access**:
- JSA (`JobSafetyAnalyses/Form.jsx`) and HIRADC (`RiskAssessments/Form.jsx`) header grids (2 each,
  bare `grid-cols-2`) fixed to `grid-cols-1 sm:grid-cols-2`. Their per-step/per-item tables remain
  wide enterprise tables, deliberately NOT converted to mobile cards this pass — JSA/HIRADC are
  multi-field risk-assessment documents typically prepared by HSE ahead of time, not a field
  quick-action, and a field user already reaches the relevant JSA/HIRADC in context from an approved
  PTW's own "HIRADC: ... / JSA: ..." reference (Phase 3D) without needing to author one from a phone.
- Gas Test: confirmed to already have two real entry points (PTW Show's own inline form, and
  `GasTestRecords/Index.jsx`'s own dialog) — no third was added, no code changed.
- **LOTO**: confirmed via a fresh audit to have zero entry point anywhere outside typing its URL
  directly — not in `WorkCenterService::quickActionsFor()`, not on Field Home — despite having a real,
  working, standalone Create page since an earlier pass. Added one `New LOTO` quick action (same
  `canManageHse()` gate as its sibling HSE actions) and one secondary Field Home tile. JSA/HIRADC/Gas
  Test deliberately did NOT get their own Field Home tiles for the reasons above — adding four more
  tiles would violate the explicit "keep Field Home simple, no giant wall of buttons" direction; one
  genuinely-missing entry point does not.

**Field Home final tile set** (`DashboardController::fieldHome()`): Create PTW, My PTW, Digital
Checklist, Safety Observation, Report Incident, LOTO (secondary, new this pass), My Tasks — each still
gated by the exact same `canManage*()` capability its destination route independently enforces.

**Role/RBAC**: unchanged from Phase 3A. There is still no dedicated Foreman/field role;
`User::isDepartmentUser()` remains the same documented MVP proxy this whole Phase 3 effort is built on
— re-confirmed, not silently carried forward, by this pass's own audit.

**Explicitly deferred, not built this pass**: photo/evidence capture for Digital Checklist (the
directive itself said not to add this infrastructure unless it already exists and is necessary — it
doesn't exist for this module, only Safety Observation has it, and was left as-is), converting
JSA/HIRADC's item tables to mobile cards, Product Finalization, SaaS/Subscription/Billing,
Offline/PWA.

## TBM (Toolbox Meeting)

**Department:** HSE (Milestone 4, Workstream B3).

`TbmMeeting` (`app/Models/TbmMeeting.php`): same "logged after the fact" shape as `DailyReport` — a
TBM record IS the record of a meeting that already happened, no `HasWorkflow` lifecycle. Real
`tbm_attendees` pivot to `Employee` (not JSON) since per-employee attendance is genuinely useful to
query later (e.g. a future attendance/compliance report). Numbered `TBM-{year}-{00001}`.

## HSE Inspection

**Department:** HSE (Milestone 4, Workstream B2).

`HseInspection` (`app/Models/HseInspection.php`): same logged-after-the-fact shape as TBM, JSON
`checklist_items` (item/result[ok|not_ok|na]/remarks). A `not_ok` finding can be raised into a real
`CorrectiveAction` row directly from the Show page — reuses the existing polymorphic CAPA entity
(see Safety Observation's own section above), not a second findings-tracking system. Numbered
`HSE-INS-{year}-{00001}`.

## Incident Investigation + CAPA (cross-module)

**Department:** HSE (Milestone 4, Workstream B14/B15).

`IncidentInvestigation` (`app/Models/IncidentInvestigation.php`): one-to-one enhancement of the
EXISTING `Incident` model (root_cause/method[5_why|fishbone|other]/findings/recommendations) — not a
duplicate incident-tracking system. `Incident::correctiveActions()` now points at the SAME
`CorrectiveAction` polymorphic entity Safety Observation/HSE Inspection already use.

`CorrectiveActionController::index()` (`app/Http/Controllers/CorrectiveActionController.php`,
`/corrective-actions`) is a standalone, cross-source CAPA view over those SAME rows — grouped by
source (Safety Observation/HSE Inspection/Incident), with an inline status-update action
(`open -> in_progress -> completed -> verified`, plus `cancelled`). This directly answers the
Workstream B15 requirement that CAPA be reusable across HSE sources, by never creating a second CAPA
table per module.

**Also fixed in this same pass (found while extending `IncidentController` for
Investigation/CAPA, not a separate silent change):** that controller previously had ZERO company
scoping anywhere (`index()`/`show()`/`store()`) — the exact same bug class already fixed in
`DashboardStatsService`/`HseDashboardController`, previously flagged as a separate background task,
now resolved here since the file was already open for a real, in-scope reason.
`Incident.company_id` is nullable (its own older migration convention), so a null-company incident
stays visible to every tenant; company-scoped incidents are now tenant-isolated via the same
`assertInCurrentTenant()` 404-not-403 guard pattern used throughout this workstream. Raw `exists:`
validation rules in `store()` were replaced with `Rule::in()` over tenant-scoped id collections.

**v2.5.0 (Field HSE Experience pass, Phase 2 -- CAPA as an action management tool)**:
`CorrectiveActionController::index()` gained a tenant-scoped `summary` (Open/Overdue/In Progress/
Closed counts) and a real `overdue` quick-filter, matching `CorrectiveAction::getIsOverdueAttribute()`'s
exact logic by hand in raw SQL (that accessor stays computed/never stored, per its own doc comment --
if its rule ever changes, this query needs the same change made alongside it). Frontend
(`CorrectiveActions/Index.jsx`) renders these as a compact `StatCard` row, the Overdue card itself a
clickable filter shortcut. The destructive-feeling `cancelled` status transition now asks for
confirmation; the other three (`in_progress`/`completed`/`verified`) stay one-click, per the explicit
"do not over-confirm harmless actions" product direction.

## Safety Equipment, HSE Materials, P3K

**Department:** HSE (Milestone 4, Workstream B10/B11/B12).

Three deliberately SEPARATE tables, per the spec's own explicit taxonomy ("separate PPE / HSE
Consumables / Reusable Safety Materials / Safety Equipment/Operational HSE Assets / Emergency
Facilities. Do NOT treat all HSE items as the same thing"):

- `SafetyEquipment` (`app/Models/SafetyEquipment.php`): fixed-location operational assets (fire
  extinguishers, safety showers, eyewash stations, emergency alarms, spill kits) with a real,
  queryable `next_inspection_due` date — the thing the HSE Dashboard's "Overdue Equipment" widget
  and a future reminder actually key off.
- `HseMaterial` (`app/Models/HseMaterial.php`): consumables/reusable-materials catalog with a simple
  `current_stock`/`reorder_level` — deliberately NOT a warehouse/inventory-transaction system (no
  goods-receipt/issue ledger here); reordering still goes through the EXISTING Material Request
  pipeline (see below), this table is just the catalog + current level that pipeline's own
  HSE-category requests would reference.
- `P3kBox` (`app/Models/P3kBox.php`): first-aid station OPERATIONAL inspection record only
  (location/next inspection due/completeness status) — explicitly NOT a medical-records or
  treatment-log system, per the spec's own instruction that detailed medical records stay entirely
  HR-owned.

All three land on the shared `Hse/Master.jsx` page (extended from Workstream B0's Hazard Category
section, not three new standalone routes), tenant-scoped, with the same per-instance
`assertInCurrentTenant()`/`abort_unless()` ownership guard pattern on `update()`/`destroy()`.

## HSE Procurement (verified reuse, no new code)

**Department:** HSE (Milestone 4, Workstream B13).

Verified, not duplicated: `MaterialRequest` (`app/Models/MaterialRequest.php`) is already
deliberately department-agnostic (`department_id`, no hard-coded department list — see that
model's own doc comment), so HSE staff already use the SAME existing Material Request pipeline for
HSE consumables/equipment purchases today, with zero code changes required. No second
request/approval system was built.

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

**v1.10.7 security fix**: every method in `MilestoneController` was completely unscoped by tenant —
`index()` listed every tenant's projects/milestones, `store()` validated `project_id` with a raw
`exists:projects,id` (a real IDOR: any tenant could attach a milestone to another tenant's project),
and `update()`/`destroy()` had no ownership check on the route-bound `Milestone` at all. Fixed with
the same tenant-scoped `Rule::in()` + `assertInCurrentTenant()` pattern every other controller in
this codebase already uses — found during the v1.10.7 cross-module integration audit, not previously
caught by any prior pass.

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

**Milestone 4, Workstream C5 extension (Procurement PO integration, additive):**
`purchase_order_id`/`purchase_order_item_id` added as nullable columns alongside the existing
`material_request_id` — a receipt is EITHER against a Material Request (unchanged) OR a Purchase
Order (new), enforced at the application level in `GoodsReceiptController::store()`, not a DB
constraint. Recording a GRN against a PO recomputes and auto-advances that PO's own delivery status
(`issued -> partially_delivered -> fully_delivered`) via `transitionTo()`. **Tenant-leak fix found
while extending this controller** (same discipline as `HseDashboardController`/`IncidentController`
earlier this milestone): `GoodsReceiptController` previously had zero company scoping anywhere;
fixed via `whereHas()`-through-parent scoping and a new `assertInCurrentTenant()` guard (a
`GoodsReceipt` has no `company_id` of its own, so ownership is derived through whichever parent it's
linked to).

## Procurement Department (Milestone 4, Workstream C)

**Department:** Procurement — a genuine cross-department engine (per its own explicit spec
positioning), not owned by any single requesting department. HSE/Maintenance/Project/HR/Warehouse
etc. all raise a `MaterialRequest` (existing, unmodified) that Procurement can turn into a
`PurchaseRequisition` from here.

**Repository audit performed first:** confirmed no `Vendor`/`Supplier`/`PurchaseOrder`/`Rfq` table
existed anywhere before this workstream — `MaterialRequest -> GoodsReceipt` was a direct two-step
flow with no purchasing layer between them. Everything below is genuinely new, not a rename/
duplicate of something that already existed.

**Flow:** MaterialRequest (existing) → `PurchaseRequisition` → `Rfq` (+ `VendorQuotation` per
invited vendor) → Quotation Comparison (computed, on the RFQ's own Show page) → vendor selection →
`PurchaseOrder` (+ `PurchaseOrderItem`) → approval → issue → `GoodsReceipt` (existing module,
extended) → delivery reconciliation → closed.

### Vendor / Supplier Master
`Vendor` (`app/Models/Vendor.php`) + `VendorDocument` (dedicated child-row-per-document, mirrors
`DailyReportPhoto`'s pattern). Identity/contact/business fields per the spec. **Scope decision:**
qualification status (`draft/under_review/qualified/conditionally_qualified/rejected/suspended/
expired`) lives directly on the `vendors` row rather than a separate checklist/document
qualification table — avoids a second, thinner, un-integrated module while still enforcing every
state the spec asked for. Bank account fields are plain nullable strings (operational reference
data, not a payment-execution system — no accounting/banking integration exists in this codebase to
protect, matching the explicit "do not build accounting integration" instruction). Numbered
`VEN-{seq}` (never resets — a vendor code is a permanent identifier, not period-scoped).

### Purchase Requisition (PR)
`PurchaseRequisition` (`app/Models/PurchaseRequisition.php`). Procurement's own internal document,
optionally sourced from an approved `MaterialRequest` (`source_material_request_id`, nullable — can
also stand alone for Procurement-initiated purchasing). Full spec lifecycle via `HasWorkflow`:
`draft -> submitted -> under_review -> approved/rejected -> converted_to_rfq -> converted_to_po ->
completed`, plus `cancelled` from most states. `items` is JSON (same reasoning as HIRADC/JSA's own
line items from Workstream B — edited/viewed as one document, nothing else queries an individual
PR line). **Segregation of duties, reused from `MaterialRequestController`'s own precedent, not
reinvented:** create/submit gated to `canManageProcurement()`, review/approve/reject/cancel gated to
`config('workflow.approvers'/'overriders')` — a Procurement Officer never automatically gains
approval authority. Numbered `PR-PROC-{year}-{00001}`.

### RFQ + Vendor Quotation + Quotation Comparison
`Rfq` (`app/Models/Rfq.php`) always originates from an approved PR (`purchase_requisition_id`
required). Creating an RFQ transitions its parent PR to `converted_to_rfq` via the existing
`transitionTo()`. `RfqVendor` is a real invited-vendor pivot with a response-status lifecycle
(`invited/viewed/responded/no_response/declined/expired`), not a plain many-to-many.
`VendorQuotation` (one per vendor per RFQ, `unique(rfq_id, vendor_id)`) has JSON line items (same
reasoning as PR) with computed subtotal/discount/tax/shipping/total stored on save.

**Quotation Comparison is deliberately NOT a separate stored table** — it's a computed view built
at request-time on `Rfqs/Show.jsx` (side-by-side total/lead-time/payment-terms/validity per invited
vendor), with the selection OUTCOME (`selected_vendor_id`/`evaluation_notes`/`selected_by`/
`selected_at`) recorded directly on the RFQ row. **The system never auto-picks the cheapest
vendor** — selection is an explicit, notes-capturing human decision, per the spec's own explicit
"transparent evaluation" requirement. Numbered `RFQ-PROC-{year}-{00001}`.

### Purchase Order
`PurchaseOrder` (`app/Models/PurchaseOrder.php`). `vendor_id` required; `purchase_requisition_id`/
`rfq_id`/`vendor_quotation_id` all nullable — the normal path pre-fills price/terms from the
selected quotation (create form accepts `?rfq=`), but a direct PO without the full RFQ cycle is
also supported (real procurement operations sometimes skip the cycle for low-value/emergency
purchases). **Same "no separate Pending Approval status" convention as `MaterialRequest`** (see
`docs/ADR/006-material-request-workflow.md`) — `submitted` IS what a pending-approval PO looks like
from a data-model perspective, not a fifth invented status. Lifecycle via the SAME `HasWorkflow`
engine as every other document: `draft -> submitted -> approved/rejected -> issued ->
partially_delivered/fully_delivered -> closed`, plus `cancelled`. `partially_delivered`/
`fully_delivered` are reached AUTOMATICALLY from real delivered-vs-ordered quantity math (see Goods
Receipt's extension above), never a manual status button.

`PurchaseOrderItem` (`app/Models/PurchaseOrderItem.php`) is a REAL child table — unlike PR/RFQ/
Quotation's JSON line items, PO items ARE independently queried elsewhere: `delivered_quantity`/
`remaining_quantity`/`delivery_status` are computed accessors that sum `goods_receipt_items` on
every access (never a stored running total that could drift), giving the exact "PO Qty=100,
GRN#1=40, GRN#2=60, Remaining=0" reconciliation the spec describes.

Approval reuses `config('workflow.approvers')`/`'overriders'` — same segregation-of-duties
precedent as PR/MaterialRequest. **No fabricated amount-based approval threshold system** — the
spec explicitly said not to hard-code arbitrary financial thresholds; this is documented here as a
future per-tenant-configurable extension point (a `config/workflow.php` `procurement_approvers` key,
or a `NumberingFormat`-style per-tenant override row), not built now. Numbered
`PO-PROC-{year}-{00001}`.

### Vendor Performance (computed, not stored)
`VendorPerformanceController` (`/procurement/vendor-performance`). On-time delivery rate (completed
POs whose last Goods Receipt arrived on/before `delivery_date`) and RFQ response rate (`RfqVendor`
responded ÷ invited) are computed live from real `PurchaseOrder`/`GoodsReceipt`/`RfqVendor` rows on
every page load — never a stored score that could drift. A blank rate means "nothing to measure
yet," not zero; nothing is fabricated for a vendor with no completed transactions.

### Procurement Dashboard
`ProcurementDashboardController` (`/procurement/dashboard`). Pending PRs, open RFQs, quotations
awaiting evaluation, pending PO approval, open/overdue/partially-delivered/completed POs, YTD
procurement value, monthly trend, department breakdown, average purchase cycle time (real PR
`request_date` → PO `issued_at` deltas, only for PRs that actually reached a PO), active vendor
count. Reuses `DashboardStatsService::resolveCompanyIds()` — the same tenant-safe helper that fixed
the identical leak class in `HseDashboardController`/`IncidentController` earlier this milestone,
never reimplemented as a second copy.

### Procurement Reporting
The `PurchaseRequisitions`/`Rfqs`/`PurchaseOrders` Index pages already ARE the spec's own "PR
Register/RFQ Register/PO Register" (search + status filter + pagination) — not duplicated as
separate report pages. PDF/Excel export integration into the centralized Report Center/Analytics
dataset registry was audited as an existing extension point but **not wired this turn** — same
honest-scope decision as HSE Workstream B's own HSE Reports section; documented as a known
limitation, not silently skipped.

### Master Data
No new procurement-category/payment-terms/delivery-terms/vendor-category master tables — these are
small, structurally-fixed sets kept as validated string columns + model constants (same precedent as
Safety Observation's `type`/`severity` from Workstream B), avoiding master-data over-engineering for
values nothing else in this codebase needs to configure per-tenant yet. No `cost_center` master
either — plain free-text field on PR/PO, since no cost-center master exists anywhere to extend.

### RBAC
`User::canManageProcurement()` reuses the existing `warehouse` role (`isSuperAdmin() ||
isWarehouse()`) — same reasoning as `canManageGoodsReceipts()`: Warehouse is the logistics-
operations role this app already has, and Procurement is its natural extension, not a newly-invented
role. Financial authorization (PR/PO approval) is a genuinely separate gate
(`config('workflow.approvers')`, Manager/Super Admin) — segregation of duties enforced in every
controller action, never conflated with the operational `canManageProcurement()` gate.

### Tenant Isolation & IDOR
Every new controller: `Rule::in()` over tenant-scoped id collections throughout, never raw
`exists:`. Every route-model-bound action: a private `assertInCurrentTenant()` 404-not-403 guard,
built in from the start (not retrofitted) — including on `Vendor`, `PurchaseRequisition`, `Rfq`,
`PurchaseOrder`, and (added when this workstream needed to extend it) `GoodsReceipt` via its own
parent-derived ownership check. Data-integrity cross-references (a PO cannot reference a vendor/PR/
RFQ from another tenant, a GRN item cannot reference a foreign PO item) are enforced via `Rule::in()`
scoped to the SAME tenant on every foreign-id field, not left to frontend filtering alone.

## Acceleration Mode — Industrial Core Modules (Milestone 4, Acceleration Parts 1–7)

Seven domains built in one continuous acceleration-mode run: Material & Asset Management (Item
Master, Warehouse/Inventory, Asset Management), Maintenance CMMS Foundation, Project enhancement +
Quality Control Foundation, Contractor Management, Visitor Management, Document Control Foundation,
and Dashboard Integration. Same audit-first, reuse-first, tenant-isolation-non-negotiable discipline
as HSE Workstream B and Procurement Workstream C above — every controller uses `Rule::in()` over
tenant-scoped id collections (never raw `exists:table,id`) and a private `assertInCurrentTenant()`
404-not-403 guard on every route-model-bound action.

### Item Master

**Department:** Logistics / PPIC. `Item` — the central Warehouse-trackable catalog (`item_code`,
`name`, `category`, `unit`, `min_stock`, `is_active`), sequential-numbered via
`NumberGeneratorService` (`item` key). **Deliberately separate from `PpeType`/`HseMaterial`**
(HSE's own existing catalogs) — not retrofitted onto Item, and Item is not retrofitted onto them;
three catalogs for three different operational domains, matching the same reasoning as
`Vendor`≠`Contractor` below.

### Warehouse / Inventory

**Department:** Logistics / PPIC (kept inside Logistics, not split into a separate Warehouse
department, per explicit instruction). `Warehouse`, `StorageLocation`, `Stock` (a REAL stored
running balance per item+warehouse, updated atomically inside `DB::transaction()` +
`lockForUpdate()` — not computed on every read, since a warehouse balance is touched by an
unboundedly large number of movements over time), `StockMovement` (quantity column is ALWAYS
positive; direction is entirely determined by `type` via `StockMovement::isInbound()` checking a
const `INBOUND_TYPES` array — deliberately avoids the "movement type and signed quantity silently
disagree" bug class).

**`StockService`** (`app/Services/StockService.php`) is the single reusable `recordMovement()`/
`transfer()` entry point — used by `GoodsReceiptController` (receipt), `StockTransactionController`
(issue/transfer/adjust/opname), and `WorkOrderController` (spare part usage) alike, the same "one
engine, not N near-identical read-then-write blocks" reasoning as `NumberGeneratorService` itself.

**Goods Receipt → Warehouse integration**: additive nullable `goods_receipts.warehouse_id` +
`goods_receipt_items.item_id` — a receipt line only posts a real `StockMovement` when BOTH are set,
so pre-Warehouse-module receipts (Material Request-sourced, or PO-sourced before a warehouse/item
was selected) are completely unaffected. `GoodsReceiptController::store()` also advances the parent
`PurchaseOrder`'s own HasWorkflow status (`issued` → `partially_delivered`/`fully_delivered`)
automatically via a private `recomputePurchaseOrderDeliveryStatus()`, never a manual button.

**Known bug caught and fixed before shipping**: Stock Opname (physical count variance) can go either
direction, so `StockTransactionController::opname()` originally passed a possibly-negative variance
straight through as `quantity` to a hardcoded `StockMovement::TYPE_OPNAME`, which was listed in
`INBOUND_TYPES` — this would have silently corrupted stock balances on any negative-variance
(shrinkage) count. Fixed by removing `TYPE_OPNAME` from `INBOUND_TYPES` and recording opname
variances as real `ADJUSTMENT_IN`/`ADJUSTMENT_OUT` with `abs($variance)`, tagged `[Stock Opname]` in
the notes; `TYPE_OPNAME` stays defined in `StockMovement::TYPES` purely as a future reporting label,
never actually created.

### Asset Management

**Department:** Asset Management (promoted from a Coming-Soon placeholder to a real department this
phase). `Asset` + `AssetTransaction` — full lifecycle Purchase → Receive → Register (optionally
pre-filled from an issued PO via `?po=`) → Assign → Operate → Inspect → Maintain (see Maintenance
CMMS below) → Retire. Every lifecycle step past registration writes a real `AssetTransaction` row
(`assign`/`transfer`/`inspect`/`status_change`), never a silent field update — `Asset`'s own
`transactions()` relation IS its maintenance/lifecycle history, no separate history table.
`User::canManageAssets()` reuses the existing Warehouse operational role.

### Maintenance CMMS Foundation

**Department:** Maintenance (promoted from Coming-Soon). `MaintenanceRequest` (Request → Approved →
Converted to Work Order, via `HasWorkflow`) and `WorkOrder` (Draft → Scheduled → In Progress →
Completed/Cancelled). Spare part usage on a Work Order (`WorkOrderController::addSparePart()`) posts
a real `StockMovement` (`TYPE_ISSUE`) through the SAME `StockService` the Warehouse module itself
uses, with an availability check against `Stock::available_quantity` before allowing the issue.
Deliberately scoped to operational workflow only — no preventive-maintenance scheduling engine, no
full SAP PM equivalent.

### Project enhancement + Quality Control Foundation

**Department:** Project Management (Activities) / Quality Control (Inspection Request, NCR — QC
promoted from Coming-Soon this phase). `ProjectActivity` — a real owner+progress+status record,
**deliberately distinct from `DailyReportActivity`** (a free-text daily-report log line with no
owner or progress field) — feeds the Project Management Dashboard's Avg. Activity Progress widget.
Not yet wired into the Project Form/Show UI (`projects.activities` route exists and works, but the
existing Project pages don't yet surface a UI to reach it) — documented known limitation, not a
silent gap.

`InspectionRequest` + `InspectionResult` (pass/fail/conditional) and `Ncr` (Non-Conformance
Report). An `Ncr` raises a real `CorrectiveAction` via the SAME polymorphic `morphMany` CAPA pattern
`SafetyObservation`/`HseInspection`/`Incident` already use — explicitly reusing, not duplicating,
the corrective-action system.

**Known bug caught and fixed before shipping**: `NcrController::store()` originally derived
`company_id` via `Company::query()->value('id')` (picks whichever company happens to be first in the
table) instead of a validated, explicitly-selected field — any tenant's NCR could silently land on
the wrong company. Fixed by adding `'company_id' => ['required', Rule::in($tenantCompanyIds)]` to
validation and passing the tenant's company list to the create form.

### Contractor Management

**Department:** HSE (its `canManageContractors()` gate reuses the HSE operational role; no
standalone department was warranted for this alone). `Contractor` + `ContractorWorker`, with
document/expiry tracking (`contractor_documents`, expiry-date columns checked for the "expiring
soon" surfacing the spec asked for) and an approval step (`reviewApproval()`) before a contractor is
usable elsewhere. **Deliberately a separate table from `Vendor`** despite conceptual overlap —
Vendor is Procurement's goods/quotations counterpart; Contractor is HSE's labor/workforce
counterpart (workers, inductions, HSE compliance documents), and the two are never meant to merge.

### Visitor Management

**Department:** HSE (`canManageVisitors()`, same reasoning as Contractor above). `Visitor` —
registration → approval → HSE induction toggle → check-in → check-out, all real workflow steps with
their own timestamped columns rather than a single generic status field, since each step has
different actors (host employee, HSE approver, gate/security). No QR code or physical pass
generation — audited as out of scope for this phase ("if existing infrastructure allows" in the
spec; none currently does), documented as a known limitation.

### Document Control Foundation

**Department:** HSE (`canManageDocuments()`, same reasoning as Contractor/Visitor above).
`ControlledDocument` — a document REGISTER with version history (`storeVersion()`) and a lifecycle
(`Draft → Review → Approved → Effective → Obsolete`) via `HasWorkflow`. **Deliberately distinct from
`DocumentTemplate`** (Milestone 3's PDF-generation template engine, used to produce PDFs from
structured data) — `ControlledDocument` tracks arbitrary uploaded files (SOPs, permits, external
certificates) through an approval/version lifecycle; it reuses the existing `Storage::disk('public')`
upload architecture rather than building a new file storage system.

### Dashboard Integration

The global `DashboardController` gained six new cross-department widgets this phase
(`openIncidentsCount`, `openCapaCount`, `pendingProcurementCount`, `stockAlertCount`, `assetCount`,
`maintenanceDueCount`), all scoped via the existing `DashboardStatsService::resolveCompanyIds()`
helper. `AssetController::index()` and `WorkOrderController::index()` each gained their own
department-dashboard-style widgets (Inspection Due, Open/Overdue Work Orders).

**Two pre-existing tenant-scoping leaks found and fixed while extending dashboards this phase**:
`LogisticsDashboardController` and `ProjectManagementDashboardController` had zero company filtering
anywhere in their queries (every tenant saw every other tenant's logistics/project data) — both
rewritten to use `DashboardStatsService::resolveCompanyIds(null)`, the same helper every other
dashboard controller already used correctly.

## Final Industrial Module Completion Pass (v1.10.5)

A consolidated navigation/access/security integration pass across everything Milestone 4 built
(Workstreams A/B/C and Acceleration Mode). This was explicitly a "connect what already exists" pass,
not a rebuild — see each item below for what changed and, just as importantly, what was deliberately
left as-is because the existing embedded UX was already reasonable.

**Navigation fixes:**
- Removed a stale, disabled "Permit To Work" placeholder from the HSE department nav
  (`resources/js/lib/workspaces.js`) that sat directly above the real, working PTW entry Workstream
  B6 later added — the two together read as "PTW is locked" when only the leftover placeholder was.
  There is now exactly one PTW nav entry.
- Project `Show.jsx` gained an "Activities" button linking to the already-existing
  `projects.activities` page (`ProjectActivity`, Acceleration Part 3) — previously real backend with
  no UI path to reach it at all. This closes the "Project Form/Show UI not updated to surface
  Activities" known limitation from the earlier Acceleration Mode report.

**Intentionally embedded, not standalone (documented per this same pass's own instruction not to
duplicate logic just to produce a menu item matching some target tree):**
- **Asset Transactions / Asset Inspections** live inside `Assets/Show.jsx` — Assign/Transfer/
  Inspect/Change Status are all one-click actions in the page header, and the resulting
  `AssetTransaction` rows (including inspections) render in one combined "Transaction History" list
  on the same page. Judged sufficient: every action is a visible, labeled button, not a hidden
  gesture; nothing here needed a standalone page.
- **Contractor Workers / Contractor Documents** live inside `Contractors/Show.jsx` as their own
  labeled Workers/Documents cards, each with full add/update/remove — same reasoning.

**RBAC / access-control hardening:**
- `config/departments.php`'s `hse` array was stale (only `ppe`, `incidents`, `kpi-input`,
  `kpi-records`, `hse` — predating Workstream B entirely). It's now exhaustive across every route
  prefix in `routes/web.php`, cross-checked directly rather than assumed, and every department's own
  array was similarly completed (`logistics` gained `items`/`warehouses`/`stock`, `asset-management`
  gained `assets`, `maintenance` gained `maintenance-requests`/`work-orders`, `quality-control`
  gained `inspection-requests`/`ncrs`, `hr` gained the Shift/Roster/Competency prefixes,
  `reports`/`administration` gained `analytics`/`report-center`/`activity-center`).
- `App\Http\Middleware\RestrictDepartmentAccess` used to **fail open** for any route-name prefix not
  found in `config/departments.php` ("the map is a curated allow-list, not exhaustive, so an
  unmapped route is more likely an oversight than something to lock down"). With the map now treated
  as exhaustive, this flipped to **fail closed**: an unmapped prefix is denied for a Department User
  unless it's in the middleware's own small `UNIVERSAL_PREFIXES` list (dashboard, home, work-center,
  approvals, notifications, search, login/logout/password). Concretely, this closes a real gap where
  every HSE route added during Workstream B (Safety Observation, HSE Inspection, HIRADC, JSA, PTW,
  LOTO, TBM, CAPA, Contractor, Visitor, Document Control) was reachable by direct URL from a
  Department User assigned to *any other* department, not just HSE — the config map had simply gone
  stale, and "unmapped" silently meant "unrestricted." Administrators (`department_key = null`) are
  entirely untouched by any of this, exactly as before.

**HSE "locked" investigation — root cause and what could/couldn't be fixed from code:**
Traced the full chain (workspace catalog → tenant grant → department config → middleware → RBAC →
routes → controllers → frontend). Two candidate causes were identified with equal code-level
confidence, and this pass could only act on one of them:
1. The stale `config/departments.php` + fail-open middleware (above) — **fixed** in this pass.
2. A missing or revoked `tenant_workspaces` grant for the `hse` key
   (`App\Http\Middleware\HandleInertiaRequests`'s `workspace_catalog` prop forces `is_active: false`
   for any workspace key not present in `tenant_workspaces` for the current tenant, which hides the
   entire department from the selector — see `PlatformController::updateWorkspaces()`'s `sync()`).
   **This is live tenant data, not code, and no database access was available to confirm or correct
   it from this pass.** If HSE is still missing from the Department Selector after this deploy, the
   safe remediation is re-running the existing, idempotent `TenantGrantSeeder` for the affected
   tenant (`syncWithoutDetaching`, never destructive) — **not** a manual database edit. This is
   documented here rather than acted on, per this pass's own explicit "do not modify production data
   manually" constraint.

**Security fixes (tenant isolation / IDOR):** see `docs/CONVENTIONS.md`'s Migrations pitfalls list
for the full writeup — summarized here: `CompetencyTypeController`/`ShiftController`/
`RosterPatternController` gained the missing `assertInCurrentTenant()` guard on `update()`/
`destroy()`; `EmployeeController` gained the same guard on `show()`/`edit()`/`update()`/`destroy()`
(previously **none** of these four checked the route-bound `$employee`'s tenant at all — only a role
check); `EmployeeController::index()` and `EmployeeExport` both gained a base tenant `whereIn` they
were previously missing entirely (omitting the optional `?company_id=` filter returned/exported
**every tenant's** employee roster, not just the current one — the single most severe finding in
this pass); and `StoreEmployeeRequest`/`UpdateEmployeeRequest` had their raw `exists:companies,id`/
`exists:departments,id`/`exists:positions,id` rules replaced with tenant-scoped `Rule::in()`, the
same IDOR guard every Milestone 4 FormRequest already uses.

**Known limitation, explicitly not fixed in this pass:** roughly 20 other pre-existing FormRequests/
controllers across the *original* (pre-Milestone-4) codebase still use raw `exists:` validation for
tenant-owned foreign keys (Project, MaterialRequest, KpiCategory, KpiRecord, Task, DailyReport,
LeaveRequest, Milestone, PPE, Settings' Department/Position creation) — the same class of bug just
fixed for Employee, at a scale too large to safely rewrite and verify (no test runner available) in
one pass without materially raising regression risk across the entire original application. Flagged
as a dedicated follow-up rather than silently expanded into this session's scope.

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

**Warehouse, Finance** remain Coming-Soon as of this phase (Warehouse deliberately, since its real
functionality lives inside Logistics/PPIC per explicit instruction, not split out; Finance has no
real module yet). **Procurement** (Workstream C), **Asset Management**, **Maintenance**, and
**Quality Control** (all three: Acceleration Mode) have since graduated from this placeholder list
to real departments with real modules — see their own sections above. Coming-Soon departments each
get one real link to a shared `ComingSoon` page (`app/Http/Controllers/ComingSoonController.php`,
`resources/js/Pages/ComingSoon.jsx`) rather than a disabled sidebar row — there's genuinely somewhere
to go now, even though no real module exists yet. Each department gets its OWN route name
(`{department-key}.coming-soon`, e.g. `warehouse.coming-soon`) pointing at the same controller/page —
not one shared route name — because `workspaces.js`'s active-department detection keys off the
route-name prefix; several items sharing one route name would all collide onto whichever department
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

## Global Calendar — the ONE Calendar Engine (v1.11.0, revised v1.11.2)

**Not a department** — pinned in the top bar next to Dashboard (`CalendarController`, route
`calendar.index`), reachable regardless of which department is active, and still the full unfiltered
view of every event. Aggregates two kinds of events: real, editable `CalendarEvent` rows (manual
events — title/description/start/end/all_day/event_type/department_key/responsible_user/
`is_management_event`, tenant-owned via `company_id`) and **read-only "virtual" events** computed
live from other modules' own existing due-date fields — Leave (`start_date`/`end_date`), Permit To
Work (`start_datetime`/`end_datetime`), TBM (`meeting_date`), Milestone (`target_date`), Work Order
(`planned_date`). No second events table per module was created — each source is queried live via
the same tenant-safe `Company::query()->pluck('id')` pattern used everywhere else. Deliberately
excludes sources with no single unambiguous date (e.g. Gas Test — a reading, not a scheduled event)
rather than forcing one in. Frontend: `Calendar/Index.jsx`, month grid + agenda view built with
`date-fns` (already a dependency — no new library added). `RestrictDepartmentAccess`'s
`UNIVERSAL_PREFIXES` includes `calendar` (cross-department by design, owned by none of them).

**v1.11.2 (Final Completion Pass, Part 2/3/4/5) — Management Calendar vs Department Calendar split.**
The aggregation itself was extracted out of `CalendarController` into `App\Services\CalendarService`
(`aggregate()`) so there is exactly ONE engine behind three views, not three copies of the same
five-source query:

- **`CalendarService::managementEvents()`** — powers the Main Dashboard's "Management Calendar"
  widget. Shows manual events an authorized manager/admin explicitly flagged
  `is_management_event = true` ("Show on Management Calendar" checkbox in the create/edit dialog,
  only rendered when `can.markManagement` is true), UNION a small fixed set of virtual sources that
  are inherently cross-department significant regardless of any flag (Permit To Work, Milestone —
  the same two sources the pre-`v1.11.2` Dashboard widget already surfaced, kept rather than
  silently narrowed). The Dashboard is NOT a dumping ground for every department's operational
  events — only what's explicitly promoted, plus those two always-relevant sources.
- **`CalendarService::departmentEvents($companyIds, $departmentKey)`** — powers the
  `DepartmentCalendarWidget` shared component now on the HR/HSE/Project Management/Logistics/
  Procurement Overview pages (`resources/js/Components/shared/DepartmentCalendarWidget.jsx`, one
  component reused by all five, not five variants). Filters the same aggregate to events already
  carrying that department's `department_key` (both manual and virtual — the virtual providers
  already stamped a `department_key` per source: leave→hr, ptw/tbm→hse, milestone→project-
  management, work-order→maintenance). Logistics and Procurement have no virtual source of their own
  yet, so their widget currently only shows manual events explicitly tagged with that department —
  correctly empty otherwise, not fabricated.

**Calendar RBAC (v1.11.2, Part 5)**: CREATE/EDIT of a manual event stays open to any authenticated
tenant user (still primarily a lightweight scheduling tool at that level) — VIEW is implicitly
everyone's via the full Calendar page and the two widgets. Only Super Admin / HSE (`isAdmin()`) /
Manager (`isManager()`) may set `is_management_event` (`CalendarController::canSetManagementFlag()`)
— reuses the existing role system, no new role concept added. An unauthorized user's edit silently
preserves whatever the flag already was rather than erroring the whole edit. DELETE stays restricted
to the event's own tenant via `assertInCurrentTenant()`, the same 404-not-403 ownership pattern used
throughout this codebase.

**Migration**: `2026_08_26_100113_add_is_management_event_to_calendar_events_table.php` — one
additive boolean column, default `false`, so every existing manual event starts off the Management
Calendar until explicitly opted in. No new table — still one Calendar Engine. (Originally filed as
`2026_08_15_100113` and renamed after a production deploy caught it sorting — and therefore
running — before `2026_08_24_100111_create_calendar_events_table`, the migration that creates the
table it alters; see the "migration filename ordering" pitfall in `docs/CONVENTIONS.md`.)

## SaaS / Licensing / Subscription / Billing (v1.11.0, SaaS Finalization Pass)

**Extends, does not duplicate**, the Milestone 2 `Package`/`Subscription` models (`Package` = Plan/
Edition, `Subscription` = the commercial-access record per Tenant) and the Milestone 3
`tenant_modules`/`tenant_workspaces` grant tables (which module/workspace keys a tenant may use at
all). What was added:

- **`Subscription.type`** (trial / subscription / lifetime) — previously only `status` existed,
  conflating "what kind of commercial arrangement" with "is it usable right now". A lifetime record
  has `ends_at`/`trial_ends_at` forced null server-side and `Subscription::isExpired()` always
  returns false for it — perpetual usage rights to the purchased edition, deliberately generic (no
  tenant is ever hardcoded as lifetime in application code). `seat_limit`, `license_key`,
  `billing_reference`, `notes`, `created_by` also added (all nullable, additive).
- **`Invoice`** (genuinely new — confirmed via search that no billing/invoice concept existed
  anywhere before this) — one row per billing document, tenant-owned, optionally linked to the
  Subscription it bills for. No payment gateway integration exists; `markPaid()` is the ONLY way
  `status` becomes `'paid'`, always an explicit Platform Admin action recording a payment that
  happened outside this system, never a fabricated confirmation.
- **`EntitlementService`** (`app/Services/EntitlementService.php`) — the single new authority for
  "is this tenant's subscription usable right now". Deliberately does NOT reimplement
  `Tenant::modules()`/`workspaces()` (existing grant mechanism) or `RestrictDepartmentAccess`
  (existing department scope) — it composes with them: `tenant entitlement AND module/workspace
  grant AND department scope AND role capability = access`.
- **`EnforceTenantEntitlement`** middleware — the backend, direct-URL-safe enforcement of the above,
  registered globally. **v1.11.1: `Subscription::isBlocked()`/`isDegraded()` split the previous
  single "usable" check into two** — blocked (hard 403) ONLY for an explicit `suspended`/`cancelled`
  status (always a deliberate Platform Admin action); degraded (a warning banner in Settings →
  Subscription, never a block) for expired-by-date or a completely missing Subscription row, which
  are far more likely to be stale/unconfigured data than an actual delinquent tenant. This is what
  made it safe to flip `config('saas.enforce_entitlement')`'s default to `true` this pass — a stale
  seeded `Subscription.ends_at` can no longer lock anyone out; only an explicit suspend/cancel can.
  Still overridable per-install via `SAAS_ENFORCE_ENTITLEMENT=false` in `.env`.
- **Platform Admin console** (`/platform`): `Plans` (new page — `Package` previously had no
  create/edit UI at all, only a read-only dropdown), and `TenantDetail`'s Subscription card is now
  editable (type/status/seats/license key/dates) with an Invoices card (issue invoice, mark paid).
- **Tenant Admin view**: Settings → Subscription tab (read-only — changing it stays Platform Admin-
  only) shows plan/type/status/dates/seats/invoices; explicitly renders "Lifetime License -- no
  expiry" rather than a misleading renewal date when `type === 'lifetime'`.

**Known limitation, explicitly not built this pass**: no payment gateway is connected (by design —
the architecture is provider-agnostic; a future gateway integration would call `Invoice::markPaid()`
the same way a Platform Admin does manually today, no core rewrite needed). Frontend navigation does
NOT yet hide a workspace based on entitlement status (only the backend middleware, itself gated off
by default, enforces it) — flagged as a follow-up, not silently claimed done.

### SaaS Productization / Pricing Foundation (v2.14.0)

Turns the above architecture into an actual Plan/Pricing product surface, without touching payment
processing. Full reasoning in `docs/ARCHITECTURE.md`'s "SaaS Productization: Plan/Pricing Foundation"
section — summary here:

- **`Package` confirmed as the canonical Plan entity** (no second `plans` table) — one migration
  added the only four genuinely-missing fields: `currency`, `trial_days`, `is_public`, `is_custom`.
- **`App\Services\PricingService`** (new) — the single place a `Package` row becomes
  `{amount, currency, formatted}` display data. No component hardcodes a price; `is_custom` plans show
  `"Hubungi Kami"`, never a fabricated number. Both the new tenant-facing Plans page and the existing
  Platform Admin Plans CRUD page read from it.
- **New tenant-facing page**: `Subscription/Plans.jsx`, route `subscription.plans`
  (`SettingsController::plans()`) — monthly/yearly toggle, plan comparison with real
  Workspace/Module labels per plan (via `Package::defaultWorkspaceKeys()`/`defaultModuleKeys()`, the
  same mapping tenant provisioning already uses), current-plan highlight. Open to every authenticated
  tenant user (not gated to Super Admin/HSE — see that route's own comment in `routes/web.php` for a
  route-group placement mistake caught and fixed during this same pass, documented in
  `docs/CONVENTIONS.md`). No payment/checkout action anywhere on it — a disabled "Hubungi
  Administrator untuk Upgrade" button only.
- **Trial**: `Package.trial_days` is the missing per-Plan input the existing `Subscription` trial
  state machine needed; `PlatformController::updateSubscription()` derives a blank `trial_ends_at`
  from it when status is set to `trial`. No automatic expiry cron was added — unchanged from v1.11.1's
  deliberate "expiry stays a computed, non-blocking degraded state" design above.
- **Explicitly NOT built this pass** (next phase's scope): final pricing decision (existing seeded
  monthly/yearly numbers were left untouched, not treated as final), checkout, payment gateway wiring
  (the interface/tables above remain unwired), upgrade/downgrade self-service (still a Platform Admin
  edit), invoices beyond the existing manual issue/mark-paid flow.

## HSE Operational Equipment (v1.11.1, Final Production Readiness Pass)

**Department:** HSE. `SafetyEquipment` (Workstream B10) already existed and was reused, not
duplicated — fixed-location operational equipment (name/type/location/serial_number/status +
`is_overdue` computed accessor). Two gaps closed:

- **`HseEquipmentType`** — a configurable master (mirrors `HazardCategory` exactly:
  company_id/name/code/description/is_active/sort_order), replacing the old hardcoded
  `SafetyEquipment::TYPES` PHP array. Seeded with the same codes every existing row already used
  (fire_extinguisher, safety_shower, eyewash_station, emergency_alarm, spill_kit, other) plus the
  newly-requested operational categories (handheld_radio/HT, gas_detector, blower, public_address/
  TOA) — fully backward compatible, no existing `SafetyEquipment.type` value was invalidated.
  `type` itself stays a plain string column (not an FK) — additive, zero data migration needed.
- **`SafetyEquipmentInspection`** — real inspection history (mirrors `GasTestRecord`'s own
  "individually meaningful, time-series" reasoning), recorded via
  `SafetyEquipmentController::recordInspection()`. The parent `SafetyEquipment.last_inspection_date`/
  `next_inspection_due` stay in sync (existing overdue queries/widgets already read those two columns
  directly — kept, not replaced, to avoid rewriting every consumer to join the new child table).

**v1.11.2 update — Equipment Types management UI completed.** The prior pass's stated limitation
("no frontend UI yet to add a brand-new type beyond the seed") is closed: `EquipmentTypesSection`
in `resources/js/Pages/Hse/Master.jsx` (reachable from HSE → Master Data → Equipment Types) gives
Admins create/edit/deactivate/remove against the already-existing `hse-equipment-types.*` routes —
no backend change was needed, since `HseEquipmentTypeController` was already correctly tenant-scoped
(`Company::query()->pluck('id')` + `Rule::in()` on create, `abort_unless(...->contains(...))` on
update/destroy) and `destroy()` already refuses to delete a type with equipment referencing it. The
`code` field is editable only at creation (matches the backend's `alpha_dash`+uniqueness constraint,
since `SafetyEquipment.type` stores the code as a plain string, not an FK). New types created here
are immediately selectable in `SafetyEquipmentSection`'s own Type dropdown on the same page, since
both sections consume the same `equipmentTypes` prop from `HazardCategoryController::master()`.

## HSE Inspection Categories — LSA/FFA/PPE (v1.11.1, templates added v1.11.2)

`HseInspection` (Workstream B2) already supported a configurable `checklist_items` JSON column per
inspection — audited first and confirmed this already satisfies "Inspection Category → Checklist →
Result → Findings" without any new architecture. `HseInspection::TYPES` lists `lsa`
(Life Saving Appliances) and `ffa` (Fire Fighting Appliances) as explicit categories alongside the
pre-existing `ppe` and `fire_safety` (kept unchanged, not renamed, so no existing inspection record
is affected).

**v1.11.2 (Final Completion Pass, Part 9) — actual checklist TEMPLATES, not just category labels.**
`HseChecklistTemplate` (new, additive table `hse_checklist_templates`) is a named, reusable seed for
`HseInspection.checklist_items` — `category` (validated against `HseInspection::TYPES`, the exact
same source of truth the Inspection form's own type dropdown uses, so a future inspection type is
automatically a valid template category with no schema change), `name`, `items` (JSON array of
`{label}`), `is_active`, `sort_order`. This is still ONE inspection engine — a template never creates
a separate FFA/LSA/PPE table or form, it only prefills the existing one. CRUD lives on the shared HSE
Master Data page (`ChecklistTemplatesSection` in `resources/js/Pages/Hse/Master.jsx`, routes
`hse-checklist-templates.*`, mirrors `HseEquipmentTypeController`'s own tenant-safe pattern). In
`HseInspections/Form.jsx`, a "Load Template" dropdown (filtered to whichever `inspection_type` is
currently selected) replaces the current checklist rows with the template's items — a confirm prompt
guards against silently discarding a partially-filled checklist.

The owning migration seeds one default template per existing company for exactly the three
categories the spec gave example item lists for:
- **FFA**: equipment available, correct location, physical condition, safety seal/pin, pressure
  gauge, hose/nozzle condition, signage, inspection tag.
- **LSA**: availability, correct storage location, physical condition, accessibility, identification/
  marking.
- **PPE**: helmet, safety shoes, gloves, safety glasses, coverall.

Additive only — never overwrites a company's own later edits (a per-company-per-category existence
check guards the seed insert).

## Calendar widgets on the Main Dashboard and Department Overviews (v1.11.1, revised v1.11.2)

See the "Global Calendar — the ONE Calendar Engine" section above for the full Management vs
Department Calendar architecture. In short: `DashboardController` shows
`CalendarService::managementEvents()` (next 14 days, capped at 8) as the Main Dashboard's Management
Calendar; HR/HSE/Project Management/Logistics/Procurement Overview pages each show
`CalendarService::departmentEvents($companyIds, $departmentKey)` (next 3 weeks, capped at 6) via the
shared `DepartmentCalendarWidget` component. Both are narrower views over the same aggregation the
full `Calendar/Index.jsx` page uses — not duplicate calendar systems.

## Man-Power / Man-Hour (v1.11.1)

Audited first: no attendance/timesheet/clock-in table exists anywhere in this codebase, so actual
**worked man-hours cannot be reliably computed** and were NOT fabricated. What genuinely exists and
is shown on the Main Dashboard instead: `active_employees` (real headcount) and `on_shift_today`
(real count of `EmployeeShiftAssignment` rows currently in effect). Building true man-hour tracking
would require a new attendance/timesheet source — explicitly flagged as future work, not attempted
this pass.

## Overview ModuleCard rollout (v1.11.2, Final Completion Pass Part 1)

The `ModuleCard` grid pattern (previously HSE-only, per the prior pass's explicit note) is now on
every department Overview page that actually has one: HR, HSE, Project Management, Logistics,
Procurement (`HR_MODULES`/`PM_MODULES`/`LOGISTICS_MODULES`/`PROCUREMENT_MODULES` constant arrays in
each `Dashboard.jsx`, same shape as HSE's own `HSE_MODULES`). Departments with **no dedicated
Overview/Dashboard page at all** — Warehouse, Asset Management, Maintenance, Quality Control,
Finance, Contractor, Visitor, Document Control, Reports, Administration — were deliberately NOT given
a new Overview page just to host a card grid ("do not invent modules"); they still link back to the
global Dashboard as before. Building a real Overview for any of them is future work, not silently
done here.

## v1.11.2 security fixes (Final Completion Pass, Part 19)

Two confirmed, real issues found and fixed while extending the department dashboards/routes above —
not merely listed:

- **`HrDashboardController` had zero company/tenant scoping** — every query (`Employee::count()`,
  `LeaveRequest::where(...)`, `KpiRecord::forPeriod(...)`, etc.) queried across every tenant in the
  database, unlike its 4 sibling department dashboard controllers (Hse/ProjectManagement/Logistics/
  Procurement), all already fixed for the exact same bug class in earlier passes. Fixed the same way:
  `DashboardStatsService::resolveCompanyIds()`, not a new copy of the scoping logic.
- **`config/departments.php` was missing `hse-equipment-types` from the `hse` department's route
  prefix list** — since `RestrictDepartmentAccess` fails CLOSED for any prefix not in the map (see
  that middleware's own v1.10.5 doc comment), an HSE Department User (`department_key = 'hse'`) would
  have gotten a 403 trying to use the Equipment Types feature, despite it being an HSE-only page.
  Added, along with the new `hse-checklist-templates` prefix for this same pass's new routes.

## Global Dashboard/Overview + HSE Master Data UX Rework (v1.11.3)

A UI/IA pass, not a new-feature pass (except the three brand-new department Overviews below) — same
controllers, routes, services, RBAC, and tenant scoping throughout; only the *visual system* changed.
Audited first (3 parallel read-only agents covering every dashboard controller/page, the HSE Master
Data architecture, and the Calendar Engine) before any edit — see the plan file this pass was executed
from for the full audit findings.

**Shared component tightening**: `StatCard` shrunk from `p-5`/`h-11 w-11` icon to `p-3.5`/`h-9 w-9`,
matching `ModuleCard`'s already-established scale exactly (StatCard used to be visually LARGER than
the module-shortcut grid beneath it, backwards from the intended hierarchy). `PageHeader` converged
from `text-2xl` to `text-lg`, resolving three different header type scales that were simultaneously in
use across the app (this component's own former size, PPE/Platform/Rosters' hand-rolled `text-lg`,
Main Dashboard's hand-rolled `text-base` hero). New `DashboardShell` (PageHeader + consistent spacing
wrapper) and `ActivityList` (generalizes the "divide-y list + empty state" pattern that was hand-rolled
8+ times across dashboards) added to `Components/shared/`. Unlike `StatCard`'s own prior doc comment
("consolidating the three existing implementations is a separate follow-up, not bundled here" — which
never happened), this pass adopts the tightened components into every dashboard in the SAME pass.

**Main Dashboard**: swapped its local `PrimaryCard` for the shared `StatCard` (verified drop-in safe —
`PrimaryCard` was already at the same compact scale, and every call site uses named props, so removing
it and pointing at `StatCard` needed no call-site changes). Its hand-duplicated "Management Calendar"
markup (byte-for-byte the same shape as `DepartmentCalendarWidget`) now imports and uses that shared
component instead — the one real Calendar-side duplication the audit found; `CalendarService` itself
needed zero changes, it was already correctly split into `managementEvents()`/`departmentEvents()`.

**HSE Master Data** (`Hse/Master.jsx`): was one 756-line page with six CRUD sections stacked flat, no
grouping — confusing as "one long unrelated CRUD page" per explicit feedback. Regrouped into 4 tabs
based on the *actual* data relationships (audited, not assumed to match the initially-proposed
grouping): **Safety Equipment** (Equipment Types → Safety Equipment Register → Inspection History, a
strict producer/consumer chain), **Inspection Templates** (Checklist Templates only — already a fully
working feature before this pass, just visually unseparated from "actual inspection record"),
**Hazard Categories** (its own tab — feeds Safety Observations only, not folded into "risk references"
broadly since it has no relation to RiskAssessment/JSA), **HSE Supplies & Facilities** (HSE Materials +
P3K Boxes). Client-side, single-route tabs (`?tab=` mirrored via plain history API, no Inertia
round-trip) — not `ModuleTabNav`'s route-per-tab pattern, since all 6 sections still share one
efficient, N+1-free controller call (`HazardCategoryController::master()`); every section component is
UNCHANGED internally, only moved and grouped. Hazard Categories' inline CRUD block (previously the only
section not following the named-component pattern) was extracted into its own `HazardCategoriesSection`
component for consistency with the other five.

**Safety Equipment ↔ Asset**: kept as two separate systems (`SafetyEquipment` is HSE's own
inspection-cadence register; `Asset` is the general company-asset ledger — different operational
rhythms, forcing a merge would lose one side's shape or bolt HSE fields onto every non-HSE asset).
The nullable `SafetyEquipment.asset_id` FK (added `2026_08_22_100094`, fully wired at the model layer
both directions since then) was scaffolded but never exposed in any form or validation array — now
surfaced as an optional "Link to Company Asset" `Select` on the Safety Equipment form, and added to
`SafetyEquipmentController::store()`/`update()`'s validation (`nullable`, tenant-scoped `Rule::in()`).
Purely additive; every existing `SafetyEquipment` row keeps working with no asset link at all.

**Three new department Overviews** — Asset Management, Maintenance, Quality Control previously had NO
Overview route at all (confirmed via audit: no `'Overview'` item in `workspaces.js` for any of the
three). Built following the exact existing dashboard-controller pattern (constructor-inject
`DashboardStatsService` + `CalendarService`, `resolveCompanyIds(null)`, tenant-scoped queries) and
using the shared component set from day one (`DashboardShell`/`StatCard`/`ModuleCard`/`ActivityList`/
`DepartmentCalendarWidget` — no legacy pattern to inherit since there was no prior page):

- `AssetDashboardController` / `Assets/Dashboard.jsx` (`asset-management.dashboard`) — KPIs from real
  `Asset` data only (counts by status, category breakdown). No fabricated "utilization %" or similar
  without a real source.
- `MaintenanceDashboardController` / `Maintenance/Dashboard.jsx` (`maintenance.dashboard`) — KPIs from
  `WorkOrder`/`MaintenanceRequest`. Its Department Calendar is genuine reuse, not new plumbing:
  `CalendarService::provideWorkOrders()` already stamps `department_key = 'maintenance'` on every
  WorkOrder virtual event, so `departmentEvents($companyIds, 'maintenance')` surfaces real
  planned-maintenance dates with zero new Calendar Engine code.
- `QualityControlDashboardController` / `QualityControl/Dashboard.jsx` (`quality-control.dashboard`)
  — KPIs from `InspectionRequest`/`Ncr` (Milestone 4, Acceleration Part 3 — QC Foundation).

Warehouse (`warehouses.master`, intentionally folded into Logistics per existing design) and Finance
(`ComingSoon.jsx`, no backing models exist at all) were deliberately left untouched — building a
Finance Overview would necessarily show fabricated data, which this project's own rules forbid.

**PPE Dashboard**: swapped its local `StatCard` clone for the shared one (same named-prop shape,
verified drop-in safe the same way as Main Dashboard's swap) and adopted `PageHeader` — closes the
last dashboard-level component-duplication the audit found.

**Not changed**: Platform Dashboard (different audience/layout, SaaS admin surface — out of scope),
`Rosters/Overview` (a filtered table, not a stat dashboard — out of scope). No migration, no RBAC/
middleware change, no tenant-scoping logic change — every new query uses the same
`resolveCompanyIds()`/`whereIn('company_id', ...)` pattern already used everywhere else.
`config('saas.enforce_entitlement')` untouched.

## Department Dashboard UX Completion (v1.11.5)

**Design principle** (the reason this pass exists): **Department Dashboard = operational control
center. Main Dashboard = management/company-wide overview.** A department Overview should answer "what
do I need to know or act on right now?", not "what collection of database statistics can I display?" —
cards are supporting containers for information that benefits from grouping, not the entire page
structure. The Main Dashboard stays the cross-department summary location and was not redesigned this
pass; each department Overview below was restructured into an explicit information hierarchy (compact
KPI strip → primary operational area → action/attention area → secondary information → activity +
calendar) using the existing shared component set (`DashboardShell`/`StatCard`/`ModuleCard`/
`ActivityList`/`StatusBadge`/`DepartmentCalendarWidget`) — no new shared components were needed, only
applied more deliberately than before. Every KPI and list row traces to a real, tenant-scoped query;
nothing fabricated, no fake charts.

**HSE** (`HseDashboardController` / `Hse/Dashboard.jsx`): KPI strip trimmed to the 6 numbers that
actually drive action (Open Incidents, Critical Incidents, Open Observations, Open CAPA, Active PTW,
PPE Alerts). New `actionRequired` prop merges overdue Safety Equipment inspections, overdue P3K
inspections, and overdue CAPA into one date-sorted, click-through list — reusing the exact WHERE
clauses the pre-existing overdue counts already used, just also fetching rows. Recent
Incidents/Observations/Activity moved onto `ActivityList`. Waste summary tile and Calendar kept exactly
as built in v1.11.4/earlier, just given a wider column share.

**HR** (`HrDashboardController` / `Hr/Dashboard.jsx`): added `onShiftToday` (same
`EmployeeShiftAssignment` query the Main Dashboard's own Man-Power widget already used),
`contractExpiringCount`/`certificationExpiringCount`, and an `attentionRequired` list merging
`Employee.contract_end_date` and `EmployeeCompetency.expiry_date` (via its existing
`scopeEffectiveStatus('expiring_soon')`) — both real columns that a stale doc comment had previously
and incorrectly implied didn't exist. Workforce Status panel added alongside Attention Required.

**Project Management** (`ProjectManagementDashboardController` / `ProjectManagement/Dashboard.jsx`):
new `projectPortfolio` dataset — one row per active/planned project with its manager
(`Project::manager()`), a per-project milestone-completion percentage (same formula as the department-
wide aggregate, computed per project), and its nearest open milestone — rendered as a compact TABLE
(Project/Manager/Status/Progress/Next Milestone/Due) with an inline progress bar, not a grid of project
cards. Milestone Control and Delayed Projects moved onto `ActivityList`.

**Logistics/PPIC** (`LogisticsDashboardController` / `Logistics/Dashboard.jsx`): new `materialFlow`
prop visualizes the Material Request → Procurement → Purchase Order → Goods Receipt → Warehouse
pipeline as five real stage counts (Procurement/PO counts reuse `PurchaseRequisition`/`PurchaseOrder`
status constants already proven correct in `ProcurementDashboardController` — no new workflow logic),
so PPIC can see at a glance which stage material is backing up in.

**Warehouse** (`WarehouseDashboardController` / `Warehouses/Dashboard.jsx`): new `inventoryHealth`
dataset — a compact TABLE (Item/Category/Location/Current Stock/Min-Reorder/Status), sorted by
quantity ascending, status derived from the same `quantity`/`min_stock` thresholds `lowStockCount`/
`outOfStockCount` already used (out of stock / critical / low / healthy). Existing Low Stock/Receiving/
Issuing/Calendar sections kept as built in the prior Warehouse Overview pass (v1.11.3.2).

**RBAC/tenant isolation**: no middleware, `config/departments.php`, or query-scoping pattern changed —
every new/extended query in this pass uses the same `DashboardStatsService::resolveCompanyIds(null)`
+ `whereIn('company_id', ...)` pattern already audited as correct everywhere else. No new
cross-department route links were introduced (pre-existing PM→Quality-Control ModuleCard links were
left as found, out of this pass's scope). No local MySQL server was available in this environment (as
in every prior pass this session) — verification below is `php -l` + `route:list` + `npm run build`,
not a live browser click-through; stated plainly, not claimed otherwise.

## HSE Waste Management (v1.11.4)

New HSE module, confirmed via repo-wide audit to not exist in any form before this pass. Built around
the requested lifecycle: **Generated → Stored → Scheduled for Pickup → In Transit → Disposed →
Closed** — guarded by `WasteRecord::ALLOWED_TRANSITIONS` (mirrors `WorkOrder`/`MaintenanceRequest`'s
own transition-guard pattern; invalid transitions are rejected server-side, not left to the UI).

**Tables** (all additive, `Schema::createIfMissing`, verified zero migration-ordering violations via
the same audit script used for prior incidents): `waste_types`, `waste_storage_locations`,
`waste_records`, `waste_movements`, `waste_movement_documents`, plus one additive column —
`vendors.is_waste_vendor` (boolean, default false) — **not** a new vendor table; explicit instruction
was "Do NOT create WasteVendor as a duplicate vendor master."

**Reuse, not duplication**:
- Numbering: `NumberGeneratorService`'s existing `waste_record` DEFAULTS entry (`HSE-WST-{YEAR}-{SEQ}`)
  — no new numbering engine.
- Source: `WasteRecord.project_id`/`project_activity_id` are nullable FKs to the EXISTING
  `projects`/`project_activities` tables — no parallel source/work-area system.
- Vendor: `WasteMovement.vendor_id` FKs to the EXISTING `Vendor` table, filtered by the new
  `is_waste_vendor` flag (`Vendor::scopeWasteVendors()`).
- Documents: `WasteMovementDocument` mirrors `VendorDocument`'s exact shape and conventions
  (`file_path`/`original_name`/`document_type`, `public` disk, `asset('storage/'.path)` URL accessor)
  — no new file-storage mechanism.
- Storage register: `waste_storage_locations` is a genuinely SEPARATE table from the pre-existing
  Warehouse `storage_locations` table (2026_08_22_100088) — a deliberate non-duplication (regulated
  temporary waste holding vs. general inventory bins), not an accidental naming collision.

**Storage-limit monitoring is explicitly operational, never legal advice**: `WasteType.storage_limit_days`
is a nullable, tenant-configured integer (per waste type, since different waste categories may need
different monitoring windows). `WasteRecord::is_approaching_storage_limit`/`is_storage_overdue` are
computed accessors (never stored/cached, same "never goes stale" reasoning as `SafetyEquipment::is_overdue`)
that only activate once a threshold is actually configured — null means "no monitoring configured," never
a fabricated default. No Indonesian regulatory limit is hardcoded anywhere in this module.

**Controllers**: `WasteMasterController` (Waste Types + Storage/TPS CRUD, mirrors
`HazardCategoryController`/`WarehouseController`'s own multi-section master-data page pattern),
`WasteRecordController` (index/create/store/show/transition), `WasteMovementController` (records a
movement + optional document upload, and keeps the parent `WasteRecord.status` in sync — same
denormalized-parent-status pattern as `SafetyEquipmentController::recordInspection()`),
`WasteDashboardController` (real, tenant-scoped KPIs only — B3/Non-B3 stored counts, awaiting
pickup/in transit, disposed, storage alerts — nothing fabricated).

**Frontend**: `Hse/WasteMaster.jsx`, `Hse/WasteManagement/{Index,Form,Show,Dashboard}.jsx`. HSE
Overview (`Hse/Dashboard.jsx`) gained a compact click-through Waste summary tile (B3/Non-B3
stored/storage alerts/pending disposal counts only — explicit instruction: "Do NOT turn it into
another huge card") and a "Waste Management" `ModuleCard`/sidebar item, placed after Corrective
Actions (CAPA) per the requested navigation position.

**RBAC**: every `waste*` route-name prefix (`waste`, `waste-records`, `waste-movements`, `waste-types`,
`waste-storage-locations`) was added to `config/departments.php`'s `hse` array — verified
programmatically (not just by inspection) that all five resolve to `hse` before this pass was
committed. `RestrictDepartmentAccess` itself was not modified. Write operations are gated by the
existing `canManageHse()` check inside each controller, matching every other HSE module's pattern.

### Waste Container Inventory (v2.3.0)

A second, genuinely separate concept added alongside the above: **physical container/equipment
stock** (drums, IBC tanks, jumbo bags) tracked as total/available/in_use/damaged counts — e.g. "30
drums total, 23 available, 5 in use, 2 damaged." This is deliberately **not** the same thing as
`WasteRecord` (actual waste material, tracked by weight/volume — "1,200 Liter used oil") or
`WasteStorageLocation` (a TPS/storage *place* register). Confirmed via a full audit before building
anything that no existing table/model (`WasteStorageLocation`, `Asset`, `Item`/`Stock`, `EmployeePpe`/
`PpeType`) already represents this shape — see the owning migration's own doc comment for the
per-model comparison.

**Table**: `waste_container_inventories` (additive, `Schema::createIfMissing`) —
`company_id, container_type, code?, unit, total_quantity, in_use_quantity, damaged_quantity,
capacity?, capacity_unit?, storage_location_id?, status, notes?, created_by?`. `available_quantity`
is a computed accessor (`total - in_use - damaged`, floored at 0) — never a stored column, matching
`Stock::getAvailableQuantityAttribute()`'s and `WasteRecord`'s own accessor conventions elsewhere in
this codebase. `storage_location_id` optionally FKs into the EXISTING `waste_storage_locations` table
— reused, not duplicated. No stock-movement/history table was introduced: the explicit product
instruction was "do not fabricate movement history, let the user establish the current stock state,"
so this table holds current counts only, edited directly (same as how `waste_types`/
`waste_storage_locations` themselves are edited).

**Model**: `WasteContainerInventory` — `STATUS_ACTIVE`/`STATUS_UNDER_MAINTENANCE`/`STATUS_DISPOSED`.

**Controller**: `WasteContainerController` (index/store/update/destroy), same
`abort_unless(canManageHse())` + manual `Company::pluck('id')->contains($record->company_id)` tenant
check pattern as `WasteMasterController` — not a Policy class, matching that controller's own style
for this module. Validates `in_use + damaged <= total` server-side so `available_quantity` can never
represent an impossible state.

**Frontend**: `Hse/WasteContainers.jsx` — a compact StatCard summary (Total/Available/In Use/Damaged)
+ one CRUD table, reached via a "Waste Inventory" `ModuleCard` on the existing Waste Management
dashboard (`Hse/WasteManagement/Dashboard.jsx`) — no new sidebar item (Waste Management stays the
single top-level nav entry point it already was; this is one more in-page link, same as Waste
Records/Waste Master already are).

**RBAC**: `waste-containers` added to `config/departments.php`'s `hse` array alongside the other
`waste*` prefixes.

## Production Readiness + HSE Operational Fix Pass (v1.11.6)

**Bug fixes (real root causes, not guessed)**:
- **HSE Overview HTTP 500**: `HseDashboardController`'s `actionRequired` (added v1.11.5) selected a
  `title` column on `corrective_actions` that doesn't exist — the real free-text field is `action`
  (confirmed against `2026_08_19_100064_create_corrective_actions_table`). Fixed the `get([...])`
  select and the `->map()` accessor. This is the exact "database column doesn't match code" bug class
  the project has hit before (`permits_to_work` vs `permit_to_works`) — that one was already correctly
  handled (`PermitToWork::$table` is explicitly set), it was this newly-added query that regressed.
- **HRD could not manage its own Leave Requests**: `User::canManageLeaveRequests()` checked `isHse()`
  instead of `isHrd()` — almost certainly a copy/paste from the HSE-domain permission methods
  immediately around it. `config/departments.php` scopes `leave-requests` under `hr`, confirming the
  intended gate. Fixed; `isHrd()`'s stale "read-only" doc comment corrected too.
- **PPE record edit was structurally incomplete**: the only Edit UI (`EmployeeProfile.jsx`'s
  `EditAssignmentDialog`) and its backend (`UpdateEmployeePpeRequest`) only ever handled `status`/
  `remarks` — there was no way to correct a wrong PPE type or a typo'd issue/expiry date once issued,
  short of Delete + re-Issue. Both request validation and the dialog now also handle `ppe_type_id`/
  `issued_date`/`expiry_date`. `employee_id` deliberately stays non-editable (reassignment isn't a
  correction); there is no `quantity` field to add — `employee_ppe` is already one row per physical
  item.
- **PPE filter/context loss**: navigating from the filtered Employee PPE list into an employee's
  profile dropped company/department/search/page from the URL; the profile's "Back to Employee PPE"
  link had nothing to reconstruct it from. `Employees.jsx` now carries the current filters (and page)
  into the profile URL as a query string; `PpeController::employeeProfile()` echoes them back as a
  `backUrl` prop the profile page's Back link uses. Pure URL-state, no new global/session state.
- **Tenant-isolation gap on `EmployeePpe` update/delete**: `EmployeePpe` has no `tenant_id` of its own
  and no global scope (the app's single tenant-isolation anchor, `TenantScope`, is applied only to
  `Company` — see that class's own doc comment), so `{employeePpe}` route-model-binding on
  update/destroy previously succeeded for ANY tenant's record given a guessable id; the Policy only
  ever checked the role capability. `EmployeePpePolicy::update()`/`delete()` now also confirm the
  record's employee's company still resolves (i.e. belongs to the current tenant) before allowing the
  action. `PpeController::update()` was also missing its `$this->authorize()` call entirely — added,
  matching `completeReplacement()`'s existing pattern. **This same route-model-binding gap likely
  exists on other single-record endpoints across the app** (not audited exhaustively this pass) — see
  the final report's Security section.

**Man-Hour (new, minimal, real)**: no attendance/timesheet/clock-in model existed anywhere in this
codebase — `EmployeeShiftAssignment`/`Shift` only capture *scheduled* shift membership, never actual
worked hours. `ManHourLog` (`man_hour_logs`, one row per employee per work date, `regular_hours` +
`overtime_hours` entered explicitly, `total_hours` a computed accessor never a stored column) is the
new minimal operational record the spec asked for, owned by HR (`canManageManHour()`, gated the same
way as Leave). `DashboardStatsService::sumManHours()` (shared by `DashboardController` and
`HseDashboardController`) returns `null` — not `0` — when no rows exist for a period, so an empty log
never reads as a real zero. Main Dashboard now shows Man-Power and Man-Hours as two separate cards;
HSE Overview shows Man-Hours Today/This Month/YTD plus a Safety KPI Foundation panel that
**deliberately does not compute LTI/LTIFR/TRIR/Fatality rate** — `Incident` has no lost-time-days or
recordability/fatality classification captured anywhere, so those formulas would need fabricated
inputs; the panel shows an explicit "not available" state instead ("Attendance", the sidebar's old
disabled placeholder, was replaced by the real "Man-Hour" item rather than left alongside it).

**SaaS foundation audit**: `Tenant → Company → Subscription (trial/subscription/lifetime, generic,
never hardcoded to one tenant) → Invoice` already existed from a prior Milestone 2/SaaS Finalization
pass and needed no rebuild. What did not exist: any payment-gateway integration, webhook handling, or
transaction/idempotency ledger. Added as pure architecture, not a live integration:
`App\Contracts\PaymentGatewayInterface` (`createCheckout`/`createPayment`/`getPaymentStatus`/
`verifyWebhookSignature`/`handleWebhook`/`refund`), bound by default to `NullPaymentGateway` (throws a
clear "no payment gateway is configured" on every call — never a fake success), `payment_transactions`
(one row per gateway attempt) and `payment_webhook_events` (a `(gateway, event_id)`-unique idempotency
ledger so a duplicate webhook delivery can never double-apply), `config/payment.php` (env-driven,
Indonesian-gateway-shaped — Midtrans/Xendit key names — no credentials committed). **Requires provider
configuration** before any checkout can actually run; nothing routes to this yet since there is no real
adapter to route to.

## Production Readiness Follow-Up (v1.11.7)

Finishes the four items the v1.11.6 final report explicitly listed as deferred: HSE Omni Pro UX
benchmark, HSE navigation regrouping, Bahasa Indonesia standardization, and a wider tenant-isolation
audit. No new features, no re-redesign of already-completed work (dashboards, Waste Management, SaaS
architecture).

**HSE Omni Pro UX benchmark**: reviewed as a reference only (no code/branding/design copied). Its
sidebar (Dashboard/Performa/Statistik/Employee/Manpower/Man-Hours/HSE Plan/Insiden/Inspeksi/CAPA/
Audit/HIRADC/PTW) is itself fairly flat — not deeply nested — so the useful takeaway wasn't "add more
nesting," it was: a persistent project/area filter at the top of every page (the exact pattern IOMS's
PPE filter-persistence fix already implements), a KPI strip led by "days without incident" (not adopted
— IOMS has no incident-free-day tracking and won't fabricate one), and an auto-generated one-line
insight summarizing the top KPI callout (not adopted this pass — would need a real template over
already-real data, left as a future idea, not built speculatively).

**HSE navigation regrouping** (`resources/js/lib/workspaces.js`): 18 flat items → Dashboard, Overview,
three collapsible groups (Safety Management; Permit & Work Safety; People & PPE), Waste Management
(left top-level — a 1-item group adds a click with no scanning benefit), a fourth group (HSE Control:
Master Data/Document Control/HSE KPI), and the two disabled placeholders (Training, Reports, left
top-level since the `children` renderer has no disabled-child treatment). Built entirely on a
collapsible-group mechanism (`item.children`, `AuthenticatedLayout.jsx`) that already existed in the
sidebar component but no workspace had ever used — reused, not built new. Two real bugs in that
existing-but-unused mechanism were found and fixed while wiring HSE into it (both are workspace-agnostic
fixes, not HSE-specific patches):
- A group never auto-expanded around its own active child — landing directly on a grouped page (via
  bookmark, reload, or normal navigation before ever manually toggling the group) showed the correct
  page but a collapsed, seemingly-unrelated sidebar. Fixed via `containsActiveChild` in
  `AuthenticatedLayout.jsx`.
- `applyItemGates()` (the `adminOnly`/`moduleKey` filter) and `PREFIX_TO_WORKSPACE` (the reverse
  route→workspace lookup powering active-workspace detection/breadcrumbs) both only ever iterated the
  flat top-level `items` array — nesting `HSE KPI` (`adminOnly`) or any grouped route would have
  silently stopped being gated/detected the moment it moved into a group. Both now recurse into
  `children`. Every href is otherwise byte-identical to the flat list — zero new routes, zero renamed
  routes, zero RBAC change beyond fixing this latent gap.

**HSE Master Data clarity**: kept the existing 4-tab structure and the Safety Equipment tab's inline
"Inspect" action (a deliberate producer/consumer chain — Equipment Types → Register → Inspection
History — documented in that page's own class comment from the prior pass, not an oversight). Per the
explicit "improve labels rather than restructure unnecessarily" instruction: the page subtitle now
states the master-vs-operational distinction directly, and the Safety Equipment section's own
description explicitly calls out that its "Inspect" button records a real operational event, not
configuration.

**Bahasa Indonesia standardization**: `resources/js/lib/id.js` is the terminology map created before
any page was touched, covering navigation and the five priority departments' vocabulary; established
acronyms (HSE/PPE-APD/JSA/HIRADC/PTW/LOTO/CAPA/NCR/Man-Hour/Man-Power/Work Order/Asset) kept as-is
rather than force-translated. Applied consistently to: `workspaces.js` (every nav label/item name for
HR→HRD, HSE, Project Management→Manajemen Proyek, Logistics→Logistik/PPIC, Warehouse→Gudang, plus the
shared "Dashboard"→"Dasbor" global link translated everywhere since it's the same link in every
department) and the five department Overview pages in full (headers, KPI labels, section titles, table
headers, empty states). **Not yet translated** (scope explicitly deferred, not silently dropped):
individual module CRUD forms/dialogs/validation messages across the dozens of non-Overview pages — a
much larger surface. `id.js`'s `t()` helper is shaped like a real i18n library's translate function
specifically so a genuine multi-locale need later means swapping this file's internals, not every call
site — English localization remains straightforward to add back.

**Tenant isolation audit** (targeted, not exhaustive — see the final report for exact scope): the app's
actual isolation model was confirmed to be two-tier — `TenantScope` (automatic) applies only to
`Company`; every other tenant-owned model relies on manual `abort_unless(Company::query()->pluck('id')
->contains($model->company_id), 404)` / `Rule::in($tenantCompanyIds)` checks, already applied
consistently across most of the app (Incident, SafetyObservation, HseInspection, PermitToWork,
GasTestRecord, LotoRecord, JobSafetyAnalysis, RiskAssessment, SafetyEquipment, P3kBox, WasteRecord,
WasteMovement, Milestone, PurchaseRequisition/Order, GoodsReceipt, Warehouse, Stock/StockMovement,
MaintenanceRequest, WorkOrder, Asset, Employee, Contractor, Visitor, ControlledDocument, Calendar — all
verified guarded). Two confirmed, exploitable gaps found and fixed:
- `ProjectPolicy` — same bug class as `EmployeePpePolicy` (fixed v1.11.6): `update()`/`delete()`/
  `manageManpower()` checked only the role capability, never which tenant the Project belongs to.
  `ProjectController::show()` also never called `$this->authorize()` at all despite `ProjectPolicy::
  view()` existing — any authenticated user could load any tenant's project. Both fixed.
- `LeaveRequestController::index()` had NO company/tenant scoping whatsoever — every tenant's leave
  requests were returned to every other tenant. `show()`/`cancel()` had no per-record tenant check
  either. Fixed with the same guard convention used everywhere else in the app.

Billing data (`Subscription`/`Invoice`) was confirmed already correctly isolated — `PlatformController`
is the only controller touching them, gated entirely behind `role:platform_admin` middleware, and no
tenant-facing controller references either model. `PaymentTransaction`/`PaymentWebhookEvent` (added
v1.11.6) are not yet routed to anything, so carry no exposure risk today.

## Enterprise UI/UX Visual Refinement (v1.11.8)

A visual/UX-only pass — zero PHP files touched, zero routes/migrations/RBAC changed (confirmed via
`git status`: every changed file this pass is under `resources/js/`). Scope was shared components,
sidebar typography, and semantic color across the five priority department dashboards + Main Dashboard.

**Sidebar typography root cause** (`AuthenticatedLayout.jsx`): the reported "child items feel like
headings" (e.g. "Manajemen Insiden" reading larger than its own parent "Manajemen Keselamatan") had a
concrete cause — nested `children` links rendered at `text-sm` (14px) while the parent group header
above them used `text-xs` (12px), so children were literally larger than their parent. Parent sizing
was left untouched per explicit instruction ("already relatively good... do not enlarge dropdown
parents"); children were brought down to `text-[13px]` with tighter line-height, reduced vertical
padding (`py-1.5`→`py-1`), and slightly reduced indentation (`ml-4/pl-3`→`ml-3.5/pl-2.5`). Active/hover/
expand/auto-expand behavior (the `containsActiveChild` fix from v1.11.7) is untouched.

**Terminology correction**: this pass's own instruction explicitly reverses part of v1.11.7's Bahasa
Indonesia work — "Dashboard" is preferred over "Dasbor" for that one term specifically (every other
translated term from v1.11.7 stands). Reverted in both `resources/js/lib/id.js` (the terminology
dictionary) and every `workspaces.js` nav item using it (the shared "Dashboard" link pinned atop every
department's item list).

**Semantic color system**: `StatCard` gained `green`/`purple`/`neutral` accent options alongside the
existing `red`/`amber` (the full palette this pass's brief specifies: blue=primary/general,
green=healthy/active/completed, amber=warning/pending, red=critical/overdue, purple=assets/
administration/planning, neutral=inactive/informational). Applied across Main Dashboard (Active
Projects/green, Companies+Current Period/neutral, Open Incidents/red-or-green, Open CAPA/amber-or-green,
Pending Procurement/purple, Stock Alerts/amber-or-green, Active Assets/purple, Maintenance Due/amber-
or-green) and HSE/HR/Project Management/Logistics/Warehouse Overviews (every KPI tile whose accent
previously fell back to `null`/default blue on a healthy zero-count now explicitly reads `green`
instead, so "nothing wrong right now" is visually distinct from "here's a number to look at" rather
than defaulting to the same brand blue as every informational tile).

**Not restructured this pass** (audited, found already compliant with this pass's own direction, or
explicitly out of scope): Top Navigation (already `h-14`/`text-xs` — confirmed compact, no change
needed); HSE Master Data's 4-tab structure and Safety Equipment's inline inspection action (both kept
exactly as v1.11.6/v1.11.7 left them — deliberate, documented design, not something this pass's "don't
turn it back into a giant flat page" instruction asked to change); PPE workflow, filter persistence,
Waste Management, Calendar, and every backend/RBAC/tenant-isolation mechanism (all explicitly
out-of-scope for a visual-only pass and confirmed untouched by the empty PHP diff).

## Enterprise UI/UX Visual Refinement, Part 2 (v1.11.9)

Continues v1.11.8's shared-component work with the page-wide typography pass and Main Dashboard
consolidation its own final report flagged as not yet done. Zero PHP files touched (confirmed via
`git status`), same visual-only scope.

**Page title typography, app-wide**: every hand-rolled `<h1 className="text-lg font-bold
tracking-tight text-graphite-900...">` across the app (76 files — every module's Index/Show/Master
page) was at 18px, well under this pass's own "page title ~24-28px" guideline. Fixed with a single
mechanical, safe `sed` replace to `text-2xl` (24px) across every file sharing that exact class string —
a pure Tailwind class swap, no logic touched, verified by a full `npm run build` afterward. `PageHeader`
(the shared component the same pages should eventually migrate to) got the identical bump.

**`CardTitle`/`CardDescription` — a real bug, not just under-sizing**: both had a backwards
mobile-first responsive rule (`text-[13px] lg:text-xs` and `text-xs lg:text-[11px]`) that made section
titles/descriptions get SMALLER at desktop width — the opposite of intended, and directly contradicting
this app's own stated desktop-primary design target. Fixed to one non-shrinking size each (14px/12px) —
a deliberately conservative step given `CardTitle` alone is used on nearly every `Card` in the app;
still short of the "15-17px" upper guideline, but a safe, build-verified improvement rather than a
blast-radius risk with no way to visually confirm every one of its hundreds of call sites in this
environment.

**`StatCard` KPI number size**: bumped from `text-base` (16px) to `text-xl` (20px) — the low end of
this pass's own "KPI number 20-26px" guideline. Card footprint (padding, icon size) unchanged, so the
number gains visual weight without the tile itself growing.

**Main Dashboard KPI redundancy** (the concrete example this pass's own directive was written to force
finding): `HeroSummary`'s "Today's Summary" stat row and the "Primary cards" `StatCard` grid immediately
below it both showed Employees and Active Projects — the exact same two numbers, twice, inside the
first viewport. Consolidated by removing the duplicate pair from `HeroSummary` (renamed "Today's
Alerts") and keeping only what's genuinely unique there: the greeting and the two safety-relevant
alerts (Lost Time Incidents, PPE Alerts) that appear nowhere else on the page — no information lost,
only the literal duplicate removed. The hero title itself (`text-base`, 16px, hand-rolled separately
from `PageHeader`) was bumped to `text-xl` (20px) for the same consistency reason.

**Not further restructured this pass** (time-bounded triage, not found-and-skipped): HSE/HR/Project
Management/Logistics/Warehouse Overview page STRUCTURE was confirmed already compliant with this pass's
own hierarchy guidance from the v1.11.5/v1.11.6 passes (KPI strip → status/action → operational →
activity → calendar; PM's portfolio is already a table, not cards; Logistics' material flow is already
a compact horizontal strip, not five cards; Warehouse's Inventory Health is already a table) — these
inherit the `StatCard`/`CardTitle`/`PageHeader` fixes above automatically since they already use those
shared components, without needing page-specific edits. Monthly Trend/Employees by Department charts
on the Main Dashboard were reviewed and kept (`h-80`, a standard chart height, genuinely adding
analytical value per this pass's own "charts only where they add value" carve-out).

## Visual Correction Pass (v1.11.10)

A direct, narrow correction of v1.11.9's own typography work, based on real feedback that it went too
far in the opposite direction. Two files changed, zero PHP touched.

**What was actually wrong**: `PageHeader`/`CardTitle` (24px/14px, set in v1.11.9) both already matched
this pass's own restated targets exactly — confirmed by re-reading the new spec's own numbers, not
changed again. The two things that genuinely read as oversized were: the Main Dashboard's hand-rolled
hero `<h1>Dashboard</h1>` (bumped 16px→20px in v1.11.9, inside a compact ~220px banner alongside filter
dropdowns — it never needed the same treatment as a true page title, and 20px there was visibly too
heavy next to the filters beside it) and `StatCard`'s KPI-number weight (`font-bold` at 20px reading as
dominant, even though 20px itself is within this pass's own stated 20-22px target). Fixed: hero title
reverted to `text-base` (16px); `StatCard`'s value weight dialed from `font-bold` to `font-semibold` —
size unchanged (still 20px, matching spec), only the boldness that made it feel heavy is reduced. This
is the literal "hierarchy through weight, not size" instruction applied to the one place it hadn't been.

**Man-Hour empty state** (Part 7): Main Dashboard's Man-Hours card previously showed three independent
"—" tiles (Today/This Month/YTD) whenever nothing was recorded for that period. When ALL THREE are
genuinely empty (nothing has ever been logged), the tile grid is now replaced with one explicit
"Belum ada data Man-Hour." message — clearer than three dashes that could be misread as a formatting
quirk rather than "no data exists yet." Partial data (e.g. this month logged, YTD not) still shows "—"
per-tile, since the section as a whole isn't empty in that case.

**Everything else in this directive's scope** (HSE Overview/Master Data structure, PPE workflow/filter
persistence, HRD/PM/Logistics/Warehouse dashboard structure, Waste Management, sidebar grouping/
typography, sidebar RBAC recursion, tenant isolation on Employee/PPE/Project/Leave/Waste, SaaS
Plan/Subscription/Invoice architecture, the payment gateway abstraction, migration ordering) was
audited against the actual current code and found **already correctly implemented by the passes
immediately before this one** (v1.11.6 through v1.11.9) with no intervening change since — re-verified
by re-reading the relevant files, not re-implemented from scratch, since nothing regressed and nothing
in this directive's own text described new functional requirements beyond what those passes already
built. See each of those passes' own `docs/MODULES.md` sections for the original implementation detail.

## Reusable engines (Approval, Workflow, Timeline, Import, PDF, Report Export)

These aren't a "module" with their own page — they're cross-cutting infrastructure consumed by the
modules above. Fully described in `ARCHITECTURE.md`, not repeated here.
