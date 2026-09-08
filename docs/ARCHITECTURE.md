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

### Document Identity System (v2.51.0) — the shared letterhead every generated document uses

**What it is**: `DocumentEngine::identity()` plus four Blade partials in
`resources/views/pdf/partials/` — `styles`, `letterhead`, `signatures`, `footer`.

**The problem it solves**: each PDF view used to reach into `CompanySetting` for whichever fields
its own header wanted. The HSE permit grew a real letterhead and nothing else had one, and adding a
header anywhere meant copying that block again. A purchase order and a permit looked like they came
from two different companies.

**How it works**: `identity()` returns the full company identity a formal Indonesian business
document needs — name, legal name, logo, address, assembled locality line, phone/email/website,
NPWP, NIB — read from tenant-scoped `company_settings` (see `CompanySettingScope`). A document can
therefore only ever carry the identity of the tenant that generated it. `branding()` is unchanged
and still serves on-screen chrome; `identity()` is the document-grade superset.

**Convention for a new document type**:

```blade
@include('pdf.partials.styles')
@include('pdf.partials.letterhead', ['identity' => $identity, 'docTitle' => '...', 'docRefs' => [...]])
...your document's own body...
@include('pdf.partials.signatures', ['signatures' => [['role' => 'Dibuat Oleh', 'name' => ...]]])
@include('pdf.partials.footer', ['identity' => $identity, 'documentNumber' => ...])
```

and in the controller, pass `'identity' => $documents->identity()` alongside your data. The
signature partial renders an EMPTY signing box when no name is known rather than hiding it or
inventing an approver — an unsigned box is a real instruction on a printed controlled document.

`styles.blade.php` is table-based with no flex/grid/CSS-variables because that is what dompdf
actually renders; anything there that looks dated is dated on purpose.

**Documents on this system**: Purchase Order (Surat Pesanan), Purchase Requisition (FPB), Goods
Receipt (BAST), Work Order (SPK), plus the existing Permit To Work.

### The public site's components (v2.59.0, extended v2.61.0)

Everything the landing page is built from lives under `resources/js/Components/public/`, and there
is **no animation library** — Framer Motion would have been ~50KB gzipped for what an
IntersectionObserver and a handful of CSS keyframes already do. `Welcome.jsx` composes these; it
does not own their markup.

| Component | What it is |
|---|---|
| `Reveal` + `lib/useReveal` | The one motion gesture: a rise and fade as a block enters view. **Always visible; entering the viewport only ADDS a one-shot `animate-reveal` keyframe.** See CONVENTIONS for the stranding bug that shape prevents. |
| `BlueprintBackdrop` | Atmosphere for the navy bands: technical grid, one light source, and an abstract industrial silhouette (gantry, tanks, frames) as inline SVG. No image request. `variant="hero"` or `"band"`. |
| `PlatformShowcase` | The product showcase: six real workspaces in one application frame, switched by an arrow-key-navigable tablist. |
| `ConnectedOperations` | The hero diagram: eight workspaces around an IOMS hub, with a record travelling inward along each spoke. Hover/focus names what that department records. |
| `DepartmentGrid` | The eight operational domains, one accent each. |
| `FragmentedToConnected` | The problem→solution panel: six disconnected tools on the left, one record crossing five departments on the right. |

**The showcase is the positioning fix.** What it replaced rendered two panels of placeholder
furniture — figures that were literally "—" above a dashed rectangle — and both panels were HSE/PTW,
so the entire product visualisation on a page selling an Industrial Operations Platform showed one
department. It now presents Dashboard, HSE, Human Resources, Project Management, Logistics / PPIC
and Procurement, using the authenticated product's own visual language (navy rail, navy page header
with eyebrow, compact stat chips) and real IOMS vocabulary. Every stat label is the label the
matching department dashboard actually renders; the figures are illustrative and the section says
so. A test asserts the module set and the vocabulary, so a later edit cannot quietly narrow it back
to one department.

**Two workspace names the marketing copy used to get wrong (v2.61.0).** `warehouse` and `finance`
are *shell* workspaces — a Dashboard and an Overview each. The showcase's old "Warehouse" tab was
displaying item master, stock and goods receipt, all of which belong to **Logistics / PPIC**; and
the platform grid carried an "Operations" card that was not a workspace at all, just a bucket
holding the cross-cutting layer (Work Center, Tasks, Man-Hour, activity timeline). `config/plans.php`
now names the shells in a `shells` key so `PricingService` drops them from a tier's "plus" list —
they are still **granted**, they are simply never advertised. The cross-cutting layer is presented
as a layer, below the department grid.

