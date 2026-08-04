# 007 — Workspace Navigation Architecture

## Status

Accepted (v1.7.0). Refined (v1.8.0) — see "v1.8.0 — Department / Work Center Refinement" below.
Refined again (v1.9.0) — see "v1.9.0 — Administrator Master Navigation Model" below.

## Problem

The sidebar was a single flat, hardcoded list (`navItems` in `AuthenticatedLayout.jsx`), gated
per-item by role (`adminOnly`) and by the module toggle registry (`moduleKey` against
`config/modules.php`). That worked at 10 items. `config/modules.php`'s own comment already
documents ~14 more business domains on the roadmap (HR, Asset, Fleet, Marine Operations,
Procurement, Warehouse, Maintenance, QC, Document Control, Visitor Management, Contractor
Management, Permit to Work, Risk Assessment, Incident Management) — a flat list would have to grow
to 25-30+ entries to hold all of them, at which point it stops being a usable sidebar. The
navigation needed restructuring *before* that growth happens, not after.

## Decision

**Introduce a two-level Workspace → Item navigation, resolved entirely on the frontend, with zero
route or URL changes.**

### Workspace registry (`resources/js/lib/workspaces.js`)

A `WORKSPACES` array groups the exact same items the old flat `navItems` had, unchanged in shape
(`name`, `href`, `icon`, optional `moduleKey`, optional `adminOnly`, optional `children`) and
unchanged in gating logic (`getVisibleWorkspaces()` applies the identical two-gate filter the old
`visibleNav` filter used). Nothing about *how* an item is shown or hidden changed — only that items
are now grouped, and the group itself disappears once it has zero visible items.

Seven real workspaces exist today: **Dashboard** (core), **Human Resources**, **HSE**, **Project
Management**, **Logistics / PPIC**, **Reports**, **Administration** (core, admin-only). Every
current module maps to exactly one of them — see `docs/MODULES.md` for the per-module workspace
note. Two placements were judgment calls, recorded here rather than left implicit:

- **Daily Reports → Project Management, not HSE.** `DailyReport` (`app/Models/DailyReport.php`)
  `belongsTo(Project)`, carries no manpower/PPE fields, and its own docblock states that creating
  one writes a summary event to `project_timeline_events` so the Project Timeline is *derived* from
  it. It's structurally a project activity/progress log (has `activities`, `photos`), even though
  it originated as HSE-Officer-authored reporting. Placed by what the data actually models, not by
  its history.
- **Tasks (`tasks.*`) was deliberately left out of the sidebar entirely**, exactly as it was before
  this redesign. It has no current sidebar entry; adding one would be a new feature-exposure
  decision, not a navigation reorganization, so it's out of scope here and listed under Future
  Recommendations instead.

Domains with **no real module built yet** (Procurement, Warehouse, Asset Management, Maintenance,
Quality Control, Finance, Marine Operations, Document Control, Visitor Management, Contractor
Management, Permit to Work, Risk Assessment, Incident Management, and several HR/HSE sub-domains)
are **not** created as empty placeholder workspaces. They're documented in a comment block in
`workspaces.js`, mirroring `config/modules.php`'s own existing "how a future module would register"
note, so adding the first real module for one of them is a two-line change (a `config/modules.php`
entry + a workspace item with a matching `moduleKey`) — no new workspace-switcher code, no further
navigation redesign.

### Active workspace: derived from the route, not treated as session state

`getWorkspaceKeyForRoute()` reverse-indexes every workspace item's route-name *prefix* (the segment
before the first `.` — the same prefix concept the old sidebar already used for its own
active-route highlighting), so any route belonging to a workspace's domain (e.g. `ppe.employees`,
`ppe.master`, not just `ppe.dashboard`) resolves to the right workspace. `AuthenticatedLayout`
derives the active workspace from the current route on every navigation; only when no route match
exists does it fall back to a `localStorage` value from the last explicit switch. This deliberately
avoids creating a second, undocumented "active context" concept — `docs/ARCHITECTURE.md` already
flags that this codebase has no persistent company-scoping session state by design (company
filtering is a per-request query param), and a workspace switcher that was authoritative client
state rather than route-derived would be exactly that same anti-pattern in a new place. Deep links,
bookmarks, PDF links, and browser back/forward all land in the correct workspace because the URL
alone determines it.

