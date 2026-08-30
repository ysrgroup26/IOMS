# Architecture

This document covers the cross-cutting patterns that span multiple modules — the reusable
"engines," the multi-tenant/company-scoping model, and the current authorization approach. For
what a specific module does, see `MODULES.md`. For the reasoning behind a specific decision below,
check `ADR/` — this document says *what* the shape is; the relevant ADR says *why*, in more depth,
where the decision wasn't obvious.

## The reusable engine pattern

The recurring shape in this codebase: build one general mechanism, expose it as a trait or service,
and have modules opt in rather than reimplementing similar logic per module. This pattern emerged
gradually — Material Request is the first (and so far only) module built with all of them wired
together — and is the intended template for whatever comes next (PPE Replacement Request's
approval flow, a future Permit To Work, Purchase Request, etc.).

### Approval Engine

**What it is**: a single, polymorphic `approvals` table (`approvable_type`/`approvable_id`) plus
a generic `ApprovalController` (`approvals.approve` / `approvals.reject` routes) that operate on
the `Approval` record's own polymorphic relationship — not scoped under any specific module's
routes.

**How a model opts in**: add the `HasApprovals` trait (`app/Concerns/HasApprovals.php`). It
provides `approvals()` (morphMany), `latestApproval()`, and `submitForApproval($user)` (idempotent
— calling it again while a pending approval already exists returns the existing one rather than
creating a duplicate).