**Motion on the public site.** Three keyframes, all reached only through Tailwind's `motion-safe:`
variant, so `prefers-reduced-motion` removes every one of them and no code path can hide content:

- `reveal` — the one-shot entrance gesture (v2.59.0).
- `dataflow` — moves `stroke-dashoffset` along a hero spoke, so a record visibly travels from a
  department into the hub. Staggered per node; the static spoke is always drawn underneath it.
- `hub-ring` — one slow ring leaving the centre as records arrive.
### Workspace grants: departments are sold, chrome is not (v2.58.0)

`workspaces.tier` separates `department` from `global`. Only DEPARTMENT workspaces are plan
capacity. `reports` and `administration` are the application's own chrome — Reports, Analytics,
Report Center, Settings, Users, Audit Logs — and no plan may withhold them.

**`EntitlementService::grantedWorkspaceKeys()` is the single answer** to "what may this tenant
reach", used by both the route gate (`EnforceTenantEntitlement` →`tenantCanUseWorkspace()`) and the
navigation layer (`HandleInertiaRequests` → `workspace_catalog` → `applyCatalog()` in
`resources/js/lib/workspaces.js`). Before v2.58.0 those two derived it separately and disagreed; see
CONVENTIONS' pitfall entry for what that cost.

The rules it applies, in order: a global-tier workspace is always granted; a tenant with **no grant
rows at all** is unrestricted (matching the documented entitlement default); otherwise the explicit
grant list plus the global keys.

### The IOMS email design system (v2.58.0)

One shell — `resources/views/emails/layout.blade.php` — with three partials: `partials/logo`,
`partials/button`, `partials/summary`. Every transactional message extends it, so no template draws
its own header, button or key/value table.

The shell provides a hidden **preheader** (the inbox preview line), the IOMS mark, a 3px **tone
band** coloured by purpose (`brand` / `security` / `billing` / `success` / `danger`), an optional
**eyebrow** category, the body, and a footer carrying the reply mailbox plus Terms / Privacy /
Refund / Contact. Contextual variation is the tone band and eyebrow, and deliberately nothing more:
a transactional email is read in four seconds by somebody who wants one fact.

Table-based and inline-styled throughout — Outlook renders no flex or grid, and Gmail strips
`<style>` in some contexts, so the single `<style>` block holds only media queries.

**The logo lives in exactly one file.** `partials/logo.blade.php` renders a typographic wordmark
today and carries the instructions for swapping in the official mark: absolute https URL, explicit
width/height, keep the alt text and keep the wordmark as the image-blocked fallback.
### Domain, mailboxes and public identity (v2.55.0)

**One setting names the domain.** `APP_URL` is what every absolute URL is built from — password
reset links, email links, the payment provider's finish callback, invoice links, asset URLs, and
the webhook URL registered in the provider dashboard. No domain is hardcoded anywhere in
application code, so moving from `ioms.web.id` to `iomsuite.com` is an environment change:
`APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE`, and optionally `SESSION_DOMAIN`.
(The `ioms.web.id` mention in `docs/ADR/029` is a historical incident record and stays.)

`TRUSTED_PROXIES` was added because cPanel terminates TLS at a proxy and forwards over plain HTTP;
without it Laravel reads the request as `http://` and puts insecure links in reset emails. It is
opt-in rather than hardcoded to `*`, because trusting forwarding headers from an untrusted network
lets a client spoof its own scheme and host.

**Four mailboxes, four jobs** (`config('ioms.emails')`): `support@`, `billing@`, `noreply@`,
`hello@` on `iomsuite.com`. Every Mailable sends **from** `noreply` with a **Reply-To** matching the
conversation — billing for an invoice, support for an activation, sales for a signup verification.

**`config('ioms.legal')` is empty by default and must stay that way until real facts exist.** The
public policy documents are built in `App\Support\LegalDocuments`, which DROPS any clause whose
underlying fact is unconfigured rather than printing a placeholder that reads like a statement, and
`PublicController` drops any section left with no clauses. There is deliberately no config key for a
tax or registration number: the identity a payment provider verifies (personal name, residential
address, NPWP, ID documents) is merchant KYC data, not website content.

### The invoice is the one document that runs the other way (v2.55.0)

Every other PDF in IOMS is written BY a tenant, so `DocumentEngine::identity()` puts the tenant's
company on the letterhead. An invoice has IOMS as the vendor and the tenant as the customer, so
`InvoiceDocumentService::issuerIdentity()` supplies the ISSUER identity in exactly the shape
`pdf.partials.letterhead` already expects. Same partials, same styles, same footer — no second
document system.

