# Changelog

All notable changes to **IOMS — Industrial Operations Platform** (formerly Shipyard
Management System, formerly SAFETY LOG) are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

---

## [2.0.0 Beta] — 2026-08-16

Milestone 2: SaaS Tenancy Foundation. Architecture Freeze per the approved Blueprint -- builds the
platform layer above the existing single-instance app (Tenant → Company, unchanged in meaning) using
existing engines wherever possible rather than rewriting them. Full reasoning in
`docs/ADR/008-tenancy-foundation.md`.

### Added — Tenancy Foundation

- `tenants` table; `companies.tenant_id` (NOT NULL anchor) and `users.tenant_id` (nullable forever --
  null means Platform Super Admin, see `User::isPlatformAdmin()`).
- `App\Models\Scopes\TenantScope` on `Company`, fail-closed with no resolved tenant.
- `App\Http\Middleware\ResolveTenant` (runs first in the web middleware stack) + `App\Support\CurrentTenant`
  (container singleton).
- Legacy, dead `roles` table/model/seeder removed (collided by name with Spatie's own `roles` table).

### Added — Platform Super Admin

- `User::ROLE_PLATFORM_ADMIN`; `PlatformAdminSeeder` creates `platform@ioms.local`.
- New `/platform/*` surface (`PlatformController`, `PlatformLayout.jsx`) -- Dashboard and Tenants
  pages, tenant status management (trial/active/suspended/expired). Gated by `role:platform_admin`,
  entirely separate from the tenant-side app.

### Added — Package + Subscription (structure)

- `packages` and `subscriptions` tables/models. Seeded with Starter/Professional/Enterprise tiers; the
  current tenant gets an active Enterprise subscription by default. No payment gateway integration yet.

### Added — RBAC (spatie/laravel-permission)

- Package installed; `teams` feature repurposed for tenant scoping (`team_foreign_key` = `tenant_id`).
- `config/permission_catalog.php` -- flat `module.action` permission catalog.
- `RolePermissionSeeder` creates one tenant-scoped Role per existing `role` value with a default
  permission set matching that role's current capabilities, and assigns every seeded user their Role.
- New Settings → "Roles & Permissions" tab -- a Company Admin can edit any tenant-side role's
  permissions. Does not yet change any controller's actual authorization (still `role`/`isX()/canX()`)
  -- said plainly in the UI.

### Added — Dynamic Module & Workspace catalogs (DB-driven, replacing config files)

- `modules` table replaces `config('modules.available')` as the runtime module registry
  (`config/modules.php` now only supplies `ModuleSeeder`'s default seed data).
- `workspaces` table overrides label/icon/order/active-state for `resources/js/lib/workspaces.js`'s
  WORKSPACES entries (structure/routes/gates stay in code -- see the migration's own doc comment).
  New Settings → "Department Navigation" section to rename/reorder/hide departments without a deploy.

### Fixed — bugs found during this milestone's own verification

- `CurrentTenant` wasn't bound as a container singleton, so every `app(CurrentTenant::class)` call
  resolved a fresh, unset instance -- broke `db:seed` (`CompanySeeder` inserted `tenant_id = NULL`).
- `UserSeeder` and `SettingsController::storeUser()` didn't set `tenant_id` explicitly on new accounts,
  silently making them Platform Super Admins and failing every Company-scoped query closed for
  themselves.
- `ResolveTenant` passed `null` as the Spatie permission team id for a Platform Super Admin at runtime,
  while their Role was seeded under a `0` sentinel -- `->hasRole()`/`->can()` silently returned false.
- `PlatformController`'s tenant company counts showed 0 for every tenant -- `withCount('companies')`
  inherited `TenantScope`'s fail-closed behavior, wrong for a controller whose entire purpose is
  cross-tenant visibility. Fixed with an explicit `withoutGlobalScope`.
- Settings → "Roles & Permissions" 500'd with `LazyLoadingViolationException` -- missing
  `->with('permissions')` eager-load.

---

## [1.6.9.1 Beta] — 2026-08-10

Complete Material Request Workflow. The previous version introduced the Approval Engine and
Timeline foundation; this version finishes the full business process end to end, rather than
starting on new engines (Notification, Search, Dashboard) before one workflow was actually
production-ready.

### Added — Complete lifecycle (Draft -> Submitted -> Approved -> Processing -> Completed)

- Extended `MaterialRequest`'s status enum with `processing` and `cancelled` (real migration).
  **"Pending Approval" is deliberately not a separate stored status** -- it's how `submitted` is
  *labeled* in the UI while its `Approval` record's own status is `pending`, which already existed
  as a distinct, correct concept. Storing both would have been visible duplication; reasoning in
  `docs/ADR/006-material-request-workflow.md`.
- New `HasWorkflow` trait, complementing `HasApprovals` rather than duplicating it --
  `HasApprovals` is specifically the submit/approve/reject decision; `HasWorkflow` is the general
  state-machine guard around a model's whole `status` lifecycle, including transitions with no
  approval decision at all (`approved -> processing`, `processing -> completed`). Any future
  multi-step module defines its own `$transitions` map and gets the identical guard.
- New `completed_at` column -- the Detail Page spec explicitly asked for "Completion Date," and
  `updated_at` isn't a reliable stand-in (any edit changes it, not just completion).
- New controller actions (`process`/`complete`/`reopen`/`cancel`), each authorized through
  `config/workflow.php` rather than a hardcoded role check.

### Fixed — a real bug in the previous session's own Approval Engine code

- `ApprovalController` was calling `$approval->approvable()->update([...])` directly, completely
  bypassing any transition validation and risking a duplicate `ActivityLog` entry alongside
  `Approval::approve()`'s own logging. Fixed: `transitionTo()` (from the new `HasWorkflow` trait)
  is now the single source of both validation and logging for the approvable's status change;
  `Approval::approve()`/`reject()` now only update the `Approval` record's own fields.

### Added — Role-based authorization, evaluated before building anything custom

- Confirmed no RBAC package (Spatie Laravel Permission or otherwise) exists in this codebase before
  writing any new authorization code.
- **Recommendation, documented with full reasoning in the ADR**: adopt Spatie Laravel Permission
  when real multi-tenant permission complexity actually arrives -- not this version, since
  migrating now would be a genuinely breaking change (dozens of existing call sites would need
  rewriting) for a problem (per-company customizable permissions) that doesn't exist yet.
- What this version does instead: a new `config/workflow.php` with three role lists
  (`approvers`/`processors`/`overriders`), read by both `ApprovalController` and
  `MaterialRequestController` instead of scattered inline role checks -- specifically so a future
  Spatie migration has one small, well-defined place to update, not dozens of ad-hoc checks.
- New `warehouse` role, added with zero migration (`role` is a plain `VARCHAR`, widened from a real
  enum in an earlier session specifically for this kind of additive change).

### Added — Dynamic, status-aware Detail Page

- Action buttons now render based on both current status and the viewing user's role/permission --
  Draft shows Edit, Submitted shows Approve/Reject (via the existing reusable `ApprovalActions`),
  Approved shows Start Processing, Processing shows Complete, Rejected shows the rejection reason
  read-only (with a narrow override-only Reopen path for Company Admin), Completed/Cancelled are
  fully read-only.
- Extended the existing `StatusBadge` component (not a new one) with the workflow's statuses.
- List page filter extended to all seven real statuses.

### Database migrations

```
2026_08_09_100031_create_approvals_table
2026_08_09_100032_extend_material_requests_status_enum
2026_08_10_100033_extend_material_requests_status_enum_v2
2026_08_10_100034_add_completed_at_to_material_requests
```

### Documentation

- `docs/ADR/006-material-request-workflow.md` -- the workflow lifecycle, the "Pending Approval"
  labeling decision, and the full RBAC evaluation and recommendation.

---

## [1.6.9 Beta] — 2026-08-09

Workflow & Smart Operations sprint. Given the scope requested (Approval Engine, Timeline,
Notification Center, Attachment Engine, Audit Log, Global Search, Smart Dashboard -- seven major
systems), this version deliberately built two of them properly rather than attempt shallow,
likely-broken versions of all seven. See "Deferred" below for an honest accounting of the rest.

### Verified first, per explicit instruction -- not rebuilt

`ActivityLog` already existed before this version: polymorphic, already used 32+ times across
existing controllers via a `record()` convenience static. The recording half of "Activity Timeline"
was already done. The genuine gap, confirmed by checking rather than assumed, was that nothing in
the application could actually *view* this data -- no controller exposed it, no page rendered it.

### Added — Universal Approval Engine (Priority 1)

- New polymorphic `approvals` table + `Approval` model. `approvable_type`/`approvable_id` let any
  future model (PPE Replacement Request, Permit To Work, Purchase Request, Asset Request,
  Inspection) opt into the exact same approve/reject flow, not just Material Request.
- New `HasApprovals` trait -- the actual reuse mechanism. A future approvable model adds this one
  trait (`approvals()`, `latestApproval()`, `submitForApproval($user)`), extends its own status
  enum with `approved`/`rejected` constants, and gets the identical workflow Material Request now
  has, with zero new backend routes or controllers.
- New generic `ApprovalController` (`approvals.approve`/`approvals.reject`) operating on the
  `Approval` record's own polymorphic relationship -- deliberately not scoped under
  `/material-requests`, so it's genuinely reusable rather than reusable-in-name-only. Correctly
  placed in the general authenticated route group, not repeating an earlier session's
  Super-Admin-only routing mistake.
- `MaterialRequest`'s status enum extended (real migration, `draft`/`submitted` ->
  `+approved`/`rejected`/`completed`) and wired to the trait -- submitting now creates a real
  pending `Approval`, and approve/reject decisions keep the request's own `status` column in sync.
- Reusable frontend: `ApprovalActions.jsx` (status badge, Approve button, Reject with a required
  reason) -- any future approvable module's Show page renders it exactly the same way
  `MaterialRequests/Show.jsx` now does.
- **Deliberately not built**: the full configurable multi-step Workflow Engine discussed in an
  earlier session (per-company editable chains, role-based steps, Return/Comment/Attachment per
  step). This session's spec asked for the simpler fixed-vocabulary version; the schema is shaped
  so that larger engine could still be layered on top later without this version's data changing
  shape, but that layering is explicitly future work. Authorization is currently a flat
  `isSuperAdmin()` check, not real per-role approval routing -- see `docs/ADR/001-approval-engine.md`.

### Added — Activity Timeline viewer (Priority 2)

- New reusable `ActivityTimeline.jsx` component -- a plain list renderer, not a self-fetching
  component; the page's own controller eager-loads the relevant `ActivityLog` rows (subject already
  known there) and passes them as a prop, same pattern `MaterialRequestController::show()` now uses.
- `MaterialRequestController` now actually calls `ActivityLog::record()` at its real action points
  (created, submitted, updated) -- previously this controller recorded nothing at all despite the
  mechanism being available and used elsewhere in the app.
- See `docs/ADR/004-timeline-engine.md` for the full reasoning, including why a dedicated
  fetch-your-own-data endpoint was considered and not built this version.

### Added — Architecture Decision Records

- `docs/ADR/001-approval-engine.md`, `docs/ADR/004-timeline-engine.md` -- both include honest
  "Alternatives Considered" sections, not just the decision taken.

### Database migrations

```
2026_08_09_100031_create_approvals_table
2026_08_09_100032_extend_material_requests_status_enum
```

### Deferred, honestly -- not silently skipped

Priorities 3 through 7 (Notification Center, Universal Attachment Engine, Audit Log, Global Search,
Smart Dashboard) are untouched this version. Each is a genuinely substantial system in its own
right; ADRs 002, 003, and 005 were not written, since writing a design decision record for
something not yet built and not yet even scoped in detail would be documenting a guess, not a
decision.

---

## [1.6.8 Beta] — 2026-08-08

Data Management & Document Foundation sprint continued. This session's request largely described
functionality already built in earlier sessions (Employee Import, Report Export architecture,
`PdfGeneratorService`) -- rather than rebuild any of it, verified the existing implementation
against this session's exact spec and found two real, genuine gaps worth fixing.

### Fixed — Employee Import gaps found during verification, not assumed complete

- **The template asked for "Employment Status" but the importer ignored that column entirely**,
  hardcoding `'active'` for every imported row regardless of what was actually in the file. Now
  reads the column, validates it against the real status enum (`active`/`inactive`/`resigned`),
  and only falls back to the default when the value is blank or unrecognized -- never fails or
  skips the row for an invalid value, matching the existing "optional fields never block the
  import" behavior.
- **`address` and `emergency_contact_name`/`emergency_contact_phone` columns existed in the schema
  specifically to support this import (added in an earlier session), but were never actually read
  by the importer, and weren't even in the downloadable template.** Added both to the template and
  wired them into the import. Deliberately did not add a Photo column -- Excel rows can't carry an
  uploadable image file, so photos remain a per-employee, post-import step, same as manual employee
  creation already works.
- Verified module registration, sidebar visibility, filters, Dashboard task card, template columns,
  chunked processing, and the Report Export architecture all already matched this session's spec
  correctly -- confirmed rather than assumed, no changes needed to any of them.

---

## [1.6.8 Beta] — 2026-08-07

### Fixed — Runtime verification round 3: clean migration + a real cache-key bug in my own previous fix

- **Reverted the "drop table if exists" migration from the second hotfix.** It resolved the
  immediate symptom but was the wrong kind of fix for a permanent, production-facing migration --
  silently dropping a table as routine logic is dangerous if this migration ever needs to run
  again against a database that has since accumulated real data in that table. The migration is
  now back to a clean, straightforward `Schema::create()` (with the table-name fix from the first
  hotfix retained). Recovery for a database already affected by the original bug is now a
  separate, manual, one-time process documented in `README.md`'s Troubleshooting section --
  `Schema::dropIfExists()` run once via `php artisan tinker`, then a normal `migrate` -- rather
  than baked into the migration file itself.
- **Found a real bug in my own previous fix while investigating why Material Request still didn't
  appear in the sidebar despite being enabled in Settings.** The second hotfix changed
  `CompanySetting::get()`'s cache key to include a hash of the default value, but never updated
  `set()`'s corresponding `Cache::forget()` call to match -- meaning saving *any* setting through
  this helper (not just `enabled_modules`) stopped actually invalidating its cache at all. Fixed
  properly: cache only the raw stored value (never the caller-supplied default), so a single,
  predictable `company_setting:{key}` cache key is exactly what `forget()` already targets, with no
  way for the two to drift out of sync again.
- **Additionally removed the caching dependency entirely for `enabled_modules` specifically** --
  reads the database directly in `HandleInertiaRequests` now. This setting has caused two separate
  real bugs across two sessions from being cached at all (a stale-default bug, then the
  cache-key-mismatch bug above); it's a tiny, rarely-changed, indexed lookup that was never a hot
  enough path to need forever-caching in the first place. Removing the dependency removes this
  entire class of "my module toggle change isn't taking effect" bug permanently, rather than
  trying to get the caching correct a third time.
- Traced the complete flow (Settings save -> database -> `HandleInertiaRequests` -> shared prop ->
  sidebar filter) end to end and confirmed every other piece -- the nav item's `moduleKey`, the
  union-in-newly-added-modules logic, the redirect after saving -- was already structurally
  correct.

### Fixed — Migration left in an inconsistent state by the previous hotfix (second hotfix)

- Fixing the wrong-table-name bug above wasn't enough on its own: the first (broken) migration
  attempt had already partially executed against real databases before failing -- the
  `ppe_replacement_request_items` table itself got created (before the invalid foreign key
  reference caused the overall migration to error out), but Laravel never marked the migration as
  completed, since it did ultimately throw. Re-running `php artisan migrate` after the fix above
  then failed with "table already exists," since Laravel tried to run the same
  (now-corrected) migration again against a database where the table was already sitting there.
  Fixed by making the migration idempotent: it now drops the table first if it already exists
  before recreating it. Safe with zero data-loss risk specifically because this table cannot
  contain any real data in that scenario -- it never finished being created successfully the first
  time, which is the definition of the failure this fixes. On a clean database this is a no-op
  (nothing to drop) and behaves exactly as before. Verified no other migration in the same batch
  has the same underlying risk -- every other `constrained()` call either already specifies an
  explicit table name or auto-guesses correctly, since `employee_ppe` is the only table in this
  batch with a deliberately non-standard (singular) name.

### Fixed — Migration failure found during runtime verification (hotfix)

- `ppe_replacement_request_items`'s foreign key used `->constrained()` with no explicit table
  name, so Laravel guessed the referenced table by naively pluralizing the column name
  (`employee_ppe_id` -> `employee_ppes`). The actual table is deliberately named `employee_ppe`
  (singular) via `EmployeePpe::$table` -- confirmed directly from that model and the table's
  original creation migration, not assumed. Fixed by passing the real table name explicitly.
  Swept every other model with a non-default table name (`ProjectManpower`) and every validation
  rule referencing `employee_ppe` for the same mistake -- both already correct, no other instances
  found.

Data Management & Document Foundation sprint. Started with a mandatory verification of the
previous session's Material Request module rather than assuming it worked -- found two real,
severe bugs as a result.

### Fixed — Material Request/PPE Replacement Request were genuinely inaccessible

- **Every route for both modules was accidentally nested inside `role:super_admin` middleware.**
  This meant only a Super Admin could reach either module at all -- HSE, the actual intended user
  base for both, got a 403 on every single route, even though the sidebar link still rendered
  normally. This alone fully explains "the application does not display the Material Request
  module." Relocated both route blocks to the general authenticated area; real per-user
  authorization already happens inside the controllers/pages
  (`canManageMaterialRequests()`/`canManagePpeDistribution()`) and was never meant to be
  duplicated as a route-level role restriction on top of that.
- **A real permanent-caching bug, found by tracing the actual code path, not assumed.**
  `CompanySetting::get()` used `Cache::rememberForever()` with no mechanism to invalidate the
  cache when `config()` itself changes. The very first time it ever ran for `enabled_modules` --
  long before `material_requests` existed in config -- it permanently cached the old module list.
  Editing `config/modules.php` afterward could never un-stick that cache on its own. Fixed two
  ways: the cache key now embeds a hash of the default value, so a changed default (like a newly
  registered module) is automatically a fresh cache entry; and the middleware explicitly unions in
  only the specific modules known to be new as of this version for any already-saved settings row
  that predates them -- deliberately not a blanket re-enable of every config key, which would have
  silently undone any module a company had genuinely chosen to disable.

### Added — Employee Import from Excel

- New `EmployeesImport` (chunked, 200 rows at a time -- comfortably handles "hundreds or
  thousands" per spec), processing every row individually and never stopping on the first invalid
  one. Duplicate Employee IDs, missing Employee ID, and missing Full Name are the only conditions
  that skip a row entirely; missing Department is handled differently (see below), and Photo/
  Phone/Email/Address/Emergency Contact never block anything.
- **Schema changes required to actually support what the spec asked for**: `department_id` was a
  required foreign key before this -- "Department (or Unassigned if supported)" genuinely wasn't
  supported. Made it nullable. `email`, `address`, `emergency_contact_name`, and
  `emergency_contact_phone` didn't exist as columns at all; added all four, nullable.
- New `profile_status` on `Employee` -- deliberately a computed accessor, not a stored column,
  since it's fully derivable from existing data (only `department_id` being null matters, as
  `employee_id`/`full_name` are enforced NOT NULL and can never be missing from an already-saved
  record) and therefore can never drift out of sync with what it describes.
- Downloadable template (`EmployeeImportTemplateExport`), a single linear import dialog (Download
  Template -> Upload -> Processing -> Summary, no wizard component), an All/Complete/Need
  Completion filter and visual indicator on the Employees list, and a Dashboard task card that only
  renders when there's actually something to complete.
- Optional `Project` column support during import, using the existing `project_manpower`
  many-to-many relationship -- purely additive, a row with no project match is simply not assigned
  to one yet, never a reason to skip the row.

### Added — Report Export architecture (prepared, not built)

- New `ReportExportInterface` + `ReportTemplateResolver`. `KpiReportExport` (the current generic
  export) now implements the interface as the default/fallback. `ReportController` routes through
  the resolver instead of instantiating the export directly -- plugging in a real company-specific
  Excel template later is one new class plus one line in the resolver, not a rewrite of how reports
  are generated. No actual company templates exist yet, per explicit instruction that they'll be
  provided later.

### Database migrations

```
2026_08_07_100030_add_optional_fields_and_nullable_department_to_employees
```

### Deferred, per explicit instruction

A generalized Import Engine base class (Department/Project/PPE Master/Vendor/Contractor imports)
was not extracted this session -- `EmployeesImport` is the first real example of the pattern, and
abstracting a reusable base class from a single instance tends to guess the wrong shape. Building
that extraction once a second import type is actually needed is more likely to produce a genuinely
reusable engine than doing it speculatively now.

---

## [1.6.7 Beta] — 2026-08-06

Two operational MVP modules requested before Stable promotion: Material Request and PPE
Replacement Request, plus a reusable PDF generation service both build on. Deliberately lightweight
per explicit instruction -- no approval workflow, no procurement/inventory/warehouse integration,
no notifications. Those are all explicitly deferred to post-Stable versions.

### Added — Reusable PDF Generation Service

- New `PdfGeneratorService`, built around `barryvdh/laravel-dompdf` (already declared in
  `composer.json`) rendering plain Blade views rather than canvas-drawn PDFs -- the fastest,
  most flexible way to match "compatibility with existing company paperwork," and the same skill
  (writing a Blade view) every future document type (Daily Report, Incident Report, PTW) would need
  to reuse this service without any changes to the service itself.
- Two traditional, printable form-style Blade templates (Material Request, PPE Replacement
  Request) -- signature lines, dense tables, not modern design, per explicit instruction. Caught and
  removed a reference to a `Company.address` field that doesn't actually exist in the schema while
  writing the first template, rather than let it silently render blank.

### Added — Material Request (MVP)

- New `material_requests` / `material_request_items` tables, models, full CRUD controller, three
  pages (Index/Form/Show), and PDF generation. Deliberately department-agnostic: `department_id` is
  a plain nullable foreign key, nothing in the schema or queries hardcodes HSE, even though HSE is
  the first real user (`canManageMaterialRequests()` permission mirrors the existing
  `canManageDailyReports()` pattern for the same reason).
- Dynamic item table with add/remove rows and a per-item optional reference image upload, on both
  create and edit (with orphaned-file cleanup when an item is removed during an edit).
- Registered in the module toggle registry (`config/modules.php`) and sidebar, following the exact
  registration steps documented in that file from an earlier session.

### Added — PPE Replacement Request (MVP)

- **Architecture finding worth stating plainly**: "Replacement Due" existed only as a *filter* on
  the employee-level Employee PPE list (one row per employee). Multi-selecting individual PPE
  *items* to bundle into a request needs item-level granularity that list was never built for --
  and deliberately shouldn't be, since it's meant to stay a minimal employee selector. Built a new,
  dedicated `Ppe/ReplacementDue` page instead (one row per overdue PPE record, checkboxes, Select
  All) -- this is the only place a Replacement Request can be created from, per explicit
  instruction against a separate manual "create" page.
- New `ppe_replacement_requests` / `ppe_replacement_request_items` tables and models. Deliberately
  does NOT duplicate employee/department/PPE-type data onto the request -- everything auto-populated
  (Employee, Employee ID/NIK, Department, PPE Item) is read live through the existing
  `employee_ppe` relationship instead, so it can't drift out of sync with the source record.
  `project_id` and `quantity` ARE captured directly on the request, since "which project this was
  for" is a point-in-time fact that shouldn't be recomputed later from an employee's
  possibly-since-changed project assignments.
- **PPE Size deliberately NOT included anywhere in this module.** Sizes don't exist in the current
  schema at all (PPE Master doesn't track them yet -- an explicitly deferred future feature).
  Auto-populating a size that doesn't exist would mean fabricating data; left out honestly instead.
- Creating a request flips each selected item's status to `replacement_requested`, keeping the
  existing PPE lifecycle in sync with the new request record -- an item already mid-request can't
  be selected again, since the Replacement Due query only ever surfaces `issued`/`in_use` items.
- Dashboard's "Replacement Due" KPI card repointed from the old employee-level filter to this new
  dedicated page, since that's now where the actual action lives.
- Two more pages (index list, detail view) plus PDF generation, and two new tabs added to the
  shared `ModuleTabNav`-based PPE navigation.

### Fixed — caught while building (not part of the original request)

- Found and fixed the same render-time `setState` anti-pattern (calling `setData` directly during
  render instead of in a `useEffect`) that was caught and fixed once before in a different
  component -- this time in the new Replacement Request creation dialog. Same fix: keyed on the
  actual selected IDs, moved into a proper effect.
- Found a real bug in the Material Request form's own draft/submit logic while building it --
  `useForm`'s `post()` doesn't accept a data override the way it was first written. Fixed using
  Inertia's `transform()` before either prior code shipped.

### Database migrations

```
2026_08_06_100026_create_material_requests_table
2026_08_06_100027_create_material_request_items_table
2026_08_06_100028_create_ppe_replacement_requests_table
2026_08_06_100029_create_ppe_replacement_request_items_table
```

### Deferred, per explicit instruction

Approval workflow, purchasing, inventory, warehouse, stock management, notifications, and any
company document template engine -- all explicitly out of scope for this MVP pass.

---

## [1.6.7 Beta] — 2026-08-05

Desktop UI compaction, two confirmed Form Request bugs, and the first real piece of Multi-Tenant
Foundation (Epic 3). Epic 3 remains far from complete -- see "Deferred" below.

### Fixed — Search Icon Overlap, actual root cause (sixth pass)

Two previous attempts at this exact bug only adjusted padding/icon numbers and never actually fixed
it, because the real cause was never in the numbers -- it was a structural conflict inside the
shared `Input` and `SelectTrigger` components themselves. Found by tracing through Tailwind's own
compilation model rather than guessing at another set of values:

- The shared `Input`/`SelectTrigger` components had a `lg:px-2` (a horizontal-padding *shorthand*)
  in their own base classes, added during an earlier density pass. Every page's search input passes
  a plain `pl-8` (no breakpoint prefix) to make room for the icon. `twMerge` does not treat
  `lg:px-2` and `pl-8` as conflicting, since they're different breakpoint-variant scopes by design
  -- both survive the class merge. But Tailwind compiles `lg:`-prefixed rules into a media query
  block that comes *after* the plain utility rules in the generated stylesheet, so on desktop
  specifically, `lg:px-2`'s 8px silently won the CSS tie over the page's intended 32px, at equal
  specificity. This is why the bug was real, was desktop-specific (exactly where this whole sprint
  is focused), and why neither prior attempt fixed it -- both changed the padding *numbers* without
  touching the actual conflicting rule.
- Removed the horizontal-padding shorthand from `Input`, `SelectTrigger`, and (for full consistency,
  even with no page currently exercising it) `Button` -- kept height/font `lg:` overrides, which
  don't have this specific left/right conflict risk. A plain `pl-8` on any page can now never be
  silently defeated by the component's own internal desktop override again.

### Fixed — Dashboard vertical rhythm (genuine inconsistencies, not just visual impression)

- **Today's Summary -> Statistics Cards had zero margin between them** -- confirmed by reading the
  actual JSX, not assumed from the screenshot description. Every other section transition on this
  page uses `mt-4` (16px); this one gap was genuinely `0`. Fixed.
- **Dashboard hero -> Today's Summary used 12px** while every other gap uses 16px -- brought in
  line.
- **Statistics Cards -> KPI Summary used `mt-5` (20px)**, the one place on the page using a
  different value than the established 16px rhythm. Fixed.
- **Found five `CardTitle` instances across Dashboard (and one more each in Home and Tasks/Show, six
  total) with an explicit `text-sm` (14px) override** that had become *larger* than the shared
  `CardTitle` component's own current default (13px/12px on desktop) after several rounds of
  compaction -- meaning these specific titles had silently regressed to be the largest card titles
  in the app. Removed the stale overrides so they inherit the shared component's actual current
  size, and shrank the icons sitting next to them to match.

### Changed — Remaining per-item polish

- **Hero Summary Card**: greeting reduced one more step; the PPE/LTI alert specifically made to
  read as secondary information (smaller icon, smaller and more muted text) rather than competing
  with the statistics above it, per explicit request.
- **KPI Summary** (`KpiSummaryCard`'s compact variant): card height, icon size, and internal
  spacing reduced further -- the KPI value itself, explicitly protected from reduction, is
  unchanged.
- **PPE Dashboard's "Active PPE by Type"**: row typography and spacing reduced (title/subtitle
  already inherited the shared `Card` component's compact default correctly).
- **Project Manpower chips**: reduced one more step (11px text, tighter padding and gap).
- **KPI Input employee lists**: re-verified still correctly matching the Employee module's exact
  row height and typography from the prior session -- confirmed intact, not re-touched
  unnecessarily.

### Fixed — Final Consistency Pass (fifth pass)

The genuinely valuable finding this session: **three separate, page-local `StatCard`/chip
components had never been touched by any of the four prior compaction sessions**, because each
lives in its own page file rather than the shared `Card` component -- PPE Dashboard's `StatCard`,
Home's own `StatCard`, and Projects' manpower chips. Every previous pass reduced the *shared*
components correctly; these three were simply never visited individually, which is exactly why "PPE
Dashboard KPI cards still feel oversized" and "Home dashboard cards still feel oversized" kept
recurring as separate complaints even after multiple density passes.

- **PPE Dashboard's `StatCard`**: was still at its original size entirely -- `p-5`, 40px icon box,
  18px value. Reduced to match the density already applied elsewhere (14px padding, 36px icon box,
  14px value, small gap added between value and label).
- **Home's `StatCard`** (Active Workforce / Running Projects / Current Period): same situation,
  same fix. The greeting header above it ("Good Evening, Name") and its Current Time display were
  also reduced -- not touched in any prior session either.
- **Project Manpower chips**: reduced height, padding, name size, and close-icon size to read as
  compact tags rather than oversized pills.
- **KPI Input's employee lists** (both the record-entry checklist and the recent-records list)
  brought to the same row height and 13px text the Employee module's own table already uses --
  previously at 14px/12px row height, a real, visible inconsistency between two places showing the
  same kind of data.
- **Search icon vs. placeholder spacing, fixed consistently across 6 files** sharing the identical
  pattern (Employees, Projects x2, PPE Employees, KpiInput, Tasks) -- the icon size and gap had
  never been recalibrated after the input itself shrank across three separate compaction passes,
  leaving proportionally less clearance than when the pattern was first built. Reduced the icon
  slightly (16px->14px) and confirmed real clearance to the input's text start, rather than
  shrinking the input further.

### Added — Vertical Rhythm & Elegance Pass (fourth pass, spacing not sizing)

A genuinely different kind of adjustment than the previous three density passes: this one restores
breathing room rather than removing more of it. Three consecutive compaction passes had reduced
`Card` padding from 20px down to 12px on desktop, and the shared `CardHeader`'s title-to-subtitle
gap had ended up at just 2px -- both objectively too tight, which is what "content feels cramped,
too close to card edges" was actually describing. Explicitly did NOT touch employee names, main
Dashboard numbers, primary statistics, or page titles, per instruction -- every change below is a
spacing addition, not a further size reduction.

- **Shared `Card` component**: title-to-subtitle gap 2px->4px on desktop; content/footer now have a
  small explicit top gap (previously zero, relying entirely on the header's own bottom padding)
  instead of touching directly; overall padding nudged back up slightly (12px->14px). This one fix
  benefits every page using the shared component without further per-page work -- confirmed
  Settings, PPE Master, and Reports have no hardcoded overrides and inherit this improvement
  directly.
- **Dashboard's `PrimaryCard` and `SummaryStat`**: both use hardcoded padding that bypasses the
  shared `Card` (the same pattern found and partly fixed in an earlier session) -- added a small
  gap between the value and its label in both, which previously sat directly touching with no space
  at all. The value/label text sizes themselves are unchanged.
- **PPE Employee Profile's stat cards** (Current/Expired/Total Ever Issued): same treatment -- a
  small padding increase and a value-to-label gap, statistic numbers themselves untouched.

### Reviewed and deliberately left unchanged

Employees Profile's info card, Employee PPE list rows, and search placeholder sizing were all
checked again and found already reasonable from earlier passes -- not touched, to avoid adjusting
things that don't need it just for the sake of making a change.

### Remaining, honestly

I cannot render this in a browser from this environment. Whether 14px padding and a 4px title gap
actually *reads* as "elegant" rather than merely "less tight" is a visual judgment call I can't
verify from here -- a real screenshot comparison against Linear/Notion's own card spacing would be
the honest next check before calling this UI Freeze-ready.

### Added — Final Desktop Balancing (third pass, manual review per page)

Deliberately not a mechanical across-the-board reduction this time -- each area below was reviewed
individually and adjusted only where it still looked oversized, per explicit instruction not to
treat shared components as automatically fixing everything.

- **Sidebar branding block**: the wordmark's internal vertical padding (the same asset property
  already compensated for horizontally in an earlier session) was compounding with container
  padding to create real, measurable extra height before the visible logo -- reduced the box itself
  (96px->72px) rather than only adjusting padding around an unnecessarily large box, plus tightened
  padding on top of that. Sidebar nav text and tab nav also reduced one more step.
- **Employee name hierarchy, reviewed per context rather than uniformly**: the PPE Employee Profile
  header (20px) and the Employees module's own profile card (16px) were both genuinely oversized
  for what's really a name label -- reduced both. The PPE Employee *list* row name was deliberately
  left alone -- at 13px it's already the primary identifying text in a dense, repeated list, and
  shrinking it further would hurt scannability for the most important column in the row.
- **Dashboard hierarchy rebalanced**: title reduced again (18px->16px), and the "Today's Summary"
  greeting was found to be the *same* size as the page's own title (16px each), collapsing the
  visual hierarchy between a page title and a section title -- reduced the greeting specifically to
  restore the distinction, rather than shrinking both by the same amount.
- **Found two more Card padding overrides that bypass the shared component**, the same pattern
  already found once on Dashboard -- Projects' list cards and its Show page's description card both
  hardcode `p-5`, meaning the shared `Card` compaction never reached either. Fixed both directly.
  Swept the rest of the app afterward for the same `CardContent`/`CardHeader`/`CardTitle` override
  pattern; confirmed no other instances exist.
- Bulk-reduced module page titles (`text-xl`->`text-lg`) across the same 20 files that share a
  byte-for-byte identical class string -- verified as safe and non-structural, same approach as the
  prior session's title reduction.
- Search placeholder sizing (GlobalSearch, Employee PPE search) reviewed and left unchanged --
  already at 12-13px from earlier passes, already at a reasonable floor for legible placeholder
  text.

### Remaining, reported honestly rather than claimed resolved

I cannot render this in an actual browser from this environment -- every adjustment above is
reasoned from measured proportions and consistent hierarchy logic, not a visual confirmation. A
real desktop pass (particularly the sidebar wordmark's vertical position, since that compensation
is calculated/estimated rather than pixel-measured after this specific change) is the honest
next step before treating this as verified.

### Added — Desktop Density Optimization (follow-up pass, same day)

A second density pass on top of the first, specifically for desktop -- using `lg:` breakpoint
overrides on interactive/touch components (`Button`, `Input`, `Select`) so mobile/tablet touch
targets are completely unaffected, per explicit instruction. Purely visual elements (`Card`,
`Table`, `Badge`, Sidebar, Topbar) were reduced directly, since those aren't a touch-target concern.

- **Found two things the first density pass had actually missed**: `SelectItem` and
  `DropdownMenuItem` were still at `text-sm` (14px), never reduced in the earlier pass -- brought
  both in line with everything else.
- **Found that several Dashboard components use hardcoded padding overrides that bypass the shared
  `Card` component entirely** (`PrimaryCard`, `HeroSummary`, `SummaryStat`) -- meaning the first
  density pass's `Card` reduction never actually reached the Dashboard's own KPI cards, the exact
  place most likely to be judged "still oversized." Fixed each directly.
- Sidebar nav items (42px->36px), Topbar (64px->56px), Global Search (40px->34px height,
  420px->380px width), Employee PPE rows, Employee Profile avatar (80px->64px) and card padding,
  Employees list avatar (32px->28px) all reduced per the explicit per-page requirements.
- `ModuleTabNav` (shared component) compacted once, affecting all four PPE pages that use it
  simultaneously.

### Fixed

- **Real KPI Category update exception, confirmed with exact root cause.** The route parameter is
  `{kpiCategory}` (camelCase) but `UpdateKpiCategoryRequest` looked up `$this->route('kpi_category')`
  (snake_case) -- always returning null, so `->id` always threw. Fixed using Laravel's recommended
  pattern: since the controller already type-hints `KpiCategory $kpiCategory`, route model binding
  has already resolved the full model by validation time -- `$this->kpiCategory` (the bound
  property) is correct here, not a fragile string-keyed `route()` lookup.
- **Found an identical, previously undiscovered bug via a systematic sweep afterward**:
  `UpdatePpeTypeRequest` had the exact same mismatch (`{ppeType}` vs `'ppe_type'`) -- meaning
  updating *any* PPE Type was always broken, not just the one bug that was actually reported. Fixed
  the same way. Verified `UpdateEmployeeRequest`'s existing `route('employee')` call was already
  correctly matched and needed no change.

### Changed — Desktop UI compaction (applied once in shared components, not per-page)

- `Button`, `Input`, `Select`, `Card`, `Table`, `Dialog`, `Label` all reduced (heights ~36px->32px,
  Card/Dialog padding 20-24px->16-20px, table row padding tightened, several font sizes to ~13px) --
  changed once in each shared component so every page that uses them shrinks consistently, without
  touching individual pages.
- Bulk-reduced page titles (`text-2xl`->`text-xl`) across 21 files that shared a byte-for-byte
  identical class string -- verified as a safe, mechanical, non-structural change.

### Added — Multi-Tenant Foundation (Epic 3, Task 1 only)

- **Found and corrected a wrong assumption before it shipped broken:** initially assumed `User` had
  a `company_id` column: it doesn't. The current architecture is genuinely "one internal
  organization viewing multiple companies' data through UI filters," not true multi-tenant SaaS yet
  -- a real, if uncomfortable, finding worth stating plainly rather than building tenant logic on a
  column that didn't exist.
- Added the actual missing piece: a real, nullable `users.company_id` migration + `User::company()`
  relationship -- nullable because existing internal-staff users legitimately have no single
  company; a future Company Registration flow (Epic 3 Task 2) would create users that genuinely do.
- New `TenantContext` service + `IdentifyTenant` middleware (registered in `bootstrap/app.php`),
  resolving "the current tenant" once per request. Deliberately does NOT add automatic query
  scoping to any existing model yet -- retrofitting that across every controller in one pass is
  exactly the large, risky change to already-working modules this session's rules caution against.
- New `stage` config field (Tester/Beta/Stable) -- previously only ever discussed in conversation,
  never actually stored or displayed anywhere in the app. Now shared via Inertia and shown in the
  About dialog next to the version number.

### Database migrations

```
2026_08_05_100025_add_company_id_to_users_table
```

### Deferred (Epic 3 remains far from complete)

Epic 3 Tasks 2-5 (Company Registration, Subscription Foundation, Landing Website, Permission
finalization) are untouched this session. Each is realistically its own multi-session effort; only
the most foundational piece (Task 1, multi-tenancy) was attempted, and only its first layer at
that -- automatic tenant-scoped querying is explicitly not yet built on top of `TenantContext`.

---

## [1.6.7] — 2026-08-04

PPE UX refinement and foundation work for IOMS's evolution into a configurable multi-company
platform. Version held at 1.6.7 across this and the previous session -- only the Product Owner
controls version progression.

### Fixed — Global modal dropdown fix (Task 1)

- **Root cause, confirmed with exact numbers, not assumed:** `Dialog`'s content used `z-[110]`
  while `Select` and `DropdownMenu` content both used only `z-50`. Radix portals all of these to
  `document.body` as separate elements -- portaling only changes *where* something renders in the
  DOM, not stacking order, which the browser still determines purely by z-index value regardless
  of visual nesting. Any Select/Dropdown opened inside a Dialog was structurally guaranteed to
  render behind it. Fixed by raising `Select`, `DropdownMenu`, `Combobox`, and `GlobalSearch` to a
  single consistent `z-[120]` -- fixed in the four shared components themselves, not patched
  page-by-page, so every current and future use (including "Settings > Add Position" and "PPE >
  Edit PPE" specifically) is covered by the same fix.

### Changed — PPE status review (Task 2)

- Reviewed the requested `Issued`/`In Use` redefinition against the existing v1.5.1 lifecycle
  meaning and found they conflict -- `issued` already means "assigned to an employee, not yet
  confirmed in active use," and every existing row was recorded under that meaning. Redefining the
  status enum in place would silently change what historical data means with no way to tell which
  meaning any given row used.
- What actually blocks "exists in inventory, not yet assigned" is structural, not a naming problem:
  `employee_id` was a required column, so no record could exist without an employee at all. Made
  it nullable (new migration) -- purely additive, every existing row keeps its real employee_id
  unchanged, and the current status lifecycle for already-assigned PPE is completely untouched. No
  current code path creates a null-employee_id row (no "add to inventory" UI exists yet) -- this is
  the foundation a future inventory feature would need, not the feature itself.

### Added — Report Configuration foundation (Tasks 3/4)

- **New `report_configurations` table and `ReportConfiguration` model** -- schema and model only,
  deliberately not the complete Report Builder. Note: the KPI *category* structure was already
  configurable per company since v1.5.0 (`KpiCategory::visibleForCompany()`, which
  `KpiReportService` already reads dynamically rather than assuming a fixed set) -- what was
  actually missing was a way to store the other two axes from the stated future vision (Group By,
  Export Type) at all. No controller, routes, or Settings UI are built yet; a future session adds
  those on top of this table without needing a schema change first.
- `Company::reportConfigurations()` relationship added, matching the existing
  `departments()`/`employees()`/`projects()` convention.

### Database migrations

```
2026_08_04_100023_make_employee_id_nullable_on_employee_ppe
2026_08_04_100024_create_report_configurations_table
```

Both purely additive -- neither changes the meaning or values of any existing data, and nothing
currently in the app depends on either new capability yet.

---

## [1.6.6] — 2026-08-03

PPE module restructure: employee-centric workflow, plus a navigation redesign completed in a
follow-up pass within the same version (version intentionally held at 1.6.6 across both — only
the Product Owner controls version progression).

### Changed — Navigation redesign (follow-up pass)

- **Sidebar**: reverted to a single flat "PPE" entry -- the Dashboard/Employee PPE/PPE
  Master/Reports split moved into a new shared **`PpeTabNav`** top-navigation component instead,
  rendered inside the module itself. Built as its own reusable component specifically so the same
  navigation pattern can be reused for future modules (Medical, Training, Asset, License, Fleet,
  Equipment) without copy-pasting it.
- **Dashboard cards now link to Employee PPE, not Reports.** Clicking "Expiring Soon" (etc.) lands
  on "which employees have this problem," matching the stated philosophy that Dashboard is a
  monitoring center and Employee PPE is the employee selector.
- **Two new KPI cards** with real backend queries: **Replacement Due** (items already in the
  replacement workflow) and **No PPE Assigned** (employees with zero PPE records at all, via
  `doesntHave('employeePpes')`).
- **Employee PPE list simplified** to exactly Name / Department / Total Assigned PPE per explicit
  instruction -- removed the three-badge status breakdown from the previous pass, since that
  duplicated information the Dashboard already shows. Added a "Showing: X" banner so arriving from
  a filtered Dashboard card is still legible.
- **Reports page cleaned up**: removed the now-redundant Dashboard/PPE Master buttons (handled by
  the tab bar) and removed the search-based Issue PPE dialog and its ~165 lines entirely -- issuing
  now only happens from an employee's PPE profile, so that dialog was genuine dead code, not kept
  "just in case."
- **Employee PPE Profile reorganized** into Overview / Current PPE / History / Management Actions.
  Added a **Renew PPE** action -- reuses the existing `ppe.complete-replacement` endpoint (it's
  already the right operation: archive the current record, issue a fresh one with a new date and
  auto-computed expiry) rather than building parallel logic for what is structurally the same thing
  as Replace.

### Fixed

- A real, latent ambiguous-column bug in `PpeController::employees()`: unqualified `company_id`/
  `department_id` filters become genuinely ambiguous once combined with `orderedForDisplay()`'s
  joins (both `employees` and `departments` have `company_id`; both `employees` and `positions`
  have `department_id`) -- matches a previously-documented regression noted in the model's own
  comments. Fixed by qualifying both columns.

### Added — PPE employee-centric restructure (first pass)

- New `PpeController::employees()` / `employeeProfile()` methods, `Employee::employeePpes()`
  relationship (didn't exist before), two new routes (`ppe.employees`, `ppe.employees.show`) --
  every existing PPE route kept unchanged.
- New pages: `Ppe/Employees.jsx`, `Ppe/EmployeeProfile.jsx`.
- Issue PPE from an employee's profile no longer requires searching for/selecting an employee --
  the employee is already known; the dialog only asks for PPE Item, Issue Date, Expiry Date
  (optional override), and Remarks.
- Employees module gained a "View PPE" button (Employee stays responsible only for employee
  information; this is just a doorway into the PPE module for a specific employee).

### Fixed — Select component bug (found during the same session)

- Root cause: the PPE Issue dialog initialized a controlled Radix Select's value as an empty
  string -- a documented pitfall in this Select version. Confirmed by contrast with every other
  Select in the codebase, which already uses a safe sentinel value. Swept the whole app and found
  **8 more latent instances** of the same anti-pattern (`KpiInput`, `Employees/Form`,
  `Projects/Form`, `Settings`'s Department dialog) and fixed all of them.

---

### Fixed — Browser QA stabilization pass (follow-up)

- **Real `ReferenceError: listUrl is not defined`** -- confirmed, not assumed. A prior session
  renamed this function to `employeesUrl` but missed one remaining call site (the "Expiring Soon"
  card's inline "view all" link). Fixed.
- **White screen flash on first navigation to an unvisited page.** Root cause: `import.meta.glob`
  defaults to lazy mode, so every page is its own dynamically-imported chunk fetched over the
  network the first time it's visited in a session -- and Inertia's progress bar only tracks the
  server round-trip, not that separate chunk fetch/parse step, so the bar can finish while the
  page's JS is still loading. Switched to `{ eager: true }`, bundling every page upfront at build
  time. This fixes the flash for every "first visit to a route this session," not just PPE --
  PPE was just where it happened to get noticed first.
- **`favicon.ico` 404** -- no favicon was ever declared at all, so browsers were falling back to
  their default auto-request for `/favicon.ico`, which doesn't exist. Added an explicit
  `<link rel="icon">` reusing the existing brand icon PNG (no `.ico` conversion needed, modern
  browsers support PNG favicons directly).
- **Employee PPE list converted from cards to a compact horizontal list** per explicit revision --
  Name / Department / Assigned PPE Count in one row, entire row clickable, no separate button.

### Verified via regression check (not re-implemented)

Sidebar single PPE entry, PPE Dashboard, PpeTabNav on all four module pages, Dashboard KPI
card→Employee PPE filtering, Employee Profile, Issue/Renew/Replace PPE, PPE Master, and Reports
all confirmed intact via direct code inspection (route/prop-contract cross-checks, not just visual
assumption).

### Known gap, honestly reported rather than silently claimed working

**Return PPE does not exist.** It was explicitly deferred as "future-ready, not built" several
sessions ago and remains unbuilt -- this session's regression checklist asked to verify it works,
and the honest answer is that there is nothing to verify. Building it would be a new feature, out
of scope for a stabilization-only session per this session's own explicit instruction.

### Fixed — QA re-verification pass (second follow-up)

The white-flash and Console findings were re-reported after the fixes above were already in place
and verified unchanged. Rather than assume the prior fix was sufficient, went looking for an
*additional* contributing cause -- found one: Inertia's progress bar had no explicit `delay`
configured, so it used the library default (~250ms) before appearing at all. Any navigation slower
than instant but faster than that threshold had a genuine dead zone with zero visual feedback,
which reads as a blank flash. PPE Dashboard runs more queries than any other single page in the
app (several counts plus a `doesntHave` subquery added in a recent session), making it the most
likely page to land in exactly that window -- though the fix (`delay: 0`) applies app-wide, not
just to PPE. Also verified `employee_ppes.employee_id` is properly indexed (via `foreignId()`,
Laravel's default), ruling out a missing-index cause for that specific subquery.

---

## [1.6.5] — 2026-08-02

About Dialog rebuilt from scratch after four consecutive sessions of unresolved "only blur shows"
reports. Sidebar branding hierarchy fixed. Six new shared foundation components.

- Rewrote `AboutDialog.jsx` from a blank slate -- removed the decorative watermark and nested
  z-index wrapper div entirely, leaving the plainest possible Radix Dialog structure with nothing
  custom left to interact badly with anything else.
- Sidebar wordmark enlarged substantially to compensate for a real, measured property of the
  wordmark asset (its visible content occupies only ~27% of its own canvas height).
- Six new shared components: `PageHeader`, `SectionHeader`, `EmptyState`, `LoadingState`,
  `StatusBadge`, `StatCard` -- built for future pages, not retrofitted into existing ones.
- Login page spacing refined.

---

## [1.6.4 Stable] — 2026-07-29

QA/stabilization pass. No new features, no business logic changes -- bug fixes and Dark Mode
completion only.

### Fixed

- **Dark Mode was fundamentally incomplete at the component-library level.** `Button`, `Input`,
  `Select`, `Table`, `Badge`, `DropdownMenu`, `Dialog`, `Label`, and `Checkbox` had **zero** `dark:`
  classes between them. Since these are the base primitives every page in the app is built from,
  this meant most interactive UI (every button hover, every table row, every select dropdown, the
  profile menu, every modal) was still rendering flat light-mode colors regardless of theme --
  fixed centrally in each shared component rather than page-by-page.
- Verified Dashboard navigation (Pending Tasks, PPE alert, Quick Actions, KPI cards), Sidebar
  behavior (active/hover/nested-menu/collapse), Company Logo fallback, and Task Engine routes --
  all confirmed already correct from prior releases, left unchanged.
- Swept for dead code (unused imports, TODO/FIXME markers, placeholder UI) -- none found.

### Verified, not changed

`Card` and Sidebar nav dark-mode text (fixed in v1.6.4 Beta) were re-checked and confirmed still
correct.

---

## [1.6.4] — 2026-07-29

Two objectives: fix real UI/dark-mode gaps from v1.6.3, and build the first foundation of the
Universal Task Engine. Deliberately scoped to just the `tasks` table -- comments, attachments,
history, timeline, notifications, and automatic overdue detection are explicitly out of scope for
this version (see ROADMAP.md for the planned follow-up).

### Added — Universal Task Engine (foundation)

- **`tasks` migration** — UUID, task number, title, description, priority, status, task type,
  task source, a lightweight polymorphic link (`related_module` + `related_record_id` — a plain
  string + generic ID, not a formal Eloquent polymorphic relation, so any future module can attach
  tasks to its own records without this table needing to know about them), company ID, workspace ID
  (nullable, no `workspaces` table exists yet — stored as a plain column ready for a real foreign
  key later rather than blocking the whole engine on a feature that doesn't exist), assigned user,
  creator, due/start/completed dates, soft deletes.
- **`Task` model** — status/priority constants, `is_overdue` as a *computed* attribute (a due-date
  comparison, not a stored status) so it can never go stale, `assignedTo`/`openStatus` scopes.
- **`TaskService`** — all business logic lives here, not in the controller: create, update, delete
  (soft), assign, change status (auto-manages `completed_date`), and task number generation
  (`TSK-{year}-{00001}`, sequential per year, matching the existing per-year numbering convention
  used elsewhere in the app).
- **`StoreTaskRequest` / `UpdateTaskRequest`** — dedicated Form Requests, any authenticated user can
  create/view (general-purpose engine for future modules), editing/deleting is restricted to the
  task's creator, its assignee, or an admin.
- **`TaskController`** — standard Laravel resource controller (index/create/store/show/edit/update/
  destroy), delegating all logic to `TaskService`. Index supports search, sort, pagination, and
  priority/status/assigned-user/due-date filters.
- **Frontend**: `Tasks/Index.jsx` (list, search, filters, sort, pagination), `Tasks/Show.jsx` (task
  detail), `Tasks/Form.jsx` (shared create/edit) — all reusing the existing design system
  (Table/Card/Select/Badge components, the same filter-in-URL pattern already used by PPE Index and
  Daily Reports Index).
- **Dashboard integration**: a genuine query (`Task::assignedTo($user)->openStatus()`, soonest due
  date first, limit 5) powers a real "Pending Tasks" widget — only rendered when the user actually
  has open tasks assigned, so it doesn't add an empty card for anyone not yet using the engine.

### Fixed — Real bugs found during verification, not assumed

- **Card text was illegible in Dark Mode.** `CardTitle`/`CardDescription` had zero `dark:` text
  variants at all — against the new `dark:bg-slate-900` card background, the light-mode text colors
  would have rendered as dark-gray-on-dark-slate, i.e. nearly invisible, on *every* Card in the
  entire application. Fixed centrally in the one shared component.
- **Sidebar navigation text had the same gap** — active/inactive/nested-menu text and icons had no
  dark variants against the `dark:bg-slate-950` sidebar background. Fixed.
- **Company Logo showed nothing (not a broken image, but not the requested fallback either)** when
  no company-specific logo was uploaded. Now falls back to the default IOMS brand icon.
- **Global Search was still a fixed 208px**, not yet reflecting the requested 500px maximum. Widened
  to a flexible `w-64 max-w-[500px]`.

### Verified already correct (no changes needed)

Dashboard/Sidebar/Topbar background colors, Hero max-height (220px) and gradient, and Topbar
border+shadow were all already implemented correctly and matched spec exactly on inspection --
left unchanged rather than redone.

### Database migrations

```
2026_07_29_100022_create_tasks_table
```

---

## [1.6.3] — 2026-07-28

Main-interface usability pass: Dashboard, Sidebar, Top Navigation, Theme, and Company Branding.
Several requested Dashboard sections (Pending Tasks, Pending Approval) and Top Navigation items
depend on subsystems that don't exist in this application (Task Engine, Approval Engine,
Incidents/Inspections/Permits/Assets modules) — these were intentionally left out rather than
built with fabricated data. See "Explicitly not built" below for the full list.

### Added

- **Dashboard**: company logo (42px) + name + subtitle added to the hero, only rendered when a
  client has actually uploaded a logo via Settings; **Quick Actions** card with real create-page
  shortcuts (New Project, Daily Report, Employee, Issue PPE).
- **Sidebar**: version number display (reads `config/ioms.php`, never hardcoded); genuine
  nested-menu architecture (expand/collapse, `localStorage`-persisted state across refresh) — no
  current nav item has children yet since no module needs a sub-menu, but the mechanism is real
  and tested, ready for one.
- **Top Navigation**: real Global Search (new `GlobalSearchController` + `GlobalSearch` component)
  across Employees and Projects by name — deliberately does not search
  Incidents/Inspections/Permits/Assets, since those modules don't exist.
- **Authentication**: Forgot Password / Reset Password, using Laravel's built-in `Password` broker
  (the `password_reset_tokens` table and `Notifiable` trait already existed from the original
  scaffolding, so this was genuinely additive). `MAIL_MAILER=log` by default, so reset links write
  to `storage/logs` until real SMTP is configured.
- **Theme**: a real, working Dark Mode toggle mechanism (`useTheme` hook, `localStorage`
  persistence, anti-flash inline script in `app.blade.php`, toggle button in the top bar).
  **Known, disclosed gap**: the `.dark` CSS variables already existed but almost nothing in the
  app actually references them — components hardcode literal colors (`bg-white`,
  `text-graphite-900`) instead of the theme-aware tokens. Toggling dark mode today changes very
  little visually; converting the app's components to theme-aware classes is a separate, larger
  follow-up.
- **Company Branding**: Settings > Branding extended with SVG logo support (Laravel's `image`
  validation rule excludes SVG by default — fixed to an explicit `mimes:` list), Favicon upload,
  Company Short Name, and Footer Copyright Text — all shared via `HandleInertiaRequests` alongside
  the existing logo/name/subtitle.

### Changed

- Dashboard hero: title/subtitle font sizes reduced (~15%), vertical spacing tightened, watermark
  opacity increased to 6% (within the requested 5-8% range).
- Sidebar: item spacing loosened slightly for better scannability.
- Top bar height reduced (64px → 56px).

### Flagged, not silently changed

- **Sidebar Profile/Logout placement**: this release's spec asked to move Profile/Logout back into
  the sidebar bottom area. That directly reverses an explicit "remove duplicate account info, keep
  it only in the top bar" instruction from several releases ago (v1.5.1). Left the current
  placement (top bar only) unchanged rather than silently re-introduce that duplication — needs an
  explicit decision either way before changing.

### Explicitly not built (would require fabricating data)

- Pending Tasks / Pending Approval sections — no Task Engine or Approval Engine exists in this
  application.
- Breadcrumbs / Workspace Name in the top bar — no multi-workspace concept exists.
- Notification dropdown with categories/read/unread/archive — the existing notification badge
  (real PPE alert count) was kept as-is rather than built out into a full center without a real
  backing notifications table.

### Database migrations

None — every change in this release is code-only.

---

## [1.6.2] — 2026-07-27

Root-cause fixes for watermark visibility (found the real bug, not a cosmetic tweak), exact
pixel/spacing specifications, consistent branding text, and a proper searchable Combobox for Daily
Report's Department field.

### Fixed — Watermark visibility (Dashboard, Home, About)

Traced the actual DOM nesting instead of assuming the previous implementation was correct:

- **Dashboard**: the watermark sat inside a `relative overflow-hidden` wrapper whose real content
  height (~250-300px, just the header row + Today's Summary) was far shorter than the watermark
  itself (450px) -- `overflow-hidden` was silently clipping most of it. Removed `overflow-hidden`
  from that specific wrapper (kept `relative` for correct positioning); the true outer page wrapper
  still safely contains any edge bleed since it spans the whole page.
- **Home**: the same root cause -- a 544px watermark inside a hero block only ~250px tall. Changed
  that container from `overflow-hidden` to `overflow-x-hidden`, which keeps the horizontal
  edge-containment this section needs (the watermark is offset past the right edge) without
  clipping its height.
- **About dialog**: a subtler variant of the same bug class. The watermark used a *negative* `top`
  offset (`top-0 -translate-y-1/4`) inside an `overflow-y-auto` container. `scrollTop` can never go
  negative, so that portion was permanently unreachable via scroll -- not merely clipped, truly
  inaccessible. Repositioned to start at `top-0` with no negative offset.
- Added an `opacity` override prop to `BrandWatermark` so Dashboard can use exactly 4% (per this
  release's exact spec) while Login/Home/About keep the existing 3% global default -- previously
  opacity was only configurable globally.

### Changed — Exact specifications (no estimates)

- Login: wordmark height exactly 70px (was 112px); logo-to-subtitle gap exactly 16px
- Sidebar: width exactly 240px (was 250px, content padding synced); logo 70px; subtitle 11px;
  logo-to-subtitle gap 16px; subtitle-to-navigation gap exactly 24px (split 12px+12px around the
  existing divider)
- Dashboard hero watermark: exactly 450px wide, exactly 4% opacity

### Changed — Branding consistency

- "Developed by {personal name}" → **"Designed & Developed by {company}"** on Login, Sidebar
  footer, and About -- swept the whole app afterward and confirmed no other occurrences remain.
  Removed the now-redundant separate "Powered by" row from About (it showed the identical value).

### Added — Daily Report searchable Combobox

- New reusable `Combobox` component: type-ahead suggestions from the official Department master
  list, while free text is always still valid and is exactly what gets saved (never restricted to
  the list) -- typing "hs" surfaces "HSE" as a suggestion without forcing a selection. Not a
  traditional `<select>`. Handles the classic combobox pitfall of a suggestion click losing to the
  input's blur event (`onMouseDown` + `preventDefault`).

### Verified, not assumed

- Re-swept the entire codebase for the `PpeController.php`-style comment-delimiter bug (fixed last
  release) using the corrected method (counting all `/*`, not just `/**`) -- confirmed clean; the
  two files it flags are known, previously-verified false positives from string literals
  (`accept="image/*"`, `import.meta.glob('**/*.jsx')`).
- Full brace/paren balance across every PHP and JS/JSX file; every `route()` call cross-checked
  against `web.php`; Daily Report's controller-to-page prop contract confirmed for both
  create and edit.
- The About dialog's "only shows blur" symptom could not be independently reproduced in code review
  beyond the watermark positioning bug above and the `PpeController.php` parse error fixed last
  release -- both are real, fixed issues that plausibly explain it, but this could not be confirmed
  with a running browser from this environment.

### Database migrations

None — every change in this release is code-only.

---

## [1.6.1] — 2026-07-26

Bug fixes first, then a further branding/enterprise-polish pass on Login, Sidebar, Dashboard, and
About.

### Fixed

- **Real PHP parse error in `PpeController.php`.** A docblock comment contained the literal
  fragment `replacement_*/`, which PHP's lexer reads as the comment's closing delimiter --
  everything after it (including "archived) -- never used for..." and part of the following
  method) was being parsed as stray code outside the comment, which would fail to compile. Fixed
  by rewriting the comment without any embedded `*/` sequence. Swept the entire codebase for the
  same pattern afterward (corrected method: counting all `/*` occurrences, not just `/**`, since
  plain JS/JSX comments use a single asterisk) -- confirmed this was an isolated instance. Two
  unrelated files flagged by the sweep were verified as false positives (`accept="image/*"` and
  an `import.meta.glob('**/*.jsx')` pattern -- both are string literals containing `/*`-like
  characters, not actual unterminated comments).
- **No changes were needed to fix the reported About dialog issue directly** -- the component's
  own code was already structurally sound (verified by inspection). The PHP parse error above is
  the more likely root cause of broader instability reported during testing.

### Changed

- **Sidebar**: resized to 250px (from 256px) now that the wordmark is the dominant visual element
  -- a narrower rail with a larger logo reads more confident than a wide one with a small logo;
  logo enlarged to 70px; subtitle refined to 11px/uppercase/3px letter-spacing.
- **Login**: added a soft gradient background, two blurred gradient blobs, and two subtle
  geometric outline accents (very low opacity, brand-blue/graphite only, no color variety);
  wordmark enlarged further (96px → 112px).
- **Dashboard**: watermark enlarged; added a **"Today's Summary"** operational snapshot inside the
  hero (Employees, Active Projects, Lost Time Incidents, and PPE Alerts, all real tracked data --
  "Pending Inspections" was requested but no Inspection module exists yet, so PPE Alerts was used
  instead rather than inventing a number with no data behind it); added a warning/all-clear banner
  driven by the same real figures.
- **New "Top Department Workload" analytics card** -- ranked list of departments by total KPI
  record volume for the year. Reuses real, already-tracked KPI data; there's no "Tasks" figure
  since no task-tracking module exists.
- **Statistic cards**: consistent floating/glass treatment (larger radius, softer shadow, hover
  lift, subtle `backdrop-blur`) applied to Dashboard's primary cards, the KPI summary grid (both
  variants), Home's stat cards, and the PPE Dashboard's stat cards.
- **About dialog**: added Build Number, License, Website, Support, and Documentation fields.

### Database migrations

None — every change in this release is code-only (bug fix, config, layout, and styling).

---

## [1.6.0] — 2026-07-25

Enterprise UI refresh: stronger branding presence on Login/Sidebar, a real fix for the About
dialog's scrolling, centralized application configuration, and several UX cleanups.

### Added

- **`config/ioms.php`** — single centralized source of truth for `version`, `edition`,
  `release_date`, `developer`, `company`, `copyright_year`, `whats_new`, and a new
  `version_history` list. Replaces `config/version.php` entirely (verified first: no version
  strings were ever actually in `PpeController.php`, despite that being described as the current
  pain point -- version info has lived in one config file since v1.3.2). The shared `version`
  Inertia prop keeps the exact same shape as before, so no page that already consumed it needed to
  change.
- **About dialog: Version History** section, sourced from `config('ioms.version_history')` --
  the last several releases with date + one-line summary, below the existing "What's New".
- **Watermark support for the About page** (`about_watermark_enabled`), matching Login/Home/Dashboard.

### Fixed

- **About dialog couldn't scroll.** `DialogContent` had no `max-height` or `overflow` set at all --
  on smaller screens, or once Version History was added, content could extend past the viewport
  with no way to reach it. Fixed with `max-h-[85vh] overflow-y-auto`, the same pattern already used
  by other scrollable dialogs elsewhere in the app.
- **`BrandWatermark`'s blur was a single hardcoded value reused at three very different render
  sizes** (256px–640px), making it imperceptible at the largest sizes and comparatively too strong
  at the smallest. `blur` is now a per-usage prop, sized to match. Also dropped the unconditional
  `grayscale`, which was muting the brand's actual blue for no benefit at these opacity levels --
  the increased visual impact requested for the watermark comes from fixing these two things, not
  from raising opacity past the requested 2-3% range.

### Changed

- **Login**: wordmark doubled in size (48px → 96px); watermark blur fixed to actually read as soft
  at 640px.
- **Sidebar**: wordmark increased again (32px → 40px); more vertical breathing room.
- **Sidebar footer**: simplified to "© {year} IOMS Enterprise / All Rights Reserved. / Powered by
  {company}" — less cluttered than the previous name+edition+version+developer stack.
- **Home hero**: headline text unchanged per instruction; added a second, smaller decorative
  gradient blob (matching Dashboard's existing treatment) and enlarged/re-blurred the watermark for
  more presence.
- **Home greeting**: now "{Good Morning/Afternoon/Evening}, {first name} 👋" — branding (wordmark +
  subtitle) removed from this spot entirely, since it's already the sidebar's job.
- **KPI Input**: both Department filter labels simplified from parenthetical
  explanations ("Department (browse only -- does not affect your selections)") to plain
  "Department" — functionality unchanged, this was a copy/UI simplification only.

### Verified, not changed

- `app/Http/Controllers/PpeController.php` was not modified in any way (per instruction) --
  confirmed before starting that it contains no version-related code to begin with.

---

## [1.5.4] — 2026-07-24

Visual QA/refinement pass on the v1.5.3 branding integration. Pure polish — no functionality
changed, no navigation changed, no spacing changed outside what refining the branding required.

### Changed

- **Sidebar**: wordmark increased 24px → 32px; header changed from a cramped fixed `h-16` to
  auto-height with generous vertical padding (`py-7`), so the branding block reads as the
  sidebar's visual identity rather than a squeezed-in corner logo.
- **Login page**: wordmark increased 36px → 48px; more vertical breathing room above the form
  (`mb-8` → `mb-10`).
- **Home hero**: headline reduced from landing-page scale (`text-7xl font-black`, 72px/900) to an
  enterprise-dashboard scale (`text-5xl font-bold`, 48px/700) with tightened surrounding spacing
  and a proportionally smaller watermark — reads as a confident dashboard heading, not a marketing
  splash.
- **Home greeting**: fixed a genuine inconsistency — the line under "Good Morning/Afternoon/Evening"
  was rendering the plain-text company name (which can literally read "IOMS"), while every other
  page had already moved to the real `BrandWordmark` image. Now shows the actual wordmark + subtitle,
  matching the rest of the app.
- **Dashboard**: added a subtle premium background — two large, softly blurred, very low-opacity
  (5-6%) gradient blobs in opposite corners. Purely decorative, non-interactive, does not affect
  readability or any existing layout/spacing.
- **About dialog**: hierarchy refined to Icon → Wordmark → full application name → Edition badge →
  description, then the existing Version/Release/Developer details below.

### Verified

- Swept the entire frontend for any remaining plain-text "IOMS" — the only occurrences are `alt`
  attribute fallbacks on `BrandWordmark`/`BrandIcon` themselves (correct: alt text is for
  accessibility/broken-image fallback, not visible rendered text).
- Confirmed no empty-state message anywhere references the brand name inappropriately.

---

## [1.5.3] — 2026-07-23

Official brand assets (wordmark + icon) integrated via a new centralized branding system. No
functionality changed; no existing layout redesigned.

### Added

- **Official brand assets committed to the project** (`public/branding/wordmark.png`,
  `public/branding/icon.png`) — the exact provided files, used directly, not recreated or traced.
- **Three reusable branding components** — `BrandWordmark`, `BrandIcon`, `BrandWatermark` — the
  only place in the entire app that ever references an image path for branding. No page hardcodes
  `<img src="...">` for the logo/icon/watermark anymore.
- **`config/branding.php`** — static defaults (shipped asset paths, watermark defaults). Actual
  effective values (with room for a future admin override) are resolved once per request in
  `HandleInertiaRequests` as a new `branding` shared prop, the same pattern already used for
  `company`/`version` — never baked into `config()` directly, since `config:cache` in production
  would freeze anything DB-driven into a static file.
- **Architecture-ready for admin-editable branding** (not built yet, per explicit instruction):
  `company_settings` keys `brand_wordmark_path`, `brand_icon_path`, `watermark_enabled` (+ 3
  per-context variants), `watermark_opacity` are already read with sensible fallbacks; a future
  Settings > Branding page only needs to write to these keys, no other code changes.

### Changed

- **Sidebar**: replaced the icon + "IOMS" text block with the wordmark alone (icon removed per
  spec), subtitle shown smaller underneath, still not hardcoded — reads the same `company.subtitle`
  as before.
- **Login page**: large brand icon watermark (~3% opacity, centered, behind everything, pointer
  events disabled) with the wordmark alone in the foreground — no separate icon above it anymore.
- **Home page**: hero watermark now uses the official brand icon (was a placeholder ShieldCheck
  icon before), repositioned center-right per spec.
- **Dashboard**: gained the same subtle watermark treatment (purely decorative, absolutely
  positioned — no existing spacing/layout touched).
- **About page**: now shows both brand assets together with the full application name and
  description underneath — the official product identity page.

### Notes

- The existing single-logo upload in Settings > Branding (`company_logo_path`/`logo_url`,
  shipped in v1.5.2) is untouched and still works — it's simply no longer what's rendered in the
  spots the new two-asset Wordmark/Icon system now covers. Left in place rather than removed,
  since removing working functionality wasn't asked for.
- No Settings UI was added for uploading a custom wordmark/icon, per explicit instruction —
  only the resolution architecture (override-or-fallback) is ready for it.

---

## [1.5.2] — 2026-07-22

A QA/product review pass: fully dynamic KPI Dashboard, a real root-cause fix for image uploads,
a reusable upload architecture, and a final branding audit.

### Fixed — Root cause of "uploads not functioning" (Branding Logo, Daily Report Photos, Employee Photos)

The backend upload/storage code was actually fine. The real bug: `photoUrl()` / `url()` on
`Employee`, `DailyReportPhoto`, and the company logo were **plain PHP methods**, which Eloquent
never serializes to JSON/Inertia — so no page ever actually had access to display them, and every
page that tried worked around it by hand-reconstructing `/storage/{path}` strings directly in
JSX. Converted all three into real Eloquent accessors (`$appends`), and — the part that actually
had zero effect before — **wired the logo into the sidebar, Login page, and About dialog**, all of
which previously showed a hardcoded icon unconditionally, regardless of whether a logo existed.
Added a real "delete existing photo" endpoint for Daily Reports (previously only add was possible).

### Added — Reusable image upload architecture

- `ImageUploadField` (single image: preview, replace, remove) — used by Branding Logo and Employee
  Photo; ready for any future single-image upload (Company Logo, Asset Photo, etc.)
- `MultiImageUpload` (multiple images: grid preview, remove individual new-or-saved images) — used
  by Daily Report Documentation; ready for future multi-image needs (Project Documentation, PPE
  Photos, Incident Evidence, Permit Attachments)

### Changed — Fully dynamic KPI Dashboard (no hardcoded widgets)

- `KpiCategory` gained `show_on_dashboard`, `count_in_dashboard_stats`, `requires_approval`,
  `icon`, `color`. The Dashboard's summary cards and monthly trend chart are now generated from
  `KpiCategory::scopeDashboardVisible()` (active + show_on_dashboard + sort_order) — creating a new
  category (e.g. "LSA") and enabling it makes its card appear immediately, no code change.
- Icon/color are genuinely data-driven: `KpiSummaryCard` resolves a string icon name via a small
  lookup table (`resources/js/lib/iconMap.js`) instead of a hardcoded per-category-code map. An
  admin who doesn't set a custom icon/color gets a sensible default based on Incident-vs-Positive
  type, computed server-side (`getEffectiveIconAttribute`/`getEffectiveColorAttribute`).
- Settings > KPI Categories dialog expanded with all six requested toggles, plus an icon picker
  and a native color picker.

### Changed — Dashboard is now a navigation hub

- Every primary card (Employees, Active Projects, Companies, Current Period) and every KPI
  category card now links somewhere real: Employees → Employees page, Active Projects → Projects
  page, Companies → Settings (now deep-links to the Companies tab via `?tab=companies`), Current
  Period and every KPI card → **new** KPI Records page.
- **New KPI Records page** (`/kpi-records`) — a flat, filterable list of individual KPI
  occurrences (date, employee, department, category), distinct from the existing Reports page
  (an aggregated department × category matrix). This is the actual destination "Click FAC → see
  every FAC this period" needs, which didn't exist before.

### Changed — Module Visibility wording

Renamed "Modules" → "Module Visibility" with explicit copy clarifying this controls visibility of
*existing* modules only — it does not create new modules, which still require development.

### Changed — Project Manpower

Add Manpower dialog gained a Department filter and a search box above the employee list.

### Fixed — Final branding audit

Dashboard subtitle ("HSE KPI overview across all departments" → "Operational KPI Overview Across
All Departments"), and the browser tab title suffix, which was reading from `VITE_APP_NAME` — a
**build-time** environment variable baked into the compiled bundle — instead of the live Branding
Setting an admin can change at runtime. Now tracks the current `company.name` via Inertia's
`navigate` event so a renamed application shows correctly in the tab title without a rebuild. Also
made Excel/PDF export metadata genuinely dynamic: it now reads the live company name at export
time (`WithProperties`) rather than from `config()`, since `config:cache` in production would have
frozen any Branding-Setting-derived value into a static file that never updates again.

### Database migrations (all additive)

| Migration | Purpose |
|-----------|---------|
| `2026_07_22_100021_expand_kpi_categories_configuration` | Adds show_on_dashboard, count_in_dashboard_stats, requires_approval, icon, color |

### Known limitations for future releases

- `requires_approval` is stored but not yet enforced — no approval workflow exists yet.
- Module Visibility remains a navigation-level toggle, not a hard access-control boundary (a
  hidden module's routes are still technically reachable by direct URL) — noted since v1.4.0,
  still true.
- Dashboard's two named leaderboard cards ("Most BBS Report," "Most TBM Attendance") still
  reference those two specific categories by code; a company that renames/removes them sees no
  data there rather than an equivalent generalized leaderboard.

---

## [1.5.1] — 2026-07-21

A full consistency and polish pass: complete branding audit, PPE lifecycle redesign, Daily Report
generalization, and UX de-duplication. Not a new-features release — see `FINAL GOAL` in this
release's spec: "enterprise polish, branding consistency, UX clarity... eliminating all remaining
legacy branding."

### Fixed — Branding audit (complete sweep, not just spot-fixes)

Every remaining occurrence of "Shipyard Management System," "SAFETY LOG," and "HSE Operations
Platform" was searched for project-wide (not just the obviously user-facing pages) and corrected,
including places a normal pass would miss:

- **Browser tab title fallback** (`resources/views/app.blade.php`) — literally still read
  `config('app.name', 'SAFETY LOG')` since the very first version; never caught by any previous
  rebrand pass.
- **Excel/PDF export metadata and footer** — every exported KPI report (Excel document properties:
  creator, company, description; PDF footer) still said "SAFETY LOG" / "Shipyard HSE Management."
  Fixed in `config/excel.php` and `resources/views/exports/kpi-report-pdf.blade.php`.
- **Seeded default account emails** — `admin@safetylog.local` and friends are now
  `admin@ioms.local`, `hse@ioms.local`, `hrd@ioms.local`, `manager@ioms.local`. `UserSeeder` was
  rewritten with explicit three-state idempotent logic (fresh install / needs migration / already
  migrated) so this can never create a duplicate account or crash on a unique-constraint violation
  on a second seed run.
- **Database backup filename**, **Login page email placeholder**, **`.env.example`** mail-from
  address and section comments, **`tailwind.config.js`** and **migration docblock comments** —
  all updated or simplified to drop the old-branding lineage entirely (per this release's explicit
  goal: the codebase should read as if IOMS "from day one").
- **Intentionally left alone**: `CompanySettingSeeder`'s `$previousDefaults` array, which contains
  the literal strings `"Shipyard HSE Department"` and `"Shipyard Management System"` — these are
  functionally required for the upgrade-detection logic (identifying installs still on an old
  default so they can be auto-upgraded) and removing them would break that logic. Also left alone:
  genuine historical records in `CHANGELOG.md` and the industry-name "Shipyard" in the Home hero's
  target-industries list — neither is leftover branding.

### Fixed — Database

- **`Unknown column 'company_id' in 'kpi_categories'`**: `KpiCategorySeeder` now defensively
  checks `Schema::hasColumn()` before referencing the column, so it degrades gracefully instead of
  hard-failing if a seed is ever run before its migration (the correct order is always
  `migrate` → `seed`, never the reverse). Added an explicit Troubleshooting section to `README.md`
  for this exact error message.

### Changed — PPE Lifecycle (redesigned business workflow)

Replaced the confusing Active/Replaced/Returned with a clear linear workflow:

```
Issued → In Use → Replacement Requested → Replacement Approved
       → Replacement Completed → Archived
```

- **Expired is never part of the manual chain.** It's a computed overlay (unchanged mechanism from
  v1.3.1) that only applies while an item is still Issued/In Use and its expiry date has passed.
  Replacement is never automatic — always an explicit manual action, exactly as specified.
- **"Replacement Completed" is not a plain status change.** A dedicated action
  (`PpeController::completeReplacement()`) archives the old record and creates a **brand-new**
  issuance with its own issue date and freshly-computed expiry — a replaced item can never
  silently become active again.
- Existing data remapped automatically, nothing lost: `active` → `in_use`, `replaced`/`returned` →
  `archived`.

### Changed — Daily Report

- **"HSE Officer" (Employee dropdown) → "Department" (free text).** The report now represents a
  department, not an individual; every company can type their own department name with no master
  list to maintain. The old `hse_officer_id` column and relation are kept (for historical records
  only) — no data was deleted.
- Removed remaining HSE-specific wording throughout the module (page titles, placeholders).

### Changed — Project form

- "Vessel Name" → **"Location"** (placeholder: "e.g. Area A"); Project Name placeholder now
  "e.g. Shutdown Maintenance" instead of a shipyard-specific example. The shipyard-only "Ship" icon
  was replaced with a generic map-pin icon. The underlying database column name is unchanged — this
  is a UI relabel, not a schema rename.

### Changed — UX de-duplication

- **Welcome section** now shows only "Good Morning/Afternoon/Evening" — no name, username, or role
  (that's already in the top bar's profile menu).
- **Sidebar** no longer repeats the user's name/role in its own identity card — removed entirely;
  the top bar profile menu is the single place account identity is shown.
- **Home hero** gained a very subtle (4% opacity, blurred, monochrome) oversized watermark of the
  brand mark behind the headline — not an illustration, never distracts from the text.

### Verified — Notification logic

Reviewed end-to-end: the notification bell badge already only rendered when
`ppe_alert_count > 0` (real data, not a permanent placeholder), and the PPE lifecycle redesign
above means items already in the replacement pipeline (requested/approved/completed/archived) no
longer falsely continue counting toward "needs attention" once they're being actively handled.

### Database migrations (all additive)

| Migration | Purpose |
|-----------|---------|
| `2026_07_21_100019_add_department_name_to_daily_reports_table` | Adds free-text `department_name`; `hse_officer_id` kept for historical data |
| `2026_07_21_100020_redesign_ppe_status_lifecycle` | Widens `employee_ppe.status`, remaps existing data to the new workflow |

---

## [1.5.0] — 2026-07-20

Enterprise-grade visual redesign, dynamic per-company KPI architecture, and a modular sidebar
foundation. No database structure changes beyond what's listed below; no existing business logic
removed.

### Added

- **Dynamic, per-company KPI categories.** New Settings > KPI Categories tab (Super Admin + HSE):
  create, edit, delete, and reorder KPI categories with zero code changes. A category can be
  **Global** (applies to every company) or scoped to one specific **Company**, so Company A can
  track TRIR/LTIFR/Near Miss while Company B tracks Safety Patrol/Training/Toolbox Meeting. The
  Dashboard and Reports pages automatically adapt to whichever categories apply to the currently
  filtered company (`KpiCategory::scopeVisibleForCompany()`).
- **Module toggle architecture.** New Settings > Modules tab (Super Admin only): enable/disable
  each existing sidebar module (Employees, Input KPI, Projects, PPE, Daily Reports, Reports) for
  the whole app. Backed by `config/modules.php` (the registry of what exists) and a
  `enabled_modules` CompanySetting. This is the "modules can be enabled/disabled by Super Admin"
  architecture — documented in `config/modules.php` and `ROADMAP.md` for how a *future* module
  (Fleet, Marine Operations, Procurement, etc.) would register into the same mechanism without
  changing the toggle system itself. **Note:** this is a navigation-visibility toggle, not a hard
  access-control boundary — disabling a module hides it from the sidebar for everyone, but its
  routes remain reachable by direct URL, matching how other nav-driven visibility already works
  elsewhere in the app.
- **Self-service Authentication settings.** New Settings > Authentication tab: any Super Admin or
  HSE user can change their own login email and password (current password required to confirm).
  Structured to accept Email verification and 2FA fields later without restructuring the form.
- **Top bar redesign**: Current Date, a live-updating Current Time, a real notification bell
  (badge count of PPE items expiring soon or expired, linking to the PPE Dashboard), and a Profile
  dropdown menu (About, My Account for admins, Log Out). Logout moved out of the sidebar into this
  menu; the sidebar's user card is now identity-only.
- **Home page**: new large, heavy-weight ("Build Modern / Industrial Operations") premium hero
  with a small "Integrated Operations Management System" badge above it; dynamic time-of-day
  greeting (Good Morning/Afternoon/Evening) plus a live clock, both driven by the browser's own
  local time; stat cards restyled to a headline+descriptor format ("73 Employees" / "Active
  Workforce").
- **Dashboard**: primary cards restyled to match the same headline+descriptor format.

### Changed

- Sidebar subtitle: "HSE Operations Platform" → **"Industrial Operations Platform"**.
- Industry list in the Home hero: removed **Logistics**; now Shipyard, Mining, Construction,
  Manufacturing, **Oil & Gas**, Energy.
- Version section format app-wide: now shows **Edition** ("Enterprise Edition") alongside the
  version number, everywhere version info appears (About dialog, sidebar footer, login footer).

### Database migrations (all additive)

| Migration | Purpose |
|-----------|---------|
| `2026_07_20_100017_add_company_id_to_kpi_categories_table` | Enables per-company KPI categories (null = global) |
| `2026_07_20_100018_drop_kpi_categories_code_unique_constraint` | Removes the now-too-strict global unique constraint on `code`, replaced by app-level per-company-scoped validation |

### Known scope boundaries (disclosed, not oversights)

- The Dashboard's "Most BBS Report" / "Most TBM Attendance" leaderboard cards still look up two
  specific category codes directly. If a company customizes away from those default categories,
  those two cards simply show no data for that company (graceful, not an error) — fully
  generalizing every leaderboard to arbitrary configured categories was out of scope for this
  release.
- KPI Input (Quick Attendance) intentionally shows all active categories regardless of company,
  since a single session can span employees across multiple companies/departments by design —
  there's no single-company context to scope by there. Company-scoping applies where it's
  well-defined: the Dashboard and Reports, both of which have one Company filter.
- The "future modules" list (HR, Asset, Fleet, Marine Operations, Procurement, Warehouse,
  Maintenance, QC, Document Control, Visitor Management, Contractor Management, Permit to Work,
  Risk Assessment, Incident Management) is **architecture-ready, not built**. No stub pages or
  fake UI were created for these — see `ROADMAP.md`.

---

## [1.4.0] — 2026-07-19

Rebrand to **Integrated Operations Management System (IOMS)**, a KPI Input page layout fix, and a
Home page hero redesign. No database changes; no business logic changes.

### Changed

- **Rebranded application name**: "Shipyard Management System" → **"Integrated Operations
  Management System"**, short name **IOMS**. The product no longer presents as shipyard-exclusive
  — positioned as an industrial operations platform for Shipyard, Mining, Construction,
  Manufacturing, Logistics, and Energy. Updated everywhere the name appears: About dialog, sidebar
  (full name in the footer, short "IOMS" label in the compact header where space is tight),
  Login page, browser tab title fallback, and the centralized branding default seeded by
  `CompanySettingSeeder` (auto-upgrades any install still on a previous default; installs that
  already customized their company name are untouched, as before).
- **Home page hero section** — new, icon-free, illustration-free, background-free header:
  "Integrated Operations Management System" / "One Platform for Industrial Operations" / "Designed
  for Shipyard · Mining · Construction · Manufacturing · Logistics · Energy". The existing
  personalized "Welcome back" line and all four recent/upcoming feeds are preserved unchanged
  beneath it.
- **KPI Quick Attendance layout reordered**: Filters → Search Employees → Employee List →
  **Selected Employees** (previously the Selected Employees panel sat above the search/list and
  grew with every selection, pushing the employee list further down the page with every checkbox
  ticked). The employee list now stays near the top regardless of selection count.
- **Selected Employees panel is now collapsible** — collapsed by default, a "Show/Hide" toggle
  reveals the exact same chip UI (name + remove button) as before, with a smooth CSS-only
  expand/collapse animation. Selection count, chip design, and remove functionality are all
  unchanged — only the position and default visibility changed.
- Minor spacing refinements across the KPI Input page (filter/search gaps, employee row padding).

### Notes

- The company **subtitle** ("HSE Operations Platform") was intentionally left unchanged — it
  wasn't part of this rebrand's explicit scope and still accurately describes the app's HSE focus.
- No migrations in this release — every change is branding text, layout, and CSS.

---

## [1.3.2] — 2026-07-18

UI/UX polish, centralized versioning, and two regression fixes introduced in v1.3.1.

### Added

- **Centralized version configuration** (`config/version.php`) — single source of truth for the
  version number, release date, developer credit, and "What's New" list. Shared to every page via
  `HandleInertiaRequests`, so the About dialog, sidebar footer, and login footer all read the same
  value; bumping a release means editing one file, nothing else.
- **About dialog**, opened by clicking the sidebar logo/title (or the existing Info button) —
  shows version, release date, What's New, developer, framework, and copyright.
- **Sidebar footer** — small app name / version / developer block, always in sync with the
  centralized config.
- **Home page redesign** — welcome header with 3 primary stats (Employee Count, Active Projects,
  Current Month), an auto-hiding "What's New" announcement (hides 48h after release, computed
  server-side so it never flashes stale), and four recent/upcoming feeds: Recent KPI Activity,
  Recent Daily Reports, Upcoming PPE Expiry, and Recent Employee Changes (the last one reuses the
  existing `activity_logs` audit trail — no new table).
- **Dashboard redesign** — clearer visual hierarchy: 4 primary cards (Total Employees, Active
  Projects, Companies, Current Month), a compact KPI grid (new `compact` mode on
  `KpiSummaryCard`), and Monthly Trend / Employees by Department promoted to the visual focus.
  Leaderboards, Today's Activities, and Upcoming Reminder are preserved but moved further down.
- **Sidebar polish** — stronger active-page indicator (left accent bar + bold text), clickable
  logo/title, flex-column layout so the nav, user block, and new footer never overlap regardless
  of content height.

### Fixed

- **Employee Department filter: "Column 'department_id' is ambiguous" (SQLSTATE 23000).**
  `Employee::scopeInDepartment()`/`scopeInCompany()` used unqualified `department_id`/`company_id`
  columns; once combined with `scopeOrderedForDisplay()` (introduced in v1.3.1, which joins
  `departments` and `positions` — both of which have their own `company_id`/`department_id`
  columns), those references became genuinely ambiguous to MySQL. Fixed by fully-qualifying both
  scopes to `employees.department_id` / `employees.company_id`. This cascaded-fixed the same bug
  in `EmployeeController`, `KpiInputController`, `EmployeeExport`, and `KpiReportService`, all of
  which call these scopes.
- **KPI Quick Attendance: switching Department didn't refresh the employee list.** Same root
  cause as above — the `kpi-input.attendance-employees` endpoint hit the identical ambiguous-column
  error, which silently failed in the browser (a rejected fetch promise with no `.catch()`, so the
  employee list just never updated). Fixed by the same scope correction, plus added proper
  `response.ok` checking and error logging to that fetch call (and the PPE employee-search fetch)
  so a future backend error surfaces instead of silently freezing the UI.
- A related, previously-undetected instance of the same ambiguous-column pattern in
  `ProjectController`'s "available employees for manpower" query (`where('company_id', ...)` and
  `whereNotIn('id', ...)`, both unqualified) was also fixed proactively.

### Database migrations

None — every change in this release is code-only (query qualification, config, and frontend).

---

## [1.3.1] — 2026-07-18

Workflow and UX improvements based on real daily HSE usage. No new modules; all changes are to
existing PPE, KPI Input, and master-data screens. Additive migrations only.

### Added

- **Automatic PPE status.** PPE lists/badges now always show a computed status derived from
  `expiry_date` -- Active (>30 days left), Expiring Soon (≤30 days), or Expired (past due) --
  recalculated every time the data is displayed. The manual "Change Status" dropdown is now
  "Mark As" and only controls the genuine manual lifecycle (Replaced / Returned); it can no
  longer be used to fake an expiry-based status.
- **Clickable PPE Dashboard cards.** Active / Expiring Soon / Expired cards link straight into
  the PPE list pre-filtered to that status, showing Employee, Company, Department, PPE Type,
  Issue Date, Expiry Date, Days Remaining/Overdue, and current Status.
- **Multiple PPE items in one Issue PPE submission.** One employee, any number of PPE items
  (each with its own type/date), added or removed freely, one Save button. Previously each item
  required its own separate submission.
- **Department dropdowns grouped by Company** wherever a flat list previously mixed departments
  from every company together (Employees, Reports, PPE, Input KPI) -- avoids ambiguity between
  same-named departments in different companies (e.g. both GAJ and Maintenance have "HSE").
- **Configurable Display Order for Departments and Positions.** A new "Display Order" field
  (Settings) controls the sequence departments/positions appear in throughout the app --
  employee lists, dropdowns, and the grouped KPI Report all follow it. Nothing is hardcoded;
  Super Admin/HSE can reorder freely, and existing installs default to alphabetical (order 0)
  until explicitly set.
- **KPI Quick Attendance: cross-department draft.** Selecting employees across multiple
  departments before saving no longer risks losing anything -- the Department filter is now
  purely a browsing tool. A persistent "N employees selected across N departments" panel shows
  and lets you remove anyone from the draft regardless of which department is currently visible.
  One "Save All KPI" button submits everyone, from every department, in a single request.
  Attempting to navigate away with unsaved selections now prompts **Save / Discard / Cancel**
  instead of silently discarding the draft.

### Fixed

- **PPE "Select All" no longer wipes selections in other departments.** Previously, clicking
  Select All while a filter was applied *replaced* the entire distribution/history selection
  with just the currently-filtered items; it now merges/unmerges against the full selection.
- (Same underlying bug pattern, fixed the same way, in KPI Quick Attendance's checklist.)

### Database migrations (all additive, incremental)

| Migration | Purpose |
|-----------|---------|
| `2026_07_18_100015_add_sort_order_to_departments_table` | Adds configurable `sort_order` (default 0) to departments |
| `2026_07_18_100016_add_sort_order_to_positions_table` | Adds configurable `sort_order` (default 0) to positions |

No columns were removed and no existing table was reset. PPE status classification (Active/
Expiring Soon/Expired) is computed on the fly from the existing `expiry_date` column -- no
migration was needed for that change.

---

## [1.3.0] — 2026-07-17

Three integrated modules, built to share data rather than duplicate it: **PPE Management**,
**Project Timeline** (now auto-populated), and **Daily HSE Report**. Additive, incremental
migrations only — no existing table dropped or reset.

### Added

- **PPE (APD) Management module:**
  - **PPE Master** — Super-Admin-configurable PPE types with an optional replacement interval
    (in months). No hardcoded PPE list; intervals editable per type. A `null` interval marks
    "request-based" equipment (e.g. Harness, Headlamp) that has no fixed schedule but is still
    fully tracked in history.
  - **PPE Distribution + History** — a single page/table (`employee_ppe`) serves both concepts:
    issuing PPE to an employee *is* the same record that later appears in their history. Expiry
    date is always derived from the PPE type's interval at issuance time, never entered manually.
  - **PPE Dashboard** — active PPE count, counts by type, items expiring within 30 days, expired count.
- **Project Timeline is now auto-populated from Daily Reports.** Submitting a Daily Report writes
  a timeline event (activity summary + date only) using the `project_timeline_events` architecture
  already built in v1.2 — activities are entered once, in Daily Report, and the timeline is
  derived from it. Per spec, PPE and manpower are never written to the timeline.
- **Daily HSE Report module:**
  - A project may have **multiple Daily Reports on the same date** — different HSE Officers may
    supervise different activities, normal shifts, or overtime shifts on the same project.
  - Each report is attributed to a single **HSE Officer** field: a dropdown of active employees
    whose Department is "HSE" (scoped to the report's project company), validated server-side.
    There is no separate "Created By"/"Reported By" field in the UI — the logged-in user account
    is still recorded internally (`created_by`) for audit purposes only.
  - Free-text Activities (one or more lines) — kept intentionally simple, no predefined
    categories or checklists.
  - Findings, Notes.
  - Deliberately does **not** ask for manpower or PPE — both already exist in Project Manpower
    and PPE Distribution respectively.
  - Photo upload support (multiple photos per report) for Documentation.
  - A `shareableSummary()` model method is ready for future "Copy to Clipboard" / WhatsApp /
    PDF export wiring (none of the three are implemented yet, per spec — this is only the hook).

### Changed

- Sidebar navigation: added **PPE** and **Daily Reports** links, visible to all four roles
  (mutation actions remain permission-gated as before).

### Database migrations (all additive, incremental)

| Migration | Purpose |
|-----------|---------|
| `2026_07_17_100008_create_ppe_types_table` | PPE Master (configurable types + intervals) |
| `2026_07_17_100009_create_employee_ppe_table` | PPE Distribution + History (single table) |
| `2026_07_17_100010_create_daily_reports_table` | Daily HSE Report (one per project per day) |
| `2026_07_17_100011_create_daily_report_activities_table` | Free-text activity lines per report |
| `2026_07_17_100012_create_daily_report_photos_table` | Documentation photo uploads |
| `2026_07_17_100013_add_hse_officer_to_daily_reports_table` | Adds `hse_officer_id` FK (Employee in HSE dept), replaces auto-derived "Created By" as the business attribution field |
| `2026_07_17_100014_drop_daily_reports_unique_constraint` | Removes the one-report-per-project-per-day constraint; replaces with a plain index for query performance |

### Roles (unchanged from v1.2, extended to new modules)

| Capability | Super Admin | HSE | HRD | Manager |
|---|:---:|:---:|:---:|:---:|
| View PPE (Distribution/History/Dashboard/Master) | ✅ | ✅ | ✅ | ✅ |
| Issue/update PPE (Distribution) | ✅ | ✅ | — | — |
| Manage PPE Master (types/intervals) | ✅ | — | — | — |
| View Daily Reports | ✅ | ✅ | ✅ | ✅ |
| Create/edit/delete Daily Reports | ✅ | ✅ | — | — |

---

## [1.2.0] — 2026-07-16

Renamed from **SAFETY LOG** to **Shipyard Management System** (subtitle: *HSE Operations Platform*).
This is a **non-breaking, additive** release. No existing table was dropped or reset, and all
existing data is preserved. The only intentionally destructive change is the removal of the
`employees.nik` column (see below), which was an explicit product decision.

### Added

- **Multi-Company support.** New `companies` table (seeded with **GAJ** and **Maintenance**).
  Every department and employee now belongs to a company.
- **Company-scoped departments.** Departments now belong to a company; the same department name
  (e.g. "HSE", "Engineering") can exist under multiple companies. Full default department lists
  seeded for both GAJ (16 departments) and Maintenance (9 departments).
- **Project module** (simple grouping container — *not* project management software):
  - Project CRUD: name, vessel name, start/end date, status, description.
  - **Manpower assignment** — employees are chosen from the Employee Master (never typed) and
    displayed grouped by department.
  - **Project Timeline** — auto-collected activity feed (starts with "Project Created" and
    "Manpower Assigned"; architecture ready for future modules to append events).
- **Four-role permission system**: Super Admin (developer, full access), HSE (input/edit/manage
  operations + employee CRUD + departments/positions), HRD (read-only), Manager (view Dashboard,
  Reports, Employees, Projects).
- **About dialog** — accessible from the sidebar; shows version, developer, framework, copyright.
- **Login footer** — Version 1.2.0, developer credit (Yofhanza Shultona Rizqi S.), © 2026.
- **Dashboard additions** (existing dashboard preserved, only extended):
  - Company filter.
  - Per-company headcount cards: Overall Total, GAJ, Maintenance.
  - Active Projects count.
  - Today's Activities feed.
  - Upcoming Reminder (projects ending within 14 days).
- **Company filters** added to Dashboard, Employees, Reports, and Projects.
- **Employee improvements**: Company field added; Join Date surfaced (used later for PPE-replacement
  reminders and service years); Years of Service shown on the profile; project assignments listed
  on the profile.

### Changed

- Application name, subtitle, and default branding updated across login, sidebar, About, and config.
- `users.role` widened from an `enum('admin','hrd')` to a validated `varchar` supporting four roles.
  Existing `admin` users were migrated to `super_admin`; existing `hrd` users were unchanged.
- Employee list search no longer searches NIK (field removed); searches name and Employee ID.
- Settings split by permission tier: Departments/Positions manageable by Super Admin + HSE;
  Companies, Users, Branding, and Backup/Restore remain Super Admin only.
- Employee Excel export now includes a Company column and drops the NIK column.

### Removed

- **`employees.nik` column** — removed per product decision (Employee ID already serves as the
  unique employee number). This is a one-way data change; the down-migration restores the column
  shape but not the original values.

### Database migrations (all additive, incremental)

| Migration | Purpose |
|-----------|---------|
| `2026_07_16_100001_create_companies_table` | New companies table |
| `2026_07_16_100002_add_company_id_to_departments_table` | Adds `company_id` to departments, backfills existing rows to GAJ, scopes name-uniqueness per company |
| `2026_07_16_100003_update_employees_table_for_multi_company` | Adds `company_id` to employees (backfilled to GAJ), drops `nik` |
| `2026_07_16_100004_expand_user_roles_table` | Widens `users.role`, remaps `admin`→`super_admin` |
| `2026_07_16_100005_create_projects_table` | New projects table |
| `2026_07_16_100006_create_project_manpower_table` | Project ↔ employee pivot |
| `2026_07_16_100007_create_project_timeline_events_table` | Polymorphic timeline events |

### Migration safety

- No `DROP TABLE`, no `migrate:fresh`, no data reset.
- All new columns added nullable, backfilled, then constrained.
- Existing seeded accounts preserved (`admin@safetylog.local` migrated to Super Admin).

---

## [1.1.0] — earlier

Initial SAFETY LOG release: authentication (Laravel Sanctum), Employees, Input KPI (single +
quick attendance), Reports (Excel/PDF), Dashboard, Settings, two roles (admin/hrd).
