# Roadmap

**Integrated Operations Management System (IOMS) — Industrial Operations Platform**

This roadmap reflects the intended direction of the application. It is deliberately conservative:
the product philosophy is **simple, fast, minimal clicks, clean UI** — *not* an ERP. Every future
module below is designed to slot into the existing architecture without breaking changes.

---

## Guiding principles

1. **Additive only.** New capability arrives as new tables/columns and new pages, never as a rewrite.
2. **Preserve data.** Migrations never drop or reset existing data.
3. **Keep the UI lightweight.** Reuse the existing card/table/dialog patterns; no redesigns.
4. **Project as a container.** Any new operational module may optionally belong to a Project and
   append to that Project's Timeline via the existing polymorphic `project_timeline_events` table.
5. **One piece of information, one module.** Data is never re-entered across modules — e.g.
   manpower lives only in Project Manpower, PPE only in PPE Distribution; other modules (like
   Daily Report) reference or derive from them instead of duplicating fields.
6. **No hardcoded master data.** Anything that could change (PPE types, replacement intervals,
   categories, statuses) is a configurable database table, editable by Super Admin — never a
   code constant. This is also what keeps the system usable by future companies beyond GAJ/MTC:
   all company-specific values live in the database, not in application logic.

---

## ✅ Delivered — v1.2

- Rename to Shipyard Management System / HSE Operations Platform
- Multi-Company (GAJ, Maintenance)
- Company-scoped departments
- Project module + Manpower + Timeline architecture
- Four-role permissions (Super Admin, HSE, HRD, Manager)
- Dashboard company filter, per-company headcount, active projects, today's activities, reminders
- Employee improvements (Company, Join Date, Years of Service, project assignments)
- About dialog + versioned login footer

## ✅ Delivered — v1.3

- **PPE Management**: configurable PPE Master (Super Admin), PPE Distribution + History
  (single source of truth), PPE Dashboard (expiring/expired summary)
- **Project Timeline auto-population**: timeline entries are now derived from Daily Report
  submissions rather than entered separately
- **Daily HSE Report**: multiple reports per project per day (one per HSE Officer/shift),
  activities, findings, notes, photo documentation — without duplicating manpower or PPE data
- `shareableSummary()` hook on Daily Report, ready for future Copy/WhatsApp/PDF wiring

## ✅ Delivered — v1.3.1

- **Fully automatic PPE status** (Active/Expiring Soon/Expired), computed from `expiry_date` on
  every access instead of relying on a manually-set value
- **Clickable PPE Dashboard cards** linking into a pre-filtered PPE list
- **Multi-item Issue PPE form** — one employee, several PPE items, one Save
- **Department dropdowns grouped by Company** across Employees, Reports, PPE, and Input KPI
- **Configurable Display Order** for Departments and Positions, applied consistently everywhere
  an employee list appears (via `Employee::scopeOrderedForDisplay()`)
- **KPI Quick Attendance cross-department draft** with a single "Save All KPI" button and a
  Save/Discard/Cancel guard against losing unsaved selections when navigating away

## ✅ Delivered — v1.3.2

- **Centralized version configuration** (`config/version.php`) — one file controls the version
  shown in the About dialog, sidebar footer, login footer, and Home page announcement
- **About dialog** and **sidebar footer**, both reading from that centralized config
- **Home page and Dashboard redesign** — clearer visual hierarchy, compact KPI grid, charts
  promoted to the visual focus
- **Fixed** two ambiguous-column regressions (Employee Department filter; KPI Quick Attendance
  Department switching) introduced by v1.3.1's `scopeOrderedForDisplay()` joins
- **Fixed** the Unsaved KPI Selections dialog permanently blocking navigation after Save/Discard,
  caused by the navigation guard intercepting its own save/continue requests

## ✅ Delivered — v1.4.0

- **Rebranded to Integrated Operations Management System (IOMS)** — no longer positioned as
  shipyard-exclusive; every visible branding reference updated app-wide