It is reachable from two places because it exists in two situations: `subscription.invoices.pdf`
for a signed-in administrator (ownership checked on `invoices.tenant_id`, since that table has no
`company_id` and inherits nothing from Company's scopes), and `register.invoice` for a prospect who
has paid but has no account yet, authorised by the same unguessable registration token as the status
page.

### Checkout: IOMS's page, the provider's payment interface (v2.55.0)

Checkout used to `redirect()->away()` to the provider the instant the customer clicked pay. It now
lands on `register.pay` — an IOMS order summary stating plan, cycle, period, invoice number and the
IDR amount — and opens Snap over that page using a token the SERVER created
(`PaymentCheckoutResult::$token`, persisted on `payment_transactions.checkout_token`).

**Nothing about the activation rule changed.** Only the client key reaches the browser and it
authorises nothing; every Snap callback does one thing, navigate to the read-only status page; and
no endpoint accepts a payment result from a browser. A subscription still becomes active only in
`PaymentWebhookController`, from a payload the provider signed and this server verified.

### The organizational model (v2.54.0)

```
IOMS  ->  Organization  ->  Operating Unit(s)  ->  Departments
```

| Product term | Class / table | What it is |
|---|---|---|
| Organization | `Tenant` / `tenants` | The subscribing customer. One organization = one subscription. |
| Operating Unit | `Company` / `companies` | A yard, site or division inside that organization (GAJ, MTC). |
| Department | `Department` / `departments` | Belongs to exactly one operating unit. |

**GAJ and MTC are two operating units of ONE organization, not two customers.** That is what the
production data has always said — both are `companies` rows under a single tenant — and it is what
ADR-008 meant when it called Company "an internal business unit WITHIN one Tenant". v2.54.0 gave the
levels their names and used them consistently on every human-readable surface. The classes and tables
keep their names: renaming `Company` would touch every FK, scope and controller in the schema to buy
a word.

**Legal entity is an ATTRIBUTE of an operating unit, not a level above it.**
`companies.legal_entity_name` / `legal_entity_registration` are optional and printed on documents.
Nothing queries or scopes by them — ownership of a record is `company_id` and only `company_id`, so
there is exactly one answer to "who does this belong to". Most organizations trade as one entity and
leave both null.

**Capacity is measured in operating units.** `packages.max_companies` is that number (Starter 1,
Professional 2, Enterprise unlimited/null). `EntitlementService::canCreateOperatingUnit()` enforces
it inside a row-locked transaction in `SettingsController::storeCompanyEntity()` — before v2.54.0 the
number was displayed but never enforced. The usage count deliberately bypasses global scopes: a quota
is a property of the organization, never of the person asking.

**There is no organizational context switcher, on purpose.** What a user may reach is decided
server-side from recorded grants; a client-selected operating unit would be a second, untrusted answer
to a question the server has already settled. `User::organizationContext()` supplies the header's
read-only context display.

### Per-operating-unit authorization inside an organization (v2.53.0, reachable in v2.54.0)

Enterprise sells multiple operating units in one workspace. `TenantScope` narrows Company to the
organization; `CompanyAuthorizationScope` narrows it further to the units the **authenticated user**
is authorized for, via the `company_user` pivot. `SettingsController::updateUserCompanies()` is what
writes that pivot — until v2.54.0 nothing could, so the authorization existed but was unreachable and
every user was unrestricted in practice.

Because practically every downstream query resolves its own scope through
`Company::query()->pluck('id')`, narrowing Company propagates to employees, permits, purchase orders
and the rest — the same transitive-isolation property TenantScope already relies on.

**The default is "no grants = all operating units in the organization."** Every existing user has no
grants, so upgrading changes nothing; authorization becomes real the moment an administrator grants a
user their first unit. It only ever removes access. Granting EVERY unit is stored as *no restriction*
rather than as a full list, so adding a unit later does not silently exclude everyone who was
unrestricted at the time.

### IOMS Sandbox (v2.53.0)

A product **demonstration**, deliberately not a free trial — there is no free production tenant, and
the purchase path is unchanged.

The Sandbox is a **real tenant** flagged `tenants.is_demo`, seeded by `DemoTenantSeeder`, so it
inherits every boundary the product already enforces (TenantScope, RBAC, PTW authorization,
entitlements). `SandboxController::enter` signs a visitor in as an ordinary tenant user that already
exists; it grants nothing and refuses if the tenant has not been seeded.

`RestrictDemoTenant` adds the one property a *shared* demo needs: read-mostly. Writes are refused
outside a short **allow-list** (not a deny-list — a destructive route added later is refused by
default), and Settings, billing and the platform surface are refused entirely.

Entitlements are deliberately partial, so locked modules in the demo are locked by the real
entitlement mechanism rather than a mock-up.

### Equipment Master vs Equipment Register (v2.53.0)

| | Equipment Master (`hse_equipment_types`) | Equipment Register (`safety_equipment`) |
|---|---|---|
| Holds | Types: Gas Detector, Fire Extinguisher | Units: GD-001, FE-002 |
| Answers | "What kinds do we define?" | "Which do we own?" |
| Carries | Which lifecycles apply, default interval, code prefix | The dates for the lifecycles that apply |

**Which lifecycles apply is a property of the TYPE, not the unit** — a gas detector is calibrated, a
fire extinguisher expires, a blower is serviced. `tracks_inspection` / `tracks_calibration` /
`tracks_service` / `tracks_expiry` on the master drive which date fields the register asks for, so
equipment never carries a date it has no business having.
### BAST vs Goods Receipt — two documents, not two names for one (v2.52.0)

These were briefly collapsed into one document and separating them again is
load-bearing, so it is written down here rather than left in a commit message.

| | Goods Receipt | BAST (`HandoverRecord`) |
|---|---|---|
| What it is | A warehouse transaction | A formal handover instrument |
| Consequence | A stock level moves | A contractual acceptance exists |
| Frequency | Many times a week | Once per handover |
| Signed by | Receiving officer | Two named parties |
| Model | `GoodsReceipt` | `HandoverRecord` |
| Numbering | `GR-` | `BAST-` |

A completed Work Order handed to the asset owner needs a BAST and moves no stock at all. A pallet of
electrodes booked into the warehouse needs a Goods Receipt and no BAST. A BAST may *reference* a
Goods Receipt through its polymorphic `source` — that morph is restricted to a closed allow-list
(`HandoverRecord::SOURCE_TYPES`) so a crafted request cannot point it at an arbitrary model.

### My Work vs PTW Access — a workspace and a permission (v2.52.0)

The second distinction this codebase had collapsed. They are independent in both directions:

- `users.is_field_user` → which **workspace** an account lands in (`User::landingRouteName()`).
- `users.ptw_access` → whether the account may **create** a Permit To Work (`User::canCreatePtw()`).

A field worker normally has the first and not the second; a foreman may have both; an HSE officer may
hold PTW authority without being a field account. Granting the field workspace consumes no PTW seat
and grants no capability — they even have separate endpoints
(`settings.users.field-access` vs `settings.users.ptw-access`) so the difference is visible in the
code, not just in a comment.

**Capacity model.** `max_users` is how many login accounts a tenant may create; `max_ptw_users` is
how many *of those* may hold PTW Access. A subset, never an extra pool — `max_ptw_users <= max_users`
always holds, and the standardize-capacity migration enforces it rather than assuming it.

### Project identity vs work location (v2.52.0)

`permits_to_work` carries `project_id` (the formal Project Master, authoritative when set),
`project_name` (free-text work identity when no project row exists), and `location` (the physical
area). `PermitToWork::workIdentity()` resolves the first two. The point: HSE must never be blocked on
Management creating a project row, and a permit for real work must never print "No Project".
### Excel Export foundation (v2.51.0)

**What it is**: `app/Exports/Concerns/IomsSheetFormatting.php` — a trait, not a base class, because
every export already implements a different mix of Maatwebsite concerns and forcing them under one
parent would mean rewriting working export logic (and risking the data) to gain formatting.

Provides `iomsProperties($title)` for `WithProperties` (document properties carrying the tenant's
own identity) and `applyIomsSheetFormatting($sheet)` for an `AfterSheet` listener: navy header row,
frozen pane below it, autofilter, A4 landscape fit-to-width print setup with the header repeated on
every printed page. Deliberately does not decorate — a spreadsheet is a working document, and merged
title art three rows deep makes it harder to sort, filter and pivot.
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

> **Read this first (v2.40.0).** There are TWO layers here and they are easy to confuse. The section
> below the line describes the ORIGINAL per-company filtering convention (Epic 3:
> `TenantContext` / `IdentifyTenant` / `?company_id=X`), which still exists and is still used for
> *which company am I looking at* inside one customer. It is **not** the security boundary.
>
> The actual **tenant isolation boundary** arrived with Milestone 2 and is:
>
> - `App\Support\CurrentTenant` — request-scoped singleton holding the resolved Tenant.
> - `App\Http\Middleware\ResolveTenant` — the ONE place that resolves it (from `$user->tenant`).
> - `App\Models\Scopes\TenantScope` — applied to `Company`. It **fails closed** (`tenant_id = -1`)
>   when no tenant is resolved, so an unresolved request returns zero rows rather than every
>   tenant's.
> - `App\Models\Concerns\BelongsToCompany` + `App\Models\Scopes\CompanyOwnedScope` (v2.62.0) —
>   applied to **every model with a `company_id`**. A company-owned query can only return rows whose
>   company is one of `Company::query()->select('id')`, i.e. one the current request may see.
> - `App\Models\Scopes\UserTenantScope` (v2.62.0) — `users` is tenant-owned (its `company_id` is
>   nullable by design), so it is scoped on `tenant_id` instead.
>
> ### Why the second layer exists (v2.62.0, and read this before removing it)
>
> Until v2.62.0 only `Company` was scoped, and everything beneath it was called "safe
> *transitively*, because it can only reference a Company this scope already filtered". That is true
> of the DATA MODEL and false of the QUERIES. A row's `company_id` does point at exactly one
> Company; nothing forced a query to go looking at Company at all. Transitive safety only held for
> queries that voluntarily resolved `Company::query()->pluck('id')` first — a convention, across 66
> models and several hundred call sites.
>
> **It failed in production.** A newly provisioned Starter tenant opened Reports and saw another
> customer's departments and employee names. The responsible line is optional by design:
>
> ```php
> Department::where('is_active', true)
>     ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
> ```
>
> With no company chosen — the default — `when()` is a no-op and every department in the
> installation comes back. A static sweep found 72 sites of that shape, and `Employee::find($id)`
> reached another tenant's employee outright.
>
> So ownership is now **structural**: a forgotten `where` clause is no longer a breach, and adding a
> new company-owned table needs `company_id` plus the trait and nothing else.
> `TenantIsolationCoverageTest` (in `TenantDataIsolationTest`) asserts that every model carrying a
> `company_id` has an isolation scope, so the next table cannot be added without one by accident.
>
> Three consequences, each of which has caused a real defect:
>
> 1. **`ResolveTenant` must run before `SubstituteBindings`.** Route model binding looks a record up
>    by id before any controller code runs; with no tenant in context it resolves against
>    `tenant_id = -1`. `bootstrap/app.php` removes `SubstituteBindings` from the `web` group and
>    re-adds it after `ResolveTenant` for exactly this reason. The order is
>    `StartSession → ResolveTenant → SubstituteBindings`, and all three positions matter.
> 2. **Fail-closed means code without an HTTP request has no tenant.** Scheduled commands must
>    restore context explicitly or they silently produce empty output — see
>    `DispatchScheduledReports`, which did exactly that until v2.40.0, and whose one deliberately
>    cross-tenant query now says `withoutGlobalScopes()` in so many words.
> 3. **Defence in depth is still required for INPUT.** The scope governs which rows a query returns;
>    a submitted foreign key is a different question and still uses the `App\Rules\InCurrentTenant`
>    validation rule. Record *access* and request *input* are two layers; both are required.
>
> **Null owners.** A null `company_id` means "unowned", and unowned means visible to nobody — the
> safe reading, since a row nobody owns must not become a row everybody can see. Three tables use
> null to mean "the built-in default every customer starts from" instead (`kpi_categories`,
> `numbering_formats`, `numbering_sequences`) and opt in with
> `companyScopeAllowsGlobalRows(): true`. Two more are owned through a different column and say so
> with their own scope: `activity_logs` (a tenant-level action is owned by the user who performed
> it) and `tasks` (a company-less task is owned by its creator or assignee). `employee_ppe` and
> `ppe_replacement_request_items` have no `company_id` at all and resolve ownership through their
> parent with `whereHas`.
>
> **The escape hatch is deliberate and greppable.** Provisioning, the Platform Super Admin surface
> and the scheduled-report driver genuinely operate across tenants and say
> `withoutGlobalScope(CompanyOwnedScope::class)` / `withoutGlobalScopes()`. "I mean across tenants"
> is reviewable; "I forgot a where clause" is not.
>
> **Tenant-owned settings** (`company_settings`) use a distinct two-tier model, because guests and
> the platform genuinely need values too: `tenant_id IS NULL` is the platform default,
> `tenant_id = X` is a tenant override, and `CompanySetting::get()` resolves tenant → platform →
> caller default. `CompanySettingScope` fail-closes each request to its own bucket, covering the raw
> Eloquent paths as well as the accessor, and the cache is keyed per tier. See
> `docs/CONVENTIONS.md`'s v2.40.0 pitfall for why the accessor alone was not enough.

---

**Original per-company filtering convention (Epic 3) — still current, but not the isolation boundary:**

- `users.company_id` — nullable FK. Existing internal-staff users are `NULL` on purpose (they're
  not scoped to one company); a real multi-tenant customer's users would have it set.
- `app/Services/TenantContext.php` + `app/Http/Middleware/IdentifyTenant.php` — resolve "the
  current company" from the authenticated user plus an optional request parameter, cached per
  request. This layer answers *which of my companies am I looking at*, a UI filter.
  **It is not, and never was, the isolation boundary** — `CompanyOwnedScope` is (v2.62.0).
  This convention originally read "deliberately does not auto-scope Eloquent queries globally (a
  retroactive global scope across every model was judged too risky to introduce all at once)". That
  judgement is what produced the v2.62.0 cross-tenant read incident: the risk of adding the scope
  was one release of test failures, and the risk of not adding it was a customer reading another
  customer's employee names.
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