**Authorization**: currently a config-driven role check (`config/workflow.php`'s `approvers` list),
not a hardcoded role name, and not yet a real per-module/per-role assignment (see § Authorization
below and `ADR/001-approval-engine.md` / `ADR/006-material-request-workflow.md`).

**What it deliberately is not**: the larger, configurable multi-step Workflow Engine discussed
early on (per-company editable approval chains like `Manager -> Logistics -> Purchasing ->
Warehouse`, role-based steps, Approve/Reject/Return/Comment/Attachment per step). That's
substantially bigger and hasn't been built. The `approvals` table's shape doesn't preclude adding
that later (e.g. an optional `step` column), but nothing today assumes it's coming in a particular
form.

### Workflow Engine

**What it is**: `app/Concerns/HasWorkflow.php`, a state-machine guard around a model's own
`status` column. Complements the Approval Engine rather than duplicating it — `HasApprovals` is
specifically about the submit/approve/reject *decision*; `HasWorkflow` is the more general guard
valid for a model's *entire* lifecycle, including transitions with no approval decision involved
at all (e.g. Material Request's `approved -> processing` and `processing -> completed`).

**How a model opts in**: define a `protected static array $transitions` map (from-status =>
allowed-to-statuses) and use the trait. `transitionTo($newStatus, $user, $description = null, $meta
= [])` throws a descriptive `ValidationException` naming the current status and what's actually
allowed for any disallowed move, and logs exactly one `ActivityLog` entry for any allowed one.
`canTransitionTo($newStatus)` is available for conditionally rendering UI (e.g. "only show the
Approve button if this transition is actually legal from here").

**Integration point with the Approval Engine**: when `ApprovalController` decides an approval, it
calls `$approval->approvable->transitionTo(...)`, passing the approval's comments through as
`$meta` — this is the single source of both validation and the activity log entry for that
decision. (Earlier code called `$approval->approvable()->update([...])` directly, bypassing
validation entirely and risking a duplicate log entry — see `CONVENTIONS.md`'s pitfalls list.)

### Activity Timeline

**What it is**: `ActivityLog`, a polymorphic model (`subject_type`/`subject_id`) with a
`record($action, $description, $subject, $meta = [])` static convenience method. This already
existed and was already used 32+ times across controllers *before* the Timeline viewer was built —
worth remembering, because it means "does X already have a recording mechanism" is very often
"yes," and the actual gap is usually on the viewing side.

**Viewing**: `resources/js/Components/shared/ActivityTimeline.jsx` — a plain list renderer, not a
self-fetching component. The convention is: a page's own controller `show()` method eager-loads
the relevant `ActivityLog::where('subject_type', X::class)->where('subject_id', $id)->with('user')`
rows and passes them as a prop; the component just renders what it's given. No dedicated
`GET /activity-log?...` endpoint exists (a global, cross-record activity feed would need one, but
nothing currently needs that — see `ADR/004-timeline-engine.md` for why it wasn't built ahead of
need).

### PDF Generation

**What it is**: `app/Services/PdfGeneratorService.php`, a thin wrapper around
`barryvdh/laravel-dompdf`. `streamInline($view, $data, $filename)` and `download(...)` both take a
plain Blade view name — the actual document layout lives entirely in `resources/views/pdf/*.blade.php`
files, styled as traditional, printable paperwork (dense tables, signature lines) rather than
modern web design, since the explicit goal has been compatibility with existing company paperwork
formats, not visual polish.

**Convention for a new document type**: write a Blade view, call the service from your controller.
Don't call the PDF library directly — every future document type (Daily Report, Incident Report,
Permit To Work, Inspection Checklist) is expected to go through this same service.

### Report Export architecture (prepared, not fully built)

**What it is**: `app/Contracts/ReportExportInterface.php` + `app/Services/ReportTemplateResolver.php`.
`KpiReportExport` (the current generic KPI report export) implements the interface as the default.
`ReportController` calls the resolver rather than instantiating an export class directly. No actual
company-specific Excel template exists yet — this is deliberately just the plug-in point, built
ahead of any real template because building the seam is cheap and doing it later would mean
touching `ReportController` again.

**Convention for a new company template**: add a class implementing `ReportExportInterface`, add
one `match` arm (or similar dispatch) in `ReportTemplateResolver`. Don't touch `ReportController`.

### Import Engine

**What it is**: `app/Imports/EmployeesImport.php` — the first, and so far only, real import in the
codebase. Uses Maatwebsite's `OnEachRow` (not `ToModel`) specifically because per-row error
handling needs to happen mid-loop: a duplicate Employee ID or missing critical field must be
recorded as a skipped row and the loop must continue, never abort the whole file. Chunked at 200
rows (`WithChunkReading`) so large files don't need to be held in memory at once.

**Preview / dry-run mode**: the same class, not a parallel scanner, supports a `previewOnly`
constructor flag. All the same row parsing, critical-field checks, and duplicate detection run
either way; only the point where a row would actually write to the database is skipped in preview
mode. This is the mechanism behind Employee Import's "Preview Import" step.

**Smart Master Data Detection**: `app/Services/MasterDataDetector.php` — deliberately its own
standalone service, not baked into `EmployeesImport`, specifically so a future Department Import,
Project Import, PPE Master Import, Vendor Import, or Contractor Import can reuse the exact same
"which of these names already exist, which are new, which are probably a typo" classification
against their own tables. Typo detection is a plain Levenshtein distance check (distance ≤2) — a
name within that distance of an existing one is *suggested*, never silently auto-created as a
duplicate.

**Not yet generalized**: there's no `ImportEngine` base class extracting the common shape from
`EmployeesImport` — deliberately deferred, since abstracting a reusable pattern from a single
real example tends to guess the wrong shape. Build the second real import, then extract what's
actually common.

## Multi-tenant / company-scoping model

**Current state**: partial foundation, not a finished multi-tenant system.

- `users.company_id` — nullable FK. Existing internal-staff users are `NULL` on purpose (they're
  not scoped to one company); a real multi-tenant customer's users would have it set.
- `app/Services/TenantContext.php` + `app/Http/Middleware/IdentifyTenant.php` — resolve "the
  current company" from the authenticated user plus an optional request parameter, cached per
  request. Deliberately does **not** auto-scope Eloquent queries globally (a retroactive global
  scope across every model was judged too risky to introduce all at once) — scoping is still
  explicit, per-query (`scopeInCompany`, `->where('company_id', ...)`), not automatic.
- **Company filtering convention, app-wide**: a per-request query parameter (`?company_id=X`),
  consistently, everywhere — Dashboard, PPE, Reports, Employees, Settings' Departments/Positions
  filters. **There is no persistent "Active Company" session concept anywhere in this codebase.**
  If you're tempted to build one, that's a real, load-bearing architectural change, not a small
  addition — check with whoever owns the roadmap before introducing it, since it would be a new
  pattern alongside the existing one, not a drop-in replacement.
- Company-scoped master data: `departments` and `positions` both have a required `company_id`
  (positions' was added later, backfilled from each position's own department's company where
  possible). A department/position name only needs to be unique *within* its company.

## Authorization (no RBAC package — a deliberate, documented decision)

There is no Spatie Laravel Permission or equivalent package in this codebase. Authorization is:

1. A plain `role` string column on `users` (`super_admin`, `hse`, `hrd`, `manager`, `warehouse`) —
   genuinely a `VARCHAR`, not a real database `ENUM`, specifically so new roles can be added with
   zero migration (as `warehouse` was).
2. Hardcoded `isX()` / `canX()` helper methods on the `User` model for domain-specific checks
   (`canManageMaterialRequests()`, `canManagePpeDistribution()`, etc.).
3. For workflow actions specifically (approve/process/override), a config file
   (`config/workflow.php`) with named role lists, read by the relevant controllers — this exists
   specifically so a future migration to a real RBAC package has one small, well-defined place to
   change per action type, rather than dozens of inline `if ($user->role === 'x')` checks scattered
   through controllers.

**This was evaluated, not overlooked.** The recommendation (`ADR/006-material-request-workflow.md`)
is to adopt Spatie Laravel Permission when genuine multi-tenant permission complexity actually
arrives (per-company custom roles, granular per-action permissions beyond the current handful of
fixed roles) — not preemptively, because migrating now would mean rewriting every existing
`isX()`/`canX()` call site for a problem that doesn't exist yet. Re-read that ADR before deciding
to introduce a permission package; the reasoning for waiting is specific, not just inertia.

**Update (Milestone 2 / v2.0.0):** `spatie/laravel-permission` was in fact added — roles/permissions
now exist, are tenant-scoped, and are editable from Settings. No controller has been migrated from
the `role` column + `isX()/canX()` methods described above to permission checks yet; that remains the
live authorization path this section documents. Both systems currently coexist.

## SaaS Entitlement chain (Package → Subscription, and Package → Module/Workspace grants)

Two separate questions, both under the `Package` umbrella, answered by two separate mechanisms —
worth keeping distinct because they were built at different times and, until v2.1.0, only one of
them actually did anything at request time:

1. **"Is this tenant's subscription usable at all right now?"** — `Subscription` (status
   active/suspended/cancelled, dates) → `EntitlementService::tenantIsUsable()` /
   `tenantIsDegraded()` → enforced by `EnforceTenantEntitlement` middleware (registered globally on
   the `web` group), gated by `config('saas.enforce_entitlement')` (default `true`). This part has
   worked and been enforced since v1.11.1.
2. **"Is this specific department/workspace included in what this tenant bought?"** —
   `Tenant::modules()` / `Tenant::workspaces()` (pivot tables `tenant_modules`/`tenant_workspaces`,
   editable per-tenant from the Platform Admin "Tenant Grants" page) → `EntitlementService::
   tenantCanUseModule()` / `tenantCanUseWorkspace()`. **Until v2.1.0 these methods had zero callers
   anywhere in the request path** — fully built, never wired in — so every tenant could reach every
   department regardless of Package, gated only by role. See `docs/CONVENTIONS.md`'s "a fully-built
   enforcement mechanism with zero call sites" pitfall entry for the full audit finding.

As of v2.1.0:
- `Package::defaultWorkspaceKeys()` / `defaultModuleKeys()` (on `app/Models/Package.php`) is the
  canonical Starter=HSE / Professional=HSE+HRD / Enterprise=all-workspaces mapping, keyed by
  `Package::slug` so a display-name rename can't silently break it.
- `PlatformController::storeTenant()` now calls `$tenant->workspaces()->sync(...)` /
  `$tenant->modules()->sync(...)` using that mapping immediately after creating the `Subscription`,
  so a new tenant's Module/Workspace grants actually reflect the Package it was created with. A
  Platform Admin can still hand-adjust individual grants afterward via the existing Tenant Grants
  page — this only sets sensible defaults at creation time.
- The actual per-request enforcement of #2 is wired into `EnforceTenantEntitlement`, behind its own
  separate flag, `config('saas.enforce_workspace_entitlement')` — **default `true` as of v2.13.0**
  (SaaS Phase 1). It was safe to flip from the previous `false` default because two safety nets now
  exist together:
  1. `EntitlementService::tenantCanUseModule()` / `tenantCanUseWorkspace()` treat a tenant with
     **zero** grant rows in `tenant_modules`/`tenant_workspaces` as fully allowed (the allow-list
     check is skipped entirely) rather than fully denied. A tenant with at least one grant row is
     unaffected and goes through the real allow-list. This makes "a tenant that predates this
     feature and was never explicitly provisioned" structurally impossible to lock out, closing the
     exact "do not simply deny everyone" failure mode a naive enable would have hit.
  2. `php artisan tenants:sync-grants {tenant?} {--dry-run}` (`app/Console/Commands/
     SyncTenantGrants.php`) additively tops up a *partially*-granted tenant (e.g. one seeded before a
     newer Workspace/Module existed) up to its Package's own `defaultWorkspaceKeys()`/
     `defaultModuleKeys()` baseline. `syncWithoutDetaching()` only — it can never remove a grant, so
     it can never be used to downgrade a tenant. Run with `--dry-run` first after any deploy that
     changes this flag, to see what (if anything) would change, before relying on it.
  Denial responses for both the subscription-usability block and the workspace-grant block now
  render in Bahasa Indonesia (e.g. `"Fitur ini belum tersedia untuk paket perusahaan Anda."`) via
  `Errors/Show.jsx`, matching this codebase's page-content language convention. Still overridable
  per-install via `SAAS_ENFORCE_WORKSPACE_ENTITLEMENT=false` in `.env`.

**Entitlement Dependency Rule**: a module must never gate on another *paid* module purely because it
shares core data with it. `Employee` is core data shared by both HSE and HRD; PPE (HSE) correctly
depends only on `Employee`, never on HRD being enabled. The one violation of this rule found in this
codebase (`User::canManageManHour()` requiring `isHrd()` for what is genuinely shared HSE+HRD
Man-Hour data) was fixed in v2.1.0 — see that method's own doc comment.

## SaaS Productization: Plan/Pricing Foundation (v2.14.0)

**`Package` IS the SaaS Plan entity — no separate `plans` table exists or was created.** A fresh
audit (see this phase's own directive, Part 3) confirmed `Package` already carried nearly everything
a Plan needs (`name`, `slug`, `description`, `price_monthly`, `price_yearly`, `max_users`,
`max_companies`, `is_active`, `sort_order`) — only four fields were genuinely missing and were added
to the existing table (one migration, no new table): `currency`, `trial_days`, `is_public` (whether a
plan should appear on the Plans comparison page — an internal/retired plan can stay assigned to a
tenant without being offered to anyone else), `is_custom` (marks a plan as "contact us" pricing —
Enterprise — instead of a fixed self-serve number). `billing_interval` was deliberately NOT added as
a fifth column — the existing `price_monthly`/`price_yearly` dual-column shape already represents
both intervals.

**Pricing is never hardcoded in a component.** `App\Services\PricingService` is the single place a
`Package` row becomes display data — `publicPlans()`/`summarize()` return `{amount, currency,
formatted}` per interval, with `formatted` reading `"Hubungi Kami"` for an `is_custom` plan or
`"Gratis"` for a zero price, never a fabricated number. `resources/js/Pages/Subscription/Plans.jsx`
(tenant-facing, route `subscription.plans`, open to every authenticated tenant user — see that
route's own comment for why it's placed outside the role-restricted groups around it) and
`resources/js/Pages/Platform/Plans.jsx` (Platform Admin CRUD, unchanged surface, four new fields
added to its form) both consume this same service/shape — a future payment-gateway integration reads
prices from the exact same source, not a second copy.

**Trial**: `Subscription`'s state machine already had everything Part 6 asked for (`STATUS_TRIAL`,
`trial_ends_at`) — the one missing piece was a per-Plan "how many days" input, now `Package.trial_days`
(nullable — null means this plan has no trial). `PlatformController::updateSubscription()` derives a
blank `trial_ends_at` from the selected package's `trial_days` when status is set to `trial`; an
explicitly-entered date is always respected as-is. No automatic trial-expiry cron exists or was added
— expiry stays a computed, non-blocking "degraded" state exactly as v1.11.1 designed it (see the SaaS
Entitlement chain section above); actually converting an expired trial to active/expired remains a
Platform Admin action, matching this phase's explicit "payment conversion belongs to a later phase."

**Upgrade/Downgrade domain (documented, not built)**: per this phase's own Part 10, the intended
future behavior is recorded here rather than implemented with no real caller yet. A plan change should
eventually be representable as one of: *effective immediately* (today's only actual behavior —
`updateSubscription()` edits the current Subscription row in place) or *effective next billing cycle*
(a pending change, not yet representable — would need a `pending_package_id`/`pending_effective_at`
pair on `Subscription`, deliberately NOT added yet since nothing reads or writes it). "Upgrade" vs.
"downgrade" is not a distinct code path today — both are the same `package_id` edit; the distinction
only matters once proration/billing rules exist, which is out of scope until the Checkout/Billing
phase.

**Billing-ready architecture (documented boundary, no new tables)**: `Invoice`, `PaymentTransaction`,
`PaymentWebhookEvent`, and `PaymentGatewayInterface`/`NullPaymentGateway` already exist (an earlier
pass provisioned them) and were re-confirmed still correctly unwired this phase — no route or
controller calls the gateway interface, `NullPaymentGateway` throws on every method rather than faking
success. Intended future relationship, for the next phase to build against rather than re-derive:
`Subscription` (1) → `Invoice` (many, one per billing period) → `PaymentTransaction` (many, one per
attempt) → `PaymentWebhookEvent` (gateway callbacks, verified+processed independently of the
transaction they relate to, so a replayed/out-of-order webhook can't double-apply a payment). This
phase added no table to that chain — `packages`' four new columns are the only schema change.

## Navigation Architecture (Department → Item, v1.10.2)

The app nav is a two-level **Department → Item** structure, not a single flat sidebar. Internally
this is still called "Workspace" in code (`WORKSPACES`, `getVisibleWorkspaces()`) — only the
user-facing label is "Department" (v1.8.0). See `ADR/007-workspace-navigation.md` for the full
reasoning across every refinement (v1.8.0 through v1.10.2). This section is the quick-reference.

- **`resources/js/lib/workspaces.js`** is the single source of truth: a `WORKSPACES` array, each
  entry `{ key, label, icon, core?, tier: 'department' | 'global', items: [{ name, href?, queryParams?, icon, moduleKey?, adminOnly?, disabled?, global?, children? }] }`.
  `tier: 'department'` = a real department (offered in the Department Selector); `tier: 'global'` =
  Reports/Administration, reached only through the sidebar's Global navigation state, never the
  selector. An item's `global: true` (only ever the repeated "Dashboard" link back to the Global
  Dashboard) marks it as not owned by whichever department it appears in.
- **The Global Dashboard is NOT a department and is not in `WORKSPACES` at all** (v1.10.2). It's a
  permanently pinned link in the topbar, first element before the Department Selector, reachable
  independent of whichever department (if any) is currently active.
- **Top Navigation Bar, in order: Dashboard (pinned) → Department Selector → Global Search →
  Work Center → Notifications → Profile.** The Department Selector is a single dropdown offering
  ONLY departments (v1.10.2 — Reports/Administration were removed from it); it doesn't render at all
  for a Department User (see below).
- **The sidebar has three possible sources** (v1.10.2): a department's own items (when one is
  active), the merged "Global navigation" (`getGlobalNavItems()` — Reports + Administration, shown
  when no department is active: Global Dashboard, Reports, or Settings pages), or — for a Department
  User — always just their one assigned department, never Global navigation. Disabled items render as
  a non-interactive, visibly muted row (no `<Link>`, a lock icon, a "coming soon" tooltip) rather than
  a fake page.
- **Active department is derived from the current route only** — no `localStorage` fallback
  (removed in v1.10.2; "no department active" is a legitimate state, not an edge case to paper over).
  Active-item highlighting is `?tab=`-aware (`isItemActive()` in `AuthenticatedLayout.jsx`) — several
  Administration items intentionally share one route (`settings.index`) with different
  `queryParams.tab`.
- **Department Users** (v1.10.2): `User::department_key`, nullable, opt-in, no existing account
  assigned one. `getSelectableDepartments()` collapses to their one department; the Department
  Selector doesn't render for them; their sidebar always shows only that department. See
  `ADR/007`'s v1.10.2 section for why this wasn't retrofitted onto the existing role system.
- **Field/Foreman landing experience** (v2.7.0, Field/Foreman Experience pass, Phase 3A): the single
  universal `dashboard` route (every role's post-login landing page except Platform Admin) now
  branches server-side in `DashboardController::index()` — `$request->user()->isDepartmentUser()`
  renders a separate, task-first `Field/Home` page instead of the enterprise `Dashboard/Index`. No
  new route, no middleware change (`dashboard` was already in `RestrictDepartmentAccess::
  UNIVERSAL_PREFIXES`), no new RBAC concept — a full audit before this pass confirmed no dedicated
  "Foreman" role exists (6 real roles total: `super_admin/hse/hrd/manager/warehouse/platform_admin`),
  and `department_key` was the only existing, already-live mechanism that narrows a user's experience
  at all. This is a deliberate, documented MVP proxy, not a claim that every Department User is
  literally a field worker — see `DashboardController::index()`'s own doc comment for the honest
  limitation and the reasoning for not inventing a new column/role to solve it more precisely yet.
  `Field/Home`'s action tiles reuse the exact same `canManage*()` gates their destination routes
  already enforce, and its pending-approvals/tasks counts are read verbatim from
  `WorkCenterService` (no duplicated query).
- **A department disappears from the switcher once it has zero visible items** for the current user
  — disabled items never get filtered this way (they carry no `moduleKey`), so a department made
  entirely of placeholders (Warehouse, Maintenance, Quality Control, Finance) still always appears.
- **No URL namespacing.** `/employees`, `/ppe`, `/material-requests`, etc. are unchanged.
- **Permission readiness**: Department → Module (the `modules` DB table as of Milestone 2, Task #42
  — see `docs/ADR/008-tenancy-foundation.md`; `config/modules.php` now only supplies its default
  seed data) → Feature → Action. Real permission enforcement is via `spatie/laravel-permission`
  (also Milestone 2) for new call sites; existing controllers still run on the `role` column/
  `isX()/canX()` methods, migrating them is a separate later step.
- **Adding a real nav item**: add it to the right department's `items` array (plus an
  `App\Models\Module` row if it's a new toggleable module — see Settings → Module Visibility).
  **Adding a disabled placeholder**: same array, `disabled: true`, no `href` — see
  `docs/CONVENTIONS.md`.
- **Breadcrumb is suppressed except for genuinely multi-level pages** — see `ADR/007`'s v1.8.0
  section.
- **Dashboard is the landing page** (v1.9.0, unchanged in v1.10.2) — `/` redirects to `/dashboard`,
  login redirects to `route('dashboard')` directly for every user, Administrator or Department User
  alike. `Home` (`HomeController`, `Pages/Home/Index.jsx`) is deleted, not just unlinked; its two
  genuinely unique real feeds (Recent Daily Reports, Recent Employee Changes) and the release
  announcement banner moved into `DashboardController`/`Dashboard/Index.jsx`.
- **Each CORE department (HR, HSE, Project Management, Logistics) has its own real "Overview"**
  (v1.10.0, relabeled from "Dashboard" in v1.10.2) at `{department-key}.dashboard`, distinct from the
  global landing Dashboard above — see `docs/MODULES.md`'s "Department Dashboards" section for what
  each one deliberately does and doesn't show. **Future Departments** (Warehouse, Procurement, Asset
  Management, Maintenance, Quality Control, Finance) each link to a shared `ComingSoon` page via their
  own distinct `{department-key}.coming-soon` route name — see `ADR/007`'s v1.10.0 section for why the
  route names must stay distinct rather than sharing one.

### Work Center (v1.8.0, narrowed in v1.9.0)

`app/Services/WorkCenterService.php` + `app/Http/Controllers/WorkCenterController.php` +
`resources/js/Pages/WorkCenter/Index.jsx`, route `work-center.index`. **Not a Department** — a
global, cross-cutting action center (pinned in the topbar) for pending Approvals and assigned Tasks
for the current user, so cross-department collaboration (a Project Manager approving a
Logistics-owned Material Request) happens without ever duplicating a module into a department that
doesn't own it. Every entry links back into the owning module's own page. As of v1.9.0 the topbar
badge counts only Approvals + Tasks — PPE Alerts moved to a separate **Notifications** bell
(`NotificationsMenu`, reusing `WorkCenterService::ppeAlertCount()`), on the reasoning that Alerts are
system-detected conditions while Work Center is work explicitly assigned to a person; PPE Alerts
still also appear as their own section on the Work Center page itself. See `ADR/007`'s v1.8.0 and
v1.9.0 sections for the full reasoning.

## Frontend conventions worth knowing before building a new page

- **Shared components live in `resources/js/Components/shared/`** — check there before writing a
  new status badge, tab nav, empty state, or workflow action UI. `StatusBadge` in particular has a
  single canonical status-to-color mapping meant to cover every module's statuses in one place;
  extend it, don't create a parallel one.
- **`CollapsibleSection`** (v2.4.0, PTW UX + Field Operations pass) — the progressive-disclosure
  primitive: a labeled, collapsed-by-default section for optional/advanced form fields, so a form
  can show the minimum required fields first without a second bespoke show/hide implementation per
  page. First real consumer is `PermitsToWork/Form.jsx`'s "Optional / Advanced" section; reuse this
  for any other form that needs the same "required fields visible, optional fields on demand"
  pattern rather than building a new toggle.
- **Module top navigation** uses `ModuleTabNav` (a generic component taking a `tabs` prop) — PPE's
  navigation is the reference implementation; a new module's own `XTabNav.jsx` should be a thin
  wrapper around `ModuleTabNav`, not a reimplementation.
- **Desktop-first, dense enterprise UI** — the established visual reference points are Linear,
  Jira, ClickUp, Notion, GitHub: compact rows, small type (13px body text is typical, 11px for
  secondary/labels), minimal padding. Several rounds of density passes have progressively tightened
  this; when adding new UI, match the current density of nearby existing pages rather than the
  more generous spacing of an early version.
- **Dark mode exists in the codebase but is switched off** (`DARK_MODE_ENABLED = false` in
  `resources/js/lib/useTheme.js`) — the implementation is intact, just hidden, pending a future
  version turning it back on. Don't remove the dark-mode Tailwind classes (`dark:*`) when touching
  a page; they're dead code for now, not wrong code.

## Product UI/UX Finalization (v2.15.0)

A scoped polish pass, not a redesign — per that phase's own directive, "fix shared components first"
before touching individual pages, so a fix reaches every page that already uses the shared primitive
rather than being applied page-by-page. Audited first (Tailwind config, `app.css`, every `ui/`
primitive, `StatusBadge`, `PageHeader`, `AuthenticatedLayout`, and a representative page set) — the
underlying design tokens (the `graphite` neutral scale, `success`/`warning`/`danger` semantic colors,
12px-based radius scale, subtle single-layer shadows) were already sound and left untouched; only two
genuine, global gaps were fixed, plus a couple of representative-page/one-off improvements:

- **`ui/dialog.jsx`**: `DialogContent` had no horizontal safety margin on mobile (`w-full max-w-lg`,
  nothing preventing it from touching the viewport edge) and, more importantly, **no `max-h`/
  `overflow-y-auto` at all** — a tall form dialog on a short/narrow viewport had no way to scroll to
  content past the viewport height (confirmed only one dialog anywhere in the app,
  `Ppe/ReplacementDue.jsx`, had opted into this itself). Fixed once, globally: `w-[calc(100%-2rem)]`
  (guarantees a 1rem margin under the `max-w-*` cap) + `max-h-[85vh] overflow-y-auto` as the default
  for every dialog; a page's own `className` override still wins via `tailwind-merge`.
- **`Components/shared/PageHeader.jsx`**: was `flex-wrap items-center justify-between` at every
  breakpoint — title and action buttons sat side-by-side even on a narrow phone, wrapping only once
  they literally didn't fit, an accidental rather than deliberate mobile layout. Now stacks
  title-above-actions below `sm`, reverts to the original side-by-side row at `sm:` and up.
- **`ui/badge.jsx`**: added a `warning` variant, consuming the `warning`/`warning-light` Tailwind
  tokens that already existed in `tailwind.config.js` but had no consumer anywhere. `StatusBadge`
  now maps `overdue` and priority `high` to it (previously indistinguishable from `destructive`
  `critical`) — see `StatusBadge.jsx`'s own `STATUS_MAP` for the full status/priority mapping.
- **`Incidents/Index.jsx`** converted to the same mobile card-list pattern already proven on
  `PermitsToWork/Index.jsx` (`md:hidden` card list + `hidden md:table` desktop table) as this phase's
  one representative Part 10/14D table conversion — the 7-column table previously had no mobile
  fallback at all. The same pattern should be applied to other actionable-record tables
  (`CorrectiveActions/Index.jsx` and similar) as a follow-up; this phase did not attempt a full
  app-wide table conversion (out of proportion for a "polish, not redesign" phase).

**Deliberately not changed this pass**: the sidebar's department/workspace grouping (audited, already
clear — HSE's nested sub-groups, flat lists elsewhere — no artificial restructuring for its own
sake), the topbar (audited, already responsively hides secondary elements below `xl`/`sm`), the
Enterprise Dashboard's business logic/widget set (visual hierarchy only, if touched at all), Field
Home (already appropriately minimal per its own prior-phase doc comment), and the large number of
form pages using an un-prefixed `grid-cols-2` for a plain two-field row — reviewed and left as-is,
since two short field pairs stacking correctly at their card's own width is not the same failure mode
as a dense multi-column dashboard grid.