- **Home page hero section** — clean, icon-free, illustration-free premium header
- **KPI Quick Attendance layout reordered**: Filters → Search → Employee List → Selected Employees,
  so the employee list no longer moves down the page as more people are selected
- **Selected Employees panel is now collapsible** (collapsed by default, smooth CSS-only
  expand/collapse, same chip design/remove/count as before)

## ✅ Delivered — v1.5.0

- **Dynamic, per-company KPI categories** (Settings > KPI Categories) — create/edit/delete/reorder,
  Global or company-scoped, zero code changes required for a company to run its own KPI set
- **Module toggle architecture** (Settings > Modules, `config/modules.php`) for the modules that
  exist today, with the registration path documented below for future modules
- **Self-service Authentication settings** — change your own email/password
- **Top bar redesign** — date, live clock, real PPE-alert notification bell, profile menu (About,
  My Account, Log Out); sidebar simplified to navigation + read-only identity
- **Home page hero** — larger, heavier headline; dynamic time-of-day greeting + live clock (local
  system time); premium stat-card typography
- Enterprise visual polish: softer typography hierarchy, consistent card styling across Home and
  Dashboard

## ✅ Delivered — v1.5.1

- **Complete branding audit** — zero remaining references to any legacy product name anywhere in
  the project (see `CHANGELOG.md` for the specific hard-to-find spots this caught: browser title
  fallback, export metadata, seeded account emails, backup filenames)
- **PPE lifecycle redesign** — Issued → In Use → Replacement Requested → Replacement Approved →
  Replacement Completed → Archived, with Expired as a computed overlay, never an automatic
  replacement trigger
- **Daily Report** now represents a Department (free text) instead of an Employee dropdown
- **Project form** uses industry-neutral terminology (Location, generic placeholders)
- **UX de-duplication** — account identity shown once (top bar profile menu only), not repeated in
  the Welcome section or sidebar
- Subtle premium watermark on the Home hero

## ✅ Delivered — v1.5.2

- **Fully dynamic KPI Dashboard** — cards, icons, colors, and the trend chart all generated from
  `KpiCategory` configuration (`show_on_dashboard`, `icon`, `color`, `sort_order`); adding a new
  category makes it appear immediately, with no code change
- **Dashboard is a navigation hub** — every card is clickable; new **KPI Records** page
  (`/kpi-records`) is the flat, filterable destination for "click FAC to see every FAC this period"
- **Real root-cause fix for image uploads** — Branding Logo, Employee Photo, and Daily Report
  Photos all now actually display after upload (previously silently broken: the accessor methods
  existed but were never serialized to the frontend)
- **Reusable image upload architecture** (`ImageUploadField`, `MultiImageUpload`) ready for future
  modules (Asset Photos, Incident Evidence, Permit Attachments, etc.)
- Expanded KPI Category settings (dashboard visibility, incident/approval flags, icon, color)
- Department filter in the Add Manpower dialog
- "Module Visibility" renamed and clarified (visibility only, not module creation)

## ✅ Delivered — v1.5.3

- **Official brand assets integrated** — the real provided wordmark and icon, used directly
  throughout the app (sidebar, Login, Home, Dashboard, About)
- **Centralized branding components** (`BrandWordmark`, `BrandIcon`, `BrandWatermark`) — the only
  place any page ever references a brand image path
- **`config/branding.php`** + a resolved `branding` shared prop, architected so a future admin
  override (custom uploaded wordmark/icon, watermark toggles/opacity) requires zero changes beyond
  the Settings UI itself
- About page is now the official product identity page (both assets + full name + description)

## ✅ Delivered — v1.5.4

- **Branding visual QA pass** — sidebar/Login wordmark sizing increased with proper breathing
  room, Home hero rescaled to an enterprise-dashboard feel, Home greeting now uses the real
  wordmark instead of plain text, Dashboard gained a subtle premium background, About dialog
  hierarchy refined

## ✅ Delivered — v1.6.0

- **`config/ioms.php`** — centralized version/edition/developer/company/copyright config,
  replacing `config/version.php`; added a `version_history` list shown on the About page