As of v2.1.0, revised in v2.60.0:
- `Package::defaultWorkspaceKeys()` / `defaultModuleKeys()` (on `app/Models/Package.php`) is the
  canonical plan → Workspace/Module mapping, keyed by `Package::slug` so a display-name rename
  can't silently break it. **The mapping itself lives in `config/plans.php`**, not in the model —
  it used to be a hardcoded `match` whose `default => []` arm meant a plan created through the
  Platform Admin UI, or one whose slug was mistyped, resolved to ZERO departments while still
  carrying a price. That is the v2.58.0 empty-sidebar defect one layer up. An unrecognised slug now
  falls back to `config('plans.fallback')` (the entry tier), so a paid customer always receives a
  working, clearly-bounded product and the operator sees an obviously wrong plan instead of a
  customer with no navigation.
- `Package::departmentWorkspaceKeys()` is the same grant **minus the global chrome**. Provisioning
  uses `defaultWorkspaceKeys()`; pricing surfaces use `departmentWorkspaceKeys()`, because listing
  Reports and Settings as bullet points under every tier presents the application's own furniture as
  a purchased feature.
- `config/plans.php` also carries each tier's one-line `positioning` and the single `popular` slug,
  served through `PricingService::summarize()` as `positioning` / `is_popular`. The UI must never
  infer emphasis from a card's index — v2.27.0's `plans.length === 3 ? 1 : -1` silently emphasised
  nothing the moment a fourth tier arrived.
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