### No URL namespacing, no route changes

Workspace is a pure frontend IA/grouping layer. `/employees`, `/ppe`, `/material-requests`, etc.
are untouched — nothing is renamed to `/hr/employees` or similar. This was a deliberate choice, not
an oversight: the brief asked to preserve existing URLs whenever possible, and there was no
functional requirement (bookmarks, generated PDF links, tests) that a URL prefix would have served.
`routes/web.php`, `config/modules.php`, and `config/workflow.php` are unmodified by this change.

### Workspace enable/disable: derived, not a new persisted setting

No new `CompanySetting` key, no new toggle route. A workspace is visible only if it has ≥1 item
that is both role-visible and module-enabled — using the existing, untouched `enabled_modules`
CompanySetting read path in `HandleInertiaRequests.php` (deliberately left byte-for-byte identical;
see `docs/CONVENTIONS.md`'s pitfalls list for the two real caching bugs that mechanism has already
caused, which is reason enough not to touch it for this change). Disabling every module under a
workspace already makes that workspace disappear automatically. The Settings → Modules toggle UI
(`resources/js/Pages/Settings/Index.jsx`) got a *display-only* enhancement — the same checkboxes,
same form, same route, just grouped under workspace headers — so an admin can see "turn off all of
HSE" as one visual group without a second toggle system to keep in sync with the first.

### Permission readiness: Workspace → Module → Feature → Action, no RBAC package adopted

This introduces names for the first two layers of the eventual permission model without adding any
new persistence or revisiting the RBAC-deferral decision in `ADR/006`:

- **Workspace** = a `WORKSPACES` entry.
- **Module** = an existing `config('modules.available')` key (unchanged).
- **Feature** = an individual nav item / route name.
- **Action** = the existing per-route `role:` middleware groups and `canX()` controller checks
  (unchanged) — View/Create/Edit/Delete/Export/Import/Approve are already expressed this way today.

If/when a real RBAC package is adopted per `ADR/006`'s existing criteria, this hierarchy is meant
to be additive to attach to, not something that needs rewriting first.

## Consequences

- Sidebar stays short (2-3 items typical per workspace) regardless of how many business domains
  IOMS eventually covers — new domains get a new workspace or a new item in an existing one, never
  a longer flat list.
- Workspace-aware topbar switcher comes "for free" on every existing page, with zero per-page prop
  threading, because it's computed once in the layout from the active workspace + current URL.
- The one real functional gap this surfaced and fixed in passing: `User::isWarehouse()` existed on
  the backend but was never shared to the frontend (`HandleInertiaRequests.php`) — added as
  `is_warehouse` alongside the other role flags, since Logistics/Warehouse-role nav filtering needs
  it and every other role already had its flag shared.
- Not solved by this change (left for later, deliberately): a real breadcrumb for record-level
  pages (e.g. "HSE / PPE Management / John Doe"), and exposing Tasks as a workspace item. Both are
  addressed differently, not literally, by the v1.8.0 refinement below — see there for why.

## v1.8.0 — Department / Work Center Refinement

Prompted by using the v1.7.0 implementation in the running app rather than only reading it, and a
follow-up architecture discussion working through Information Architecture, enterprise navigation
patterns (SAP Fiori, Oracle Navigator, Dynamics 365, ServiceNow, Odoo), and visual hierarchy before
implementing. Four real changes, each addressing something v1.7.0 got wrong or left unfinished —
not a rewrite of the decision above, which still holds.

### Terminology: "Department" in the UI, "Workspace" in the code

User-facing text now says **Department**, not Workspace — concrete, matches how the app's owning
domains describe their own ownership ("HSE owns...", "HR owns..."), and doesn't assume SaaS-native
vocabulary from an audience that includes shipyard/mining/construction/HSE field staff. The code
keeps `WORKSPACES`, `getVisibleWorkspaces()`, etc. unchanged — churning every internal identifier
for a user-facing label change would be pure risk for zero behavioral benefit. The two vocabularies
are allowed to differ; this file is the place that says so.

### Home is not a Department