- **About dialog scrolling fixed** — `DialogContent` had no height/overflow constraint at all;
  now properly scrollable on every screen size
- **`BrandWatermark` fixed** — blur now scales per usage instead of one hardcoded value reused at
  wildly different sizes; dropped grayscale to preserve brand color and increase visual presence
- Login/Sidebar wordmark sizes increased again; Sidebar footer simplified; Home greeting no longer
  repeats branding (sidebar already carries it); KPI Input Department labels de-cluttered

## ✅ Delivered — v1.6.1

- **Fixed a real PHP parse error** in `PpeController.php` (a docblock comment's embedded `*/`
  prematurely closed it); swept the whole codebase with a corrected comment-balance check
  afterward to confirm it was isolated
- **Dashboard "Today's Summary"** — real-data operational snapshot (Employees, Active Projects,
  LTI, PPE Alerts) with a warning/all-clear banner
- **Top Department Workload** analytics card — real KPI activity volume by department
- Sidebar narrowed to 250px with a 70px logo; Login gained a subtle premium background treatment;
  statistic cards across Dashboard/Home/PPE Dashboard now share a consistent floating/glass style
- About dialog expanded with Build Number, License, Website, Support, and Documentation

## ✅ Delivered — v1.6.2

- **Root-cause fix for watermark visibility** on Dashboard, Home, and About — traced the actual
  DOM nesting rather than assuming; all three had a genuine `overflow`/positioning bug clipping
  most of the image away, not just an opacity/size issue
- Exact specifications applied: Sidebar 240px width / 70px logo / 16px+24px spacing; Dashboard
  hero watermark exactly 450px / 4% opacity
- Branding text standardized app-wide to "Designed & Developed by YSR Systems"
- **New reusable `Combobox` component** — Daily Report's Department field is now searchable
  type-ahead (suggestions from the master list) while still accepting free text, not a traditional
  `<select>`

## ✅ Delivered — v1.6.3

- **Dashboard**: company branding in the hero, real Quick Actions, tighter typography/spacing
- **Sidebar**: nested-menu architecture (real, tested, ready for a future module with a sub-menu),
  version number, spacing polish
- **Real Global Search** across Employees and Projects (`GlobalSearchController` + `GlobalSearch`)
- **Forgot/Reset Password** using Laravel's built-in Password broker
- **Dark Mode toggle mechanism** — real and working, but most components still need converting to
  theme-aware classes before it visually does much (disclosed gap, not hidden)
- **Company Branding extended**: SVG logo support (fixed a validation gap), favicon upload, short
  name, footer copyright
- Explicitly did not build Pending Tasks/Approval sections (no Task/Approval Engine exists) or a
  full Notification Center (no backing table) — see `CHANGELOG.md` for the complete list

## ✅ Delivered — v1.6.4

- **Universal Task Engine foundation** — `tasks` table, `Task` model, `TaskService`,
  `TaskController`, Form Requests, full CRUD frontend (List/Detail/Create/Edit), and real Dashboard
  integration (`Task::assignedTo($user)->openStatus()`, not placeholder data)
- **Fixed a real Dark Mode readability bug** — `CardTitle`/`CardDescription` and Sidebar nav text
  had zero `dark:` variants, meaning they'd render illegibly against the new dark backgrounds
  introduced in v1.6.3. Fixed centrally.
- Company Logo fallback, Global Search width — both verified against spec and fixed where they
  didn't yet match

## ✅ Delivered — v1.6.5 through v1.6.7

Brief summary here — see `CHANGELOG.md` for full detail on each, since this section had fallen
behind actual releases and is catching back up rather than duplicating three versions' worth of
entries in full:

- **v1.6.5**: About Dialog rebuilt from scratch after four sessions of unresolved reports; sidebar
  wordmark hierarchy fixed; six new shared foundation components (`PageHeader`, `SectionHeader`,
  `EmptyState`, `LoadingState`, `StatusBadge`, `StatCard`)