## The commercial model (v2.60.0)

Four standardized tiers. Annual is monthly × 10 on every tier (≈16.67%, displayed as 17%); no
percentage is written down anywhere — `PricingService::annualSaving()` derives it from the plan's own
two prices.

| Tier | Monthly | Yearly | Users | Operating Units | Departments |
|---|---|---|---|---|---|
| Starter | Rp299.000 | Rp2.990.000 | 10 | 1 | HSE |
| Professional | Rp799.000 | Rp7.990.000 | 50 | 2 | + People / HR |
| **Business** | Rp1.499.000 | Rp14.990.000 | 150 | 4 | + Project Management, Logistics / PPIC, Procurement |
| Enterprise | Rp2.499.000 | Rp24.990.000 | unlimited | unlimited | all |

The ladder is operational complexity, not module count. Business is the first tier that crosses
departments and is the one marked `popular`. `null` on `max_users`/`max_companies` is this schema's
"unlimited". There is no free trial and no custom/bespoke tier — Enterprise is the most complete
STANDARDIZED plan, not a negotiated build.

**The catalogue ships in a migration, not only in the seeder.** `db:seed` does not re-run on a live
deployment; the `packages` table is what renders. v2.51.0 shipped a whole release where production
still showed the previous prices because only the seeder had changed. `PackageSeeder` and
`2026_09_26_100270_establish_four_tier_pricing` must state the same figures, and
`ProductRevisionV252Test` asserts they do.