`WORKSPACES`'s `dashboard` entry no longer bundles `Home` alongside `Dashboard`. Home is a fixed,
frequently-used destination — bundling it one click deep inside a department switcher (even as the
first entry) adds friction to the single most common navigation action in the app. It's now a
permanently pinned link in the topbar (`AuthenticatedLayout.jsx`'s `TopBar`), outside the Department
selector entirely, next to it rather than inside it. `dashboard` workspace now holds only the
analytics Dashboard — grouped under the `platform` tier (see below), since it's a periodic
company-data check-in tool, not a place anyone does department-owned work.

### Departments vs. Platform: a tiered switcher, not a flat list

Not every entry in the old flat switcher was actually a peer. `Dashboard` (analytics), `Reports`,
and `Administration` are platform *tools* — nobody "owns" work there the way HSE owns PPE or
Logistics owns Material Requests. Mixing them undifferentiated with real departments in one flat
list was a real IA smell, not just a style nit — confirmed by comparing against how mature enterprise
platforms actually organize navigation (Oracle's Navigator and ServiceNow's "All" menu both group by
this same kind of distinction, not a flat alphabetical list). Each `WORKSPACES` entry now carries a
`tier: 'department' | 'platform'` field, and both the desktop pill row and the mobile/tablet dropdown
group by it (`groupByTier()` in `AuthenticatedLayout.jsx`) — a pure presentation change, the
underlying visibility/gating logic (`getVisibleWorkspaces()`) is untouched. The Department selector
stays in the topbar, not the sidebar — considered and explicitly rejected moving it there during the
architecture discussion, in favor of keeping the topbar as the one place global navigation lives.

### Work Center: cross-department collaboration without duplicating modules

The single biggest realization from using v1.7.0: departments don't share modules, they share
*workflows*. A Project Manager doesn't create Material Requests — Logistics does — but the PM still
needs to review and approve them. The old design had no answer for this other than "switch into
Logistics and find it," which defeats the entire point of department-scoped ownership. **Work Center**
(`app/Services/WorkCenterService.php`, `app/Http/Controllers/WorkCenterController.php`,
`resources/js/Pages/WorkCenter/Index.jsx`, route `work-center.index`) is the answer: a global,
NOT-a-Department action center, pinned in the topbar next to the Department selector, aggregating:

- **Approvals** — pending `Approval` records the current user is entitled to decide
  (`config('workflow.approvers')`, the identical rule `ApprovalController` already enforces), scoped
  to the viewer's own company **only when the viewer has a `company_id`** — most internal staff
  (managers, HSE, Super Admin) have a null `company_id` by design (see `User::company()`'s own doc
  comment), and scoping those users would hide approvals from the majority of approvers rather than
  narrow them usefully. A company-scoped user (a future tenant Company Admin) only sees their own
  company's approvals.
- **Tasks** — open tasks assigned to the current user, via the existing Universal Task Engine's own
  `assignedTo`/`openStatus` scopes (`app/Models/Task.php`) — no new query logic, this engine already
  existed and was already fully built; Work Center is its first real UI-level consumer.
- **Alerts** — PPE items expiring soon/expired, the same query the old PPE-only topbar bell already
  ran, moved into `WorkCenterService::ppeAlertCount()` so it's one implementation instead of two.
  Deliberately named "Alerts," not "Notifications" — there's no persisted, per-user read/unread
  notification model in this codebase yet, and calling this pillar "Notifications" would imply
  infrastructure that doesn't exist. A real notifications system (event-driven creation, read state)
  is future work, not bundled into this navigation change.

Every Work Center entry links back into the module that actually owns the record (a pending Material
Request still opens `material-requests.show`) — Work Center renders no module-specific UI of its own,
only pointers into the existing single-source-of-truth implementation, per the "no duplicate modules"
requirement.

The topbar badge count and the Work Center page read from the same `WorkCenterService` methods (the
badge via a `work_center` prop shared in `HandleInertiaRequests.php`, the page via
`WorkCenterController`) — they cannot drift out of sync with each other the way two independent
implementations could.

### Breadcrumb: suppressed unless it adds real information

The v1.7.0 auto-derived breadcrumb (`{workspace label} / {active item name}`) had a genuine
duplication bug: at every module-landing page, its own text repeated both the Department selector
(already showing the department) and the page's own `<h1>` (already showing the page name) — the
same words rendered twice, inches apart, actively competing for attention rather than helping.
`Breadcrumb` in `AuthenticatedLayout.jsx` now renders **only** when the active nav item has children
and one of them is the current page (the one case where there's a genuinely deeper level the
Department selector + page title don't already cover) — e.g. a future PPE sub-page. No current item
has `children` populated, so the breadcrumb renders nothing anywhere today; that's correct, not a
placeholder — the same "gate exists, currently untriggered" pattern already used for `adminOnly`
items before any admin-only item existed.

## v1.9.0 — Administrator Master Navigation Model

Prompted by reviewing the v1.8.0 implementation in the browser and deciding to build one complete,
excellent interface for the Company Administrator persona first, deferring role-specific navigation
(HSE/HR/Logistics/Warehouse/Finance/Director/Manager) to a later filtering pass on top of this same
model — not a decision to build separate layouts per role. Five real changes.

### The switcher is now a dropdown at every breakpoint, not just below `lg`

v1.8.0 kept a desktop pill row (grouped by tier) alongside a mobile/tablet dropdown fallback. A pill
row is a linear pattern — it degrades as more departments are added, and this version adds six new
department entries (Warehouse, Procurement, Asset Management, Maintenance, Quality Control, Finance)
on top of the four that already existed, which would have made the desktop pill row genuinely
unusable. The switcher (`TopBar` in `AuthenticatedLayout.jsx`) is now a single dropdown trigger
showing the current department, at every breakpoint — this scales to any number of departments
without ever needing another pass on the topbar's layout.

### Disabled navigation items: a real, deliberate pattern, not a placeholder

Every department below now lists the modules it will eventually own, even ones with no route,
controller, or page today. An item with `disabled: true` in `resources/js/lib/workspaces.js` has no
`href` and is rendered in the sidebar as a non-interactive, visibly muted row (a lock icon, `title`
tooltip reading "coming soon") — never a `<Link>`, never a route that resolves to a fake or empty
page. This is a deliberate distinction: a disabled row previews the navigation *structure* honestly
(there is genuinely nothing to click yet); a placeholder *page* would imply a feature exists when it
doesn't. `getVisibleWorkspaces()` doesn't gate disabled items by `moduleKey` (they have none) — they
always render, since they're a structural preview, not something a module toggle controls.

Two new all-placeholder departments (Warehouse, Maintenance, Quality Control, Finance — effectively a
single "Dashboard" disabled row each) exist purely so the selector previews where the platform is
headed; Procurement and Asset Management got the fuller item lists from the spec. Worth flagging
explicitly: the spec's own example lists put "Warehouse" both as its own top-level department AND as
a sub-item under Logistics/PPIC — both were kept (a future standalone Warehouse department is a
different scope than warehouse-adjacent visibility inside Logistics' own operational data), but this
is a genuine overlap worth resolving deliberately, not accidentally, whenever Warehouse gets built for
real.

### Administration reuses Settings' existing tabs — no new pages

`Users`, `Departments`, `Positions`, `Companies`, and `Module Management` are real, existing
capabilities — they already live as tabs inside the single `Settings/Index.jsx` page, which already
supports `?tab=` deep-linking (`new URLSearchParams(window.location.search).get('tab')`). Rather than
build five new routes/pages that would duplicate that page's logic, each Administration sidebar item
links to `settings.index` with a different `queryParams: { tab: '...' }`, reusing the exact same
implementation. This needed one real fix: multiple sidebar items sharing one route name broke the old
prefix-only active-route matching (it would always highlight whichever came first). `isItemActive()`
in `AuthenticatedLayout.jsx` now also compares the `tab` query param when an item specifies one, and
treats the bare `Settings` item as active only when no `tab` param is present at all. `Audit Logs` has
no backing implementation (only per-record Activity Timelines exist, not a global log viewer) — it's
a genuine `disabled` item, not a hidden fifth Settings tab.

### Dashboard is the landing page; Home is retired

`HomeController.php` and `resources/js/Pages/Home/Index.jsx` are deleted, not just unlinked — once
`/` redirects straight to `/dashboard` and login redirects to `route('dashboard')` directly (not via
`route('home')`, which would have meant a needless double-redirect), nothing renders that page anymore.
`route('home')` is kept as a route *name* (`Route::redirect('/', '/dashboard')->name('home')`) purely
so nothing depending on that name — old bookmarks, external links to `/` — breaks.

Home and Dashboard overlapped substantially (both had a welcome header, employee/project counts, KPI
activity). Two of Home's feeds were genuinely unique and not fabricated data, so they moved to
`DashboardController`/`Dashboard/Index.jsx` verbatim: **Recent Daily Reports** and **Recent Employee
Changes** (the latter reusing the existing `activity_logs` audit trail, no new table). The "What's New"
release announcement banner moved the same way. Today's Activities (KPI records) and Upcoming PPE
Expiry were already covered by Dashboard's existing widgets in a different shape, so those weren't
duplicated.

Not done in this pass, deliberately: a Calendar, Schedule, or visual Project Timeline widget. Dashboard
already substantially covers "operational command center" (KPI cards, charts, leaderboards, pending
tasks, quick actions) — but Calendar/Schedule have no backing data model in this app at all, and
building UI for them now would mean fabricating data that doesn't exist, which is exactly the kind of
placeholder this version's own navigation work deliberately avoided doing for pages. These stay a
named future recommendation, not something faked to check a box.

### Work Center's badge narrows; a separate Notifications bell returns

Work Center's topbar badge now counts only **Approvals** and **Tasks** — things explicitly assigned
to the current user. **Alerts** (PPE expiring/expired) moved to a new, separate **Notifications** bell
(`NotificationsMenu` in `AuthenticatedLayout.jsx`), reusing `WorkCenterService::ppeAlertCount()` so
there's still one implementation, not two. The distinction: Notifications are system-detected
conditions ("something needs looking at"); Work Center is work explicitly assigned to a person
("something needs *you* to act"). PPE Alerts still also appear as their own section on the Work
Center page itself (`resources/js/Pages/WorkCenter/Index.jsx`) — real, useful information in both
places is an acceptable, honest overlap; a fabricated "Notifications" system would not have been.

## v1.10.0 — Core Departments Build-Out

Scope explicitly narrowed by instruction: implement four CORE departments (Human Resources, HSE,
Project Management, Logistics) — one real Dashboard plus one genuinely new module each — while the
remaining eight departments (Reports/Administration already real; Warehouse/Procurement/Asset
Management/Maintenance/Quality Control/Finance still future) stay untouched in the switcher. Given
the volume this implied (~20 still-unbuilt sidebar items across four departments), the chosen scope
was explicitly agreed with the user first rather than either quietly under-delivering or shipping
shallow CRUD across everything at once: real Dashboards for all four departments (using only data
that already existed), plus exactly one new, fully-built module per department — **Leave** (HR),
**Incident Management** (HSE), **Milestones** (Project Management), **Goods Receipt** (Logistics).
Every other still-listed sidebar item (Attendance, Recruitment, PTW, Inspection, Milestones-adjacent
Activities, Goods Issue, Stock Movement, etc.) remains a `disabled` row, exactly the v1.9.0 pattern —
narrowing scope didn't change that mechanism, just which items moved from disabled to real.

Two of the four new modules reuse the Approval/Workflow Engines (Leave — the second real consumer
after Material Request, proving they generalize) or the Workflow Engine alone (Incident Management);
the other two (Milestones, Goods Receipt) are deliberately simpler CRUD with no lifecycle, because
neither a milestone nor a receipt has a multi-step decision to make. See `docs/MODULES.md` for each
module's own section — this ADR doesn't repeat their field-level detail.

**Future Departments got a real destination.** Previously an entire future department was a single
`disabled` "Dashboard" row (no route at all). Now each links to one shared `ComingSoon` page — a
small, honest addition: it says the department is on the roadmap and unbuilt, nothing more, no
fabricated content. The six departments deliberately get six distinct route *names*
(`{department-key}.coming-soon`), not one shared name — `workspaces.js`'s active-department detection
is keyed off the route-name prefix (the segment before the first `.`), and six items sharing one name
would all resolve to whichever department the lookup happened to process last, silently breaking
"which department pill is highlighted" for five of the six. This is the same prefix-collision
category of bug Administration's `?tab=` items already required a fix for in v1.9.0 — worth watching
for whenever multiple sidebar items are made to share a route going forward.

## v1.10.1 — Global Dashboard Regression Fix

While wiring the four v1.10.0 department Dashboards into `workspaces.js`, the pre-existing `dashboard`
Platform-tier entry (Global Dashboard / Company Overview, unchanged since v1.9.0) was accidentally
omitted when the `WORKSPACES` array was rewritten — not a deliberate redesign, an omission. The
route, `DashboardController`, and `Dashboard/Index.jsx` page were never touched or simplified and
kept working exactly as before; the regression was purely navigational — once a user selected any
department, there was no way back to the Global Dashboard except typing the URL directly, since it
no longer appeared in the Department selector.

Restored as the first `WORKSPACES` entry (Platform tier, `core: true`, matching its pre-v1.10.0
shape). This is the reminder for why: **removing a navigation entry is a real regression even when
the page behind it is untouched** — a page that still works but has become unreachable through normal
navigation is, from the user's perspective, indistinguishable from a page that was deleted. Any future
restructuring of `WORKSPACES` should diff the resulting array against the previous one, not just
reason about which entries are "obviously" still needed.

## v1.10.2 — Global Dashboard vs. Department Overview, and Department Users

A precise architecture spec superseded the previous round's looser handling of "Dashboard." Two
concepts that had been blurred together were split apart explicitly:

**Global Dashboard is not a department and is not switchable.** It was removed from `WORKSPACES`
entirely and is now a permanently pinned link in the topbar (`AuthenticatedLayout`'s `TopBar`,
first element, before the Department Selector) — reachable in one click regardless of which
department (if any) is currently active, exactly the role "Home" played in earlier iterations of
this navigation model before Home itself was retired. The route, `DashboardController`, and
`Dashboard/Index.jsx` are unchanged — see this file's own v1.10.1 section for why that page was
never the problem.

**Each core department's own dashboard is now labeled "Overview," not "Dashboard."** Same route
(`hr.dashboard`, `hse.dashboard`, `project-management.dashboard`, `logistics.dashboard`, unchanged),
different user-facing label — deliberately, so the distinction between "company-wide" and
"this-department-only" is visible in the sidebar itself, not just documented. Each core (and future)
department's sidebar now also carries its own leading "Dashboard" item pointing back at the global
route, marked `global: true` in `workspaces.js` so it's excluded from `PREFIX_TO_WORKSPACE` — without
that exclusion, whichever department defined that link last would falsely "own" the `dashboard`
route and get highlighted as active every time anyone visited the Global Dashboard.

**Reports and Administration left the Department Selector.** They aren't departments, and mixing
them into that dropdown undermined the instruction that the selector should contain "only
Departments." They're still fully live at their existing routes, now reached through a third sidebar
state — `getGlobalNavItems()` in `workspaces.js`, a flat merge of Reports' + Administration's own
gated items, shown whenever no department is currently active (on the Global Dashboard, Reports, or
Settings pages). This is the sidebar's "Global navigation" state named in the spec.

**Department Users are a genuinely new mechanism, not a pure navigation tweak** — a nullable
`department_key` string column on `users` (migration `2026_08_13_100041`), opt-in, no backfill.
`User::isDepartmentUser()` returns true only when it's set. `null` (every existing account today)
means Administrator: full Department Selector, can switch freely, unchanged behavior. Setting it to
a real `workspaces.js` department key collapses `getSelectableDepartments()` to that one department,
and `AuthenticatedLayout` stops rendering the Department Selector at all for that user (not a
disabled/single-option version of it — nothing renders there). A Department User's sidebar always
shows their one department's items, on every page including the Global Dashboard itself — never the
Administrator's Global navigation fallback, matching "only their assigned Department should be
available."

This was deliberately scoped narrower than a full "derive HSE/HR/Logistics roles from the
Administrator experience" pass: no existing account was assigned a `department_key` as part of this
change. The existing five roles (`super_admin`, `hse`, `hrd`, `manager`, `warehouse`) don't map
cleanly onto the four core departments — `hse`, in particular, already has broad, real,
cross-department capability today (Employees, Projects, Material Requests, Leave, Incidents — see
every `canManageX()` on `User` gated by `isSuperAdmin() || isHse()`), so silently restricting HSE-role
accounts to only the HSE department's sidebar would have been a real access-scope regression, not a
navigation change. Deciding which real accounts (if any) should become Department Users, and what
their role should actually be allowed to do once restricted, is a deliberate per-account policy
decision left for whoever administers this app — the mechanism is built and verified working (a
temporary test account confirmed: no Department Selector, single-department sidebar even on the
Global Dashboard, no Settings shortcut in the profile menu), but activating it for real accounts is
future work, not part of this change.

**Also fixed while the sidebar's active-state logic was being reworked**: the old `localStorage`
fallback for "which workspace is active" was removed. It existed to guarantee the sidebar was never
empty in earlier iterations, but "no department is currently active" is now a legitimate first-class
state (Global navigation), not an edge case to paper over with remembered client state — consistent
with this file's own repeated principle that active navigation state should be derived from the
route, not persisted client-side.

## v1.10.3 — Two Bugs: Real Enforcement, and a Stale Placeholder

Two defects found by testing with the actual seeded HSE account rather than Super Admin:

**Bug #1 — Department Users had no real boundary.** v1.10.2 built the `department_key` mechanism
but deliberately left every existing account (including the seeded `hse@ioms.local`) unrestricted,
flagging the "who actually becomes a Department User" question as a policy decision for later.
That decision has now been made explicitly: `hse@ioms.local` is the canonical Department User
example account (`UserSeeder`, `department_key = 'hse'`). HRD/Manager stay Administrator-like
(`department_key` null) — their documented capability already spans multiple departments (see
`UserSeeder`'s own comment), so there's no single department to assign them to without narrowing
what they can already do.

More importantly, hiding the Department Selector and other departments' sidebar items was only ever
a UX convenience — a Department User who knew (or guessed) another department's URL could still
reach it directly, since nothing on the backend enforced the restriction. `config/departments.php`
(a route-name-prefix → department-key map, the backend counterpart to `workspaces.js`'s
`PREFIX_TO_WORKSPACE`) plus `App\Http\Middleware\RestrictDepartmentAccess` (registered globally in
`bootstrap/app.php`, same tier as `HandleInertiaRequests`/`IdentifyTenant`) now closes that gap: a
Department User hitting a route owned by a different department gets a 403, not just a missing nav
link. Administrators (`department_key` null) are completely untouched by this middleware — it does
nothing at all for them, matching every account's existing behavior. A small, deliberate design
choice: an unmapped route (not listed in `config/departments.php` at all) fails OPEN, not closed —
the map is a curated allow-list of what's been explicitly assigned to a department, not an
exhaustive registry, so treating an oversight as "deny" would be more likely to break something real
than catch something dangerous.

**Bug #2 — a stale disabled "Dashboard" placeholder.** The `administration` workspace's `items`
array still carried `{ name: 'Dashboard', icon: Settings, disabled: true }` as its first entry, a
leftover from an earlier draft that predates Administration's real content (Users/Departments/
Positions/Companies/Settings/Module Management) being filled in. It was never linked to anything and
never meant to represent a real page — but it rendered as a locked row literally named "Dashboard,"
directly contradicting "Dashboard must never be disabled." Removed outright, not renamed —
Administration doesn't need its own dashboard concept; the Global Dashboard already covers
company-wide, and Administration's own items are already real and unlocked.

## v1.10.4 — KPI Ownership Correction

The existing KPI module (`kpi-input.index`, `KpiInputController`, `KpiCategory`) was mapped to Human
Resources in v1.10.0's initial department build-out. That was wrong: its routes were already gated
to `role:super_admin,hse` at the route level (see `routes/web.php`'s "Super Admin + HSE" middleware
group) — the implementation itself was always HSE's, only the sidebar placement and label
disagreed with that.

Corrected in both places that need to agree with each other:
- `resources/js/lib/workspaces.js` — HR's item renamed to a locked "HR KPI" placeholder (a
  genuinely separate, not-yet-built future concept — HR's own KPI tracking, distinct from the
  existing BBS/TBM/LTI-style module); HSE's item becomes the real "HSE KPI" link to the exact same
  route/controller/permissions, not a duplicate.
- `config/departments.php` — `kpi-input`/`kpi-records` prefixes moved from the `hr` entry to the
  `hse` entry, so `RestrictDepartmentAccess` enforces the same ownership the sidebar now shows.

No controller, route, migration, or permission logic changed — this was purely a navigation/mapping
correction, per explicit instruction to reuse the existing implementation rather than duplicate it.
HSE's "Campaign" placeholder (present in the v1.10.0/v1.10.2 item lists) was also dropped in this
pass — not in the corrected department's exhaustive item list given, and never a real page.