- **v1.6.6**: PPE module restructured to be employee-centric (Employee → Employee PPE → Issue);
  new `Employee::employeePpes()` relationship; an app-wide sweep fixed 9 instances of a real
  controlled-Select empty-string bug; white-flash root-caused to Vite's lazy per-page loading
- **v1.6.7**: Fixed a real `PpeTabNav` missing-import bug; PPE's top navigation extracted into a
  genuinely reusable `ModuleTabNav` component (a `tabs` prop, not hardcoded) for future modules
  (Medical, Training, License, Asset, Competency, Attendance) to use directly; several rounds of
  desktop density/typography polish (see CHANGELOG for detail on each individual pass); new
  Material Request module (department-agnostic, dynamic item table, PDF/print) and PPE Replacement
  Request MVP (built on a new dedicated item-level "Replacement Due" page, since the existing
  employee-level Employee PPE list couldn't support multi-selecting individual PPE records without
  breaking its own minimal-selector scope); new reusable `PdfGeneratorService` both build on
- **v1.6.8**: Found and fixed two severe bugs while verifying the previous session's work rather
  than assuming it was fine -- Material Request and PPE Replacement Request routes were accidentally
  Super-Admin-only, and a `Cache::rememberForever()` with no config-change invalidation had
  permanently hidden any newly-registered module from the sidebar; new Employee Import from Excel
  (chunked, never stops on a bad row, `department_id` genuinely made nullable to support
  "Unassigned"); Report Export architecture (`ReportExportInterface` + `ReportTemplateResolver`)
  prepared ahead of any real company template existing; a follow-up runtime-verification round
  found the caching fix above had a real bug of its own (a cache-key/forget-key mismatch), fixed
  properly and the risky part of the pattern removed entirely; a further verification pass on
  Employee Import (rather than assuming the previous session's build was complete) found the
  importer silently ignored the Employment Status column and never actually read Address/Emergency
  Contact despite those columns existing specifically for it -- both fixed
- **v1.6.9**: Verified `ActivityLog` already existed and was already used app-wide (32+ call sites)
  before building anything -- the real gap was a viewing UI, not the recording mechanism. New
  Universal Approval Engine (`HasApprovals` trait + generic `ApprovalController`, Material Request
  as first consumer) and `ActivityTimeline.jsx` viewer, both documented in `docs/ADR/`. Sprint
  requested 7 major systems (Approval, Timeline, Notification, Attachment, Audit Log, Search, Smart
  Dashboard); deliberately built two properly rather than seven shallowly -- see "Near-term" below
  for the rest.
- **v1.6.9.1**: Completed Material Request's full lifecycle (Draft -> Submitted -> Approved ->
  Processing -> Completed, plus Rejected/Cancelled) rather than starting new engines before one
  workflow was production-ready. New `HasWorkflow` trait (complements, doesn't duplicate,
  `HasApprovals`); found and fixed a real bug in the previous session's own `ApprovalController`
  (a direct `update()` call bypassing transition validation and risking duplicate activity logs);
  evaluated RBAC options (confirmed no package exists, recommended Spatie Permission for when real
  multi-tenant complexity arrives, not now) before centralizing role checks into
  `config/workflow.php`; new `warehouse` role added with zero migration.

---

## 🧭 Near-term — Remainder of the Workflow & Smart Operations sprint (deferred, not abandoned)

Priorities 3 through 7 from the v1.6.9 sprint were not attempted this version, on purpose:

- **Notification Center** (unread/read, mark-all-as-read, future Email/WhatsApp) -- genuinely
  separate from the Activity Timeline; a notification is "something a specific user needs to see,"
  a timeline entry is "something that happened," and conflating them would be the wrong shape for
  both.
- **Universal Attachment Engine** (Images/PDF/Excel/Word across Incident/Material Request/PPE/
  Inspection/PTW/Daily Report/Asset) -- a genuinely large piece of its own (storage, validation,
  per-type preview handling), deserving the same "build it properly once, not seven times shallowly"
  treatment the Approval Engine got this version.