**A catalogue price change never reprices an existing customer.** `subscriptions` carries
`agreed_price_monthly` / `agreed_price_yearly` / `agreed_currency`, stamped at provisioning
(`TenantProvisioningService::activate()`, both `PlatformController` create paths) and read back
through `Subscription::agreedAmountFor()`, which falls back to the catalogue only for rows that
predate the columns. `isOnLegacyPricing()` is true when the two differ, and the billing page says so
in words rather than leaving the customer to notice. `repriceTo()` is the deliberate act of moving a
customer onto new pricing.

> The backfill migration reads a **literal table of pre-v2.60 prices**, never the live `packages`
> table. Migrations run in filename order and the repricing migration runs first, so reading the
> catalogue would have backfilled every existing customer at the NEW price — the exact error the
> columns exist to prevent.

`packages.features` was dropped in this release. It was a third, non-authoritative list of what a
plan includes, sitting beside the two that are actually enforced (the Module and Workspace grant
tables) and drifting from them; `hasFeature()` went with it.

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

### PTW User Quota — a USER entitlement, not a Module/Workspace grant (v2.17.0)

Extends this same `Package`-as-Plan architecture with a genuinely new *kind* of entitlement, deliberately
kept separate from `tenantCanUseModule()`/`tenantCanUseWorkspace()` above (those answer "can this
tenant reach this department at all"; this answers "how many of this tenant's OWN users may be
individually authorized to create a PTW") — the two questions don't collapse into each other, so no
existing method's contract changed.

- **`packages.max_users` vs `packages.max_ptw_users` — semantics, stated explicitly (v2.17.1
  correction)**: `max_users` is the ceiling on this package's total User Account count (the existing,
  unchanged meaning). `max_ptw_users` is the ceiling on how many of THOSE User Accounts may ALSO be
  PTW-enabled — a subset of `max_users`, never a separate or larger pool. **`max_ptw_users` must never
  exceed `max_users` whenever both are set** (`null` on either side means unlimited and is never
  compared). v2.17.0 originally shipped Starter with `max_ptw_users=15` against `max_users=10` — a
  real contradiction, honestly flagged in that pass's own commit rather than silently shipped as
  correct. Fixed in v2.17.1 by raising Starter's `max_users` to 15 (per the correction directive's own
  preference: "if Starter remains 15 PTW users, max_users must support at least 15 User Accounts"),
  and `PlatformController::validatePlan()` now server-enforces this relationship for any future Plan
  edit, so the contradiction can't be reintroduced from the Plans admin UI.