- **Audit Log** (old value / new value / field-level change tracking) -- explicitly distinct from
  `ActivityLog`'s free-text description; this wants structured before/after values per field, which
  `ActivityLog`'s `meta` JSON column could store but isn't currently used for. Needs its own design
  pass, not a quick reuse of the existing table.
- **Global Search** and **Smart Dashboard** (Pending Approvals, Overdue Activities, Upcoming Expired
  PPE, Recent Activities widgets) -- both are natural, fairly quick follow-ups now that Approval and
  Timeline actually exist to power them (a "Pending Approvals" widget is now just a query against
  `Approval::where('status', 'pending')`), but weren't built yet since building the widget for a
  feature that didn't exist yet would have been backwards.

## 🧭 Near-term — Generic Import Engine (one real example exists, not yet generalized)

`EmployeesImport` (v1.6.8) is the first real import built in this codebase -- chunked reading,
row-by-row processing that never stops on the first bad row, and a critical/optional field split
are all patterns any future importer (Department, Project, PPE Master, Vendor, Contractor) would
need identically. Deliberately not extracted into a shared base class yet: abstracting a reusable
pattern from a single example tends to guess the wrong shape. The next import built should look
hard at what's actually common between it and `EmployeesImport` before extracting anything.

## 🧭 Near-term — Document Engine (foundation already in place)

`PdfGeneratorService` exists (v1.6.7) -- a thin, reusable wrapper around `barryvdh/laravel-dompdf`
that any controller calls with a Blade view name + data. Material Request and PPE Replacement
Request already use it. The stated future Document Engine (company-uploaded templates, populated
automatically) would extend this service to resolve a per-company Blade view/template instead of
a fixed one -- the service's method signatures were deliberately kept generic enough that this is
an extension, not a rewrite. Daily Report, Incident Report, and Permit to Work PDFs should reuse
this same service when built, rather than each calling the PDF library directly.

## 🧭 Near-term — Report Configuration UI (foundation already in place)

`report_configurations` table + `ReportConfiguration` model exist (v1.6.7) -- a saved KPI
selection, grouping, and export-type preference, scoped per-company the same way `KpiCategory`
already is. No controller, routes, or Settings UI exist yet. A future "Settings > Report
Configuration" page would let an admin choose which KPI categories to include, group by
department/month/company, and pick an export type, then save it as a named configuration --
this table is exactly what that page would read/write, with no schema change needed first.

## 🧭 Near-term — PPE Inventory (foundation already in place)

`employee_ppe.employee_id` is now nullable (v1.6.7) -- the structural blocker that made "PPE
exists in company inventory, not yet assigned to anyone" impossible to represent is gone. No
"add to inventory" UI exists yet, and the existing issued/in_use/replacement_* status lifecycle for
already-assigned PPE is completely unchanged. A future inventory feature would create
`EmployeePpe` rows with `employee_id = null` to represent unassigned stock, then "assign" them by
setting `employee_id` -- this is the foundation that would need, not the feature itself.

## 🧭 Near-term — Task Engine extensions (foundation already in place)

This version deliberately built `tasks` only. The natural next slice, once prioritized:
`task_comments` and `task_attachments` (both straightforward — same shape as `KpiRecord`/
`DailyReportPhoto` patterns already used elsewhere), then `task_histories` (audit trail of status/
assignment changes) and a real `task_notifications` table backing the existing topbar notification
badge pattern. Automatic overdue detection would be a scheduled Artisan command flipping status to
`overdue`-equivalent (currently `is_overdue` is computed live, which already covers the "is this
task late" question without a background job — a stored status would only be needed if overdue
tasks need their own notification trigger).

---

## 🧭 Near-term — Settings > Branding UI (architecture already in place)

`company_settings` keys for `brand_wordmark_path`, `brand_icon_path`, `watermark_enabled` (+
per-context variants), and `watermark_opacity` are already read by `HandleInertiaRequests` with
correct fallbacks (v1.5.3) — a future Settings > Branding page only needs to add upload fields
that write to these keys (reusing the existing `ImageUploadField` component from v1.5.2) and the
rest of the app picks up the change automatically, with no other code changes required.

---

## 🔜 v2.0 — Operational HSE modules (planned, not yet built)

The database architecture is being prepared so these can be added incrementally. Each will be its
own module with its own table(s), optionally referencing `project_id`, and each will write to the
Project Timeline. Once built, each also registers as a toggleable module the same way the v1.5.0
modules do (see `config/modules.php`) — no changes to the toggle mechanism itself required.

- **Incident Investigation** — report, classify, root-cause, corrective actions.
- **Nearmiss** — lightweight reporting flow feeding existing BBS/Nearmiss KPI.
- **Permit To Work (PTW)** — permit issuance, approval chain, validity window.
- **Gas Test** — readings log tied to confined-space/hot-work permits.
- **Confined Space** — entry register linked to PTW and Gas Test.
- **Inspection** — checklists, findings, follow-up.
- **Waste Management** — waste manifests and disposal records.
- **Document Control / Upload** — controlled document register with versioning.

## 🏢 v3.0+ — Enterprise modules (architecture-ready, not built)

A broader set of enterprise modules the platform is designed to eventually support, per the IOMS
positioning as a multi-industry industrial operations platform (not shipyard-exclusive). **None of
these exist yet** — no stub pages, no placeholder UI, no fake data. What *does* exist today is the
mechanism they'd plug into: `config/modules.php` (module registry) + the `enabled_modules`
CompanySetting (Settings > Modules) + role/company-scoping patterns already proven out by Projects,
PPE, and the KPI Categories work, plus (v1.5.2) a reusable image upload architecture
(`ImageUploadField`, `MultiImageUpload`) any of these can adopt immediately for Asset Photos,
Incident Evidence, Permit Attachments, Project Documentation, etc. without re-implementing upload
UI from scratch, and (v1.6.2) a reusable `Combobox` component (type-ahead suggestions from a known
list, free text still always valid) for any future field like this one -- Material Request items,
Asset categories, Permit types, etc.

- HR Management
- Asset Management
- Fleet Management
- Marine Operations
- Procurement
- Warehouse
- Maintenance
- Quality Control (QC)
- Document Control
- Visitor Management
- Contractor Management
- Permit to Work
- Risk Assessment
- Incident Management

Adding any of these later means: (1) build the module's own tables/controllers/pages as usual,
(2) add one entry to `config/modules.php` and one nav item in `AuthenticatedLayout.jsx` with a
matching `moduleKey`. Nothing about the toggle UI, the `enabled_modules` setting, or the
nav-filtering logic needs to change.

## 🧭 v2.x — HR-adjacent extensions (planned)

Built on the `join_date` foundation added in v1.2, and the PPE foundation added in v1.3:

- **PPE Replacement Reminders** — a real notification surfacing PPE nearing its
  `replacement_interval_months` deadline (the PPE Dashboard already computes this list; v2 adds
  proactive alerts instead of requiring someone to open the dashboard).
- **Service Years** — automatic tenure tracking (already surfaced read-only on profiles).
- **Training & Certification** — courses, expiry reminders.
- **Medical Checkup** — MCU schedule and results register.

## 🧭 Near-term — Daily Report sharing (structure already in place)

`DailyReport::shareableSummary()` was added in v1.3 specifically so these can be wired up without
further model changes:

- **Copy to Clipboard** — frontend-only, calls the existing summary via a small JSON endpoint.
- **PDF Export** — reuse the existing `barryvdh/laravel-dompdf` pattern already used for KPI Reports.
- **Share to WhatsApp** — `wa.me` deep link using the same summary text; no API integration needed
  for a basic version.

## 🔔 v2.x — Platform

- **Notification engine** — replaces the current lightweight "Upcoming Reminder" placeholder with
  real scheduled reminders (PPE, certification expiry, MCU, permit expiry).
- **Audit trail UI** — surface the existing `activity_logs` table in the app.

---

## Explicitly out of scope

- Full ERP features (procurement, payroll, accounting).
- Anything that would require redesigning the current UI or resetting the database.