- `packages.max_ptw_users` (nullable int, `null` = unlimited/custom) — same shape as `max_users`/
  `max_companies` on that table, editable from the existing Platform Admin Plans page, no code deploy
  required to change a Plan's quota.
- `users.ptw_access` (boolean, default false) — the per-user grant this quota counts. Independent of
  `role`: an HSE-role user's access is untouched (`User::canCreatePtw()` is `canManageHse() ||
  ptw_access`, a union); this is purely additive for non-HSE Field/Operations users.
- `EntitlementService::ptwUserQuota()`/`ptwUsersUsedCount()`/`canEnablePtwAccess()` — the single
  authoritative source both `SettingsController::updatePtwAccess()` (the only place `ptw_access` can
  be enabled) and the Settings UI's own displayed "PTW Users X / Y" banner read from, so the two can
  never show a different number than what's actually enforced. Enforcement runs inside a
  `DB::transaction` with `lockForUpdate()` on the tenant's currently-enabled users, closing the
  same race-condition class Part 6 of that phase's directive called out explicitly.
- **Who can manage PTW Access, and where (v2.19.0 correction)**: PTW Access is an account/access-
  management function, canonically reached at **Settings → Users → Field & PTW Access** — never
  folded into the HSE operational modules or the HSE Dashboard. `settings.users.ptw-access` (the
  route) sits in its own `role:super_admin,hse` group (was previously nested inside the stricter
  `role:super_admin`-only group by mistake, meaning `SettingsController::updatePtwAccess()`'s own
  already-correct `canManageHse()` check could never actually run for an HSE user — a route-level
  gate stricter than its controller's own authorization, the same failure shape documented in
  `docs/CONVENTIONS.md`'s "route accidentally nested inside a too-restrictive group" pitfall).
  Frontend-side, the Settings → Users tab now opens for `canManageHse()` too (`can.manage_ptw_access`),
  but `UsersTab` renders two INDEPENDENT cards: `UserManagementCard` (create/edit/delete/role changes)
  stays `canManageUsers` (Super Admin) only; `FieldPtwAccessCard` (the toggle + quota banner) renders
  for `canManageUsers || canPtwAccess`. HSE therefore reaches exactly PTW Access and nothing else —
  it can never create/delete a user or change anyone's role through this surface. Ordinary Field/
  Foreman users (even ones with `ptw_access = true`) cannot reach the Settings → Users tab at all —
  `can.manage_ptw_access` is `false` for them, same as every other role.

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
- **Mobile bottom navigation** (v2.22.0, Complete Product UI/UX Transformation, Part 9): below `lg`,
  `Components/shared/MobileBottomNav.jsx` renders a fixed bottom tab bar alongside the sidebar (the
  sidebar itself still exists as the same off-canvas drawer — this is an ADDITIONAL surface, not a
  replacement). Deliberately reuses `AuthenticatedLayout`'s own `visibleNav` array as-is (the exact
  same already-RBAC/department-filtered list the sidebar renders) rather than recomputing or
  duplicating navigation/permission logic — this component receives it as a prop and only decides
  *how many* of those already-authorized items to show (`Home` pinned + up to 3 leaf items, filtering
  out `disabled`/`children`-grouped items since neither has one single tap destination), never *which*
  items a role/department is allowed to see. Its "More" button calls the same `setSidebarOpen(true)`
  the sidebar's own hamburger already uses — the full authorized navigation is one tap away in the
  exact same drawer, not a second menu. `z-30`, intentionally below the drawer's own mobile overlay
  (`z-40`) so opening the drawer visually covers the bottom bar too. `<main>` gets `pb-24` on mobile
  only (`lg:pb-8` otherwise) so the fixed bar never covers page content.
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

## Public Website vs. Authenticated Application (v2.18.0)

`/` is now a genuinely public route — no `auth` or `guest` middleware, reachable by anyone. Previously
`/` was `Route::redirect('/', '/dashboard')` *inside* the `auth`-required route group, so an anonymous
visitor was bounced straight to `/login` by Laravel's own unauthenticated-request handling before
seeing anything — the opposite of what a public-facing product website needs.

- **`PublicController::home()`** branches on auth state itself, rather than being wrapped in `guest`
  middleware: an authenticated user (tenant OR Platform Admin) is redirected into the app exactly as
  `/` used to behave (`dashboard` or `platform.dashboard`); a guest sees `Public/Welcome.jsx`, the
  marketing page. This is the ONLY route in the app that behaves differently for guest vs.
  authenticated visitors at the same URL — every other route is either fully public (`/privacy`,
  `/terms`) or fully gated (everything under the `auth` group).
- **`resources/js/Layouts/PublicLayout.jsx`** — a separate layout from `AuthenticatedLayout`, with no
  sidebar/Work Center/Department Selector (none of those concepts apply to an anonymous visitor). Reuses
  `BrandWordmark` and the same `company`/`version` shared Inertia props `Auth/Login.jsx` already used
  for an unauthenticated page — no second branding source.
- **Data exposed to the public route is deliberately minimal and already-public-safe**: `PublicController
  ::home()`'s only query is `PricingService::publicPlans()` — the exact same read-only source the
  authenticated `subscription.plans` page already uses (see the SaaS Productization section above).
  Nothing tenant-specific, no employee/user data, no subscription/invoice data is ever passed to a
  public page.
- **Content honesty convention** (established this pass, apply to any future public-facing content):
  no invented customer names/logos/counts/testimonials/certifications, and no capability claimed that
  the codebase doesn't already ship — `Public/Welcome.jsx`'s own module lists were cross-checked
  against `resources/js/lib/workspaces.js`/`docs/MODULES.md` before being written, not invented from
  the marketing brief alone. Pricing is entirely data-driven (`PricingService`), never a hardcoded
  Rupiah amount — if no plan is public yet, the page shows an honest "being finalized" state instead.
- **Visual system (v2.27.0, Public Website & Auth Visual Transformation)**: the public site's ambient
  ombré (white → `brand-50/40` → soft blue) and `motion-safe:animate-pulse-glow`/`animate-float`
  keyframes (added to `tailwind.config.js`, plain CSS, no new dependency) are shared visual language
  now used identically across `Public/Welcome.jsx` and the three `Auth/*.jsx` pages (Login, Forgot
  Password, Reset Password) — the public marketing experience and the sign-in flow read as one
  continuous brand, not two different products. Both animation utilities are Tailwind's built-in
  `motion-safe:` variant only — never applied unconditionally, so `prefers-reduced-motion` disables
  them automatically with no extra code. The Hero's "platform visualization" (a central IOMS hub with
  8 real domain nodes) is `lg:`-only by design: absolute-positioned percentage-coordinate nodes don't
  reflow safely to a narrow viewport by shrinking alone, so mobile/tablet render a separate, ordinary
  wrap-grid of the same 8 domains instead of a shrunk copy of the same layout — not a hidden feature,
  a deliberately different, verified-safe presentation of the identical, real module list.
