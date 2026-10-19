# CLAUDE.md — IOMS Project Knowledge Base

This file is the entry point. Read this first, then follow links below as needed. Don't re-derive
what's already written here by re-scanning the repository — it's slower and more error-prone than
using this document, which is kept current specifically so that isn't necessary.

## What this project is

**IOMS — Industrial Operations Platform** — formerly "Shipyard Management System,"
formerly "SAFETY LOG." An enterprise operations platform for an industrial/HSE-heavy organization:
employee records, PPE (personal protective equipment) lifecycle, KPI tracking and reporting,
project manpower assignment, and an increasingly general-purpose workflow layer (Material Request
today, more modules planned) built on shared, reusable engines rather than per-module one-offs.

Current version: **2.126.0 Beta**. Check `config/ioms.php` (`version`, `stage`, `build`) for the
authoritative current number — this document doesn't restate it elsewhere to avoid it going stale
in two places.

## Stack

Laravel 12 (PHP) + Inertia.js + React 18 + Tailwind CSS + MySQL + Sanctum. `barryvdh/laravel-dompdf`
for PDF generation, `maatwebsite/excel` for Excel import/export. `spatie/laravel-permission` was
added in Milestone 2 (Tenancy Foundation) as RBAC infrastructure (roles/permissions exist, are
tenant-scoped, and are editable from Settings) — but no controller has been migrated from the
original `role` column + `isX()/canX()` methods to permission checks yet; that remains the live
authorization path. See `docs/ADR/008-tenancy-foundation.md` for the reasoning and
`docs/ARCHITECTURE.md` § Authorization for the current state.

## Where things actually are

- `app/Models/`, `app/Http/Controllers/` — standard Laravel locations, one file per model/controller.
- `app/Concerns/` — reusable traits models opt into (`HasApprovals`, `HasWorkflow`). This is where
  the "engine" pattern lives on the backend. See `docs/ARCHITECTURE.md`.
- `app/Services/` — stateless service classes (`PdfGeneratorService`, `MasterDataDetector`,
  `ReportTemplateResolver`, `TenantContext`).
- `app/Services/Documents/` — the **Document Platform** (v2.110.0): reading (`FormatDetector`,
  `Extraction/`, `Support/TableLocator`, `DocumentIngestionService`), writing
  (`Export/BrandedWorkbook`, `Export/BrandedWordDocument`), the document registry (`Library/` —
  `DocumentDefinition`, `DocumentRenderer`, `DocumentLibrary`, `Library/Definitions/`), and
  `TabularBridge`, which is how a CSV, Word or PDF table reaches the EXISTING employee importer
  instead of a second one. Read `docs/ADR/053-...` before adding a parser or an export class.
  Eleven documents are registered in `Library/DocumentLibrary::DEFINITIONS` (v2.112.0), one class
  each; adding a register needs no route, controller, export class or Blade view. Per-RECORD
  documents (Permit to Work, HSE Inspection, Incident, Investigation) stay as their own Blade views
  in `resources/views/pdf/` — the library renders registers, not forms.
- `app/Imports/`, `app/Exports/` — Maatwebsite Excel import/export classes. Two importers today:
  `EmployeesImport` (creates people) and `EmployeePpeImport` (v2.120.0 — issues PPE to people who
  already exist, and creates nothing). Both read their upload through
  `app/Http/Controllers/Concerns/ReadsImportedSpreadsheets.php`, which is the one place that
  decides which formats are accepted, when the CSV/Word/PDF bridge is taken, and how a row number
  maps back to the customer's own file. A third importer extends that trait rather than copying it.
- `resources/js/Pages/` — one Inertia page per route, grouped by module folder (`Employees/`,
  `MaterialRequests/`, `Ppe/`, etc.).
- `resources/js/Components/shared/form/` — the IOMS Form Experience System (v2.65.0): `FormSection`,
  `FormField`, `FormActions`, `ErrorSummary`, `SearchableSelect`, plus `lib/useUnsavedChanges`. Every
  new or edited form should use these rather than hand-rolling labels, error markup or action bars.
  See `Pages/Employees/Form.jsx` for the reference implementation.
  `FormDocumentHeader` (v2.72.0) is the top of an **operational** form — document type, reference
  number, state, who is accountable, what happens next. `Pages/PermitsToWork/Form.jsx` is its
  reference implementation. Master-data forms deliberately do **not** get one.
- `resources/js/Components/shared/` — the reusable frontend components every module is expected to
  use rather than reinvent: `StatusBadge`, `ApprovalActions`, `ActivityTimeline`, `ModuleTabNav`,
  `PageHeader`, `EmptyState`, `LoadingState`, `StatCard`, `EmployeeImportDialog`,
  `RecordChain` (v2.73.0 — a record's INC → INV → CAPA lineage, rendered identically from both
  ends), `DetailFields` (`FieldGrid`/`Field`/`DetailSection` — facts set as a record rather than as
  prose), `ChipSelect` and `TagInput` (v2.73.0), `ApprovalStamp`
  (v2.72.0 — the digital approval seal; takes the server-side authorization record, never a boolean,
  and renders nothing without one), `BrandWordmark` (the one place the IOMS logo is referenced —
  `tone` picks the official light/dark lockup per surface), and others.
- `resources/js/lib/useFocusTrap.js`, `resources/js/lib/useMediaQuery.js` — the shell's two behavioural
  primitives (v2.67.0). `useFocusTrap` is what makes an overlay a *dialog* rather than a div on top:
  focus enters, Tab cycles, Escape closes, focus returns to the opener. `useMediaQuery` exists
  because `role` is an attribute, not a style — the sidebar is a landmark above `lg` and a modal
  below it, and no media query in CSS can say so. Reach for these before hand-rolling either.
- `resources/js/lib/workspaces.js` — the workspace navigation registry (Workspace → Item), the
  single source of truth for the top workspace switcher and dynamic sidebar. See
  `docs/ARCHITECTURE.md`'s Navigation Architecture section and
  `docs/ADR/007-workspace-navigation.md`. A route prefix may be declared by **more than one**
  department when the capability genuinely spans them (Man-Hour, Material Request); the department
  you are already in wins the tie.
- `resources/js/lib/navigationMemory.js` — session-scoped navigation memory (v2.71.0): the sidebar's
  scroll offset and the last active department. It exists because all 144 pages wrap their own
  `<AuthenticatedLayout>` and nothing uses Inertia's persistent-layout pattern, so the rail is
  remounted — and reset to the top — on every navigation. See
  `docs/ADR/034-capability-reach-and-navigation-hierarchy.md`.
- `config/ioms.php` — app version/branding/changelog-summary metadata (not Laravel framework config).
- `config/modules.php` — the module toggle registry (Settings → Modules).
- `config/workflow.php` — role-based permission lists for workflow actions (approve/process/
  override). See `docs/ARCHITECTURE.md` § Authorization.
- `docs/ADR/` — Architecture Decision Records for the larger, less-obvious design calls (see below).
- `CHANGELOG.md`, `ROADMAP.md`, `README.md` — existing project docs, not duplicated here. This file
  is about orientation and "how to think about the codebase," not a changelog.

## Start here for product state: the Obsidian knowledge base (`docs/kb/`)

`docs/kb/` is IOMS's long-term memory, and the fastest way for a new session to get oriented. It is
a **map over the documents below, not a copy of them** — it links outward rather than restating, and
adds the layers that live nowhere else: what is implemented, pending, deferred or superseded; a
decision index; a glossary; a unified release index; and an honest record of what was actually run
versus carefully reasoned about.

Open `docs/kb/IOMS Knowledge Base.md` first. Four notes are usually enough to act safely:
`Product Identity and Principles` → `Current State` → `Requirements Register` →
`Working with This Knowledge Base` (which is also how you record what you did when you finish).

The repository root is an Obsidian vault, so every document below is a linkable note. Reasoning:
`docs/ADR/032-obsidian-knowledge-base.md`.

**When you finish a change, update the knowledge in the same session.** A separate cleanup pass does
not happen — `CHANGELOG.md` fell 71 releases behind exactly that way.

## The reference documents, and when to read which

| Document | Read it when you need to know... |
|---|---|
| **CLAUDE.md** (this file) | Where to start, how the pieces fit together at a glance. |
| `docs/kb/` | Current product state, tracked work, decisions, terminology, release history. |
| `docs/ARCHITECTURE.md` | How the reusable engines work (Approval, Workflow, Timeline, Import, PDF, Report Export), the multi-tenant/company-scoping model, and the authorization approach. Read before building anything that might duplicate an existing engine. |
| `docs/ADR/053-a-document-is-a-definition-not-an-export.md` | **Read before adding a document export, a file parser, or a new import format.** A document is described ONCE (columns, rows, who may read it) and rendered as XLSX/DOCX/PDF from that one definition; the registry is code, not config; tenant scoping lives in the definition and nowhere else; and every input format is normalised into a spreadsheet so it reaches the ONE employee importer rather than a second copy of its rules. |
| `docs/ADR/066-an-import-is-screened-before-it-is-trusted.md` | **Read before touching the PPE import, `PpeImportInterpreter`, `PpeColumnVocabulary`, `WorkbookGrid`, or anything that reads a customer's own spreadsheet.** An import is a SCREENING then a confirmation: seven stages, nothing written until the last, every correction posted back and re-read by the server rather than patched in the browser. The dictionary PROPOSES a concept; the company's catalogue DECIDES whether it exists, and a concept with no row is reported by name, never created. A work uniform is not a coverall, and a bare "Baju" proposes nothing and asks. `WorkbookGrid` is the ONE reader the screening and the import share -- two readers would make the preview a description of a different file. Row classification (separator, subtotal, blank) travels with the plan, or the importer reports a department heading as a missing employee. |
| `docs/ADR/064-a-notification-is-a-destination-not-a-sentence.md` | **Read before touching any notification, `HasWorkflow`, `ApprovalEngine`, or anything that builds a user-facing label out of a model.** Every workflow notification in the product was created with a NULL url while the menu navigates only when one is set, so the chain fired and had nowhere to go: a permit came back, the creator was told, they tapped it, it went grey, and nothing opened. A model now names the route that shows one of its records, explicitly per model; null stays legal, because opening nothing beats opening a 403. The row carries its route NAME and the menu checks it through the same allow-list the sidebar uses (ADR 061, third application). Also closes three copies of `class_basename()` reaching customer copy, including "PermitToWork moved from Draft to Submitted." on the activity timeline. |
| `docs/ADR/065-a-stamp-is-an-action-record-and-its-title-belongs-to-the-company.md` | **Read before touching `StampRenderer`, `ApprovalStamp`, `authorizationFor()`, `activity_logs.actor_title`, or the PTW document/PDF.** Stamps are drawn ONCE on the server as SVG and the same data URI goes to the PDF and the page -- v2.125.0 had two implementations and they diverged; its claim that Dompdf cannot render a rotation or an ink texture was never tested and is wrong (corrected at the top of the ADR). A STATE stamp (ACTIVE green, CLOSED red) is a standing: slanted, distressed, and carrying NO date. An ACTION stamp (approved blue, verified green) names the person, their job title and the time. Only a standing gets a stamp. The job title is the COMPANY'S (`positions.name` via `users.employee_id`), captured at action time so a reorganisation cannot rewrite a filed permit. No stage, status or authority was invented to fit the design: PTW has one gate, and the seal still cannot render without a server-side authorization record. |
| `docs/ADR/063-a-document-number-is-reserved-by-saving-not-by-looking.md` | **Read before showing a document number on a create page, and before touching `NumberGeneratorService`.** `generate()` CONSUMES a sequence number; `preview()` forecasts the next one and writes nothing. Twenty-four create pages previewed with `generate()`, so opening a form and abandoning it burned a number for good (measured: three views of /incidents/create moved the sequence 2 -> 5 with one incident in the table) and the number the form promised was never the number the record got. Every model with a `generateX()` has a `previewX()` beside it; a test fails if a consuming generator reappears in an Inertia prop. |
| `docs/ADR/062-a-my-work-account-is-an-employees-login.md` | **Read before touching the Add My Work User flow, My Work sign-in, access codes, `users.employee_id` or `users.email`'s nullability.** A My Work account is an EMPLOYEE'S LOGIN: created by picking somebody from the Employee Register (never by describing them), with no role and no department asked, an optional email, and an access code stored in `users.password` through the existing `hashed` cast. Signing in at `/my-work/login` resolves an Employee ID or an exact full name to at most five candidates and lets the CODE decide which account it is -- a name finds, the code proves. A Share Access Link carries the badge number and never the code. Supersedes v2.89.0's refusal of a second login page, which was right while both classes used an email and a password. |
| `docs/ADR/061-an-offer-is-bound-to-what-the-server-allows.md` | **Read before adding a menu item, a quick action, a tile or a stat-card link, and before touching `RestrictMyWorkUser`, the My Work rail or `storeUser()`.** Anything that OFFERS a link asks the same question the gate that would refuse it asks, from the same source: the My Work rail and tiles read `RestrictMyWorkUser::ALLOWED_PREFIXES` itself, and plan-gated offers ask `EntitlementService::tenantCanUseRoutePrefix()`, which reproduces `EnforceTenantEntitlement`'s rule once from `config('departments')`. A My Work account was reported as impossible to create when it was in fact created, signed in, and then offered eight links of which six answered 403. |
| `docs/ADR/060-ppe-completeness-is-computed-from-declared-roles.md` | **Read before touching the PPE completeness status, `ppe_types.compliance_role`, or the PPE import file format.** Completeness is work clothing (Work Uniform OR Coverall) + Safety Shoes + Safety Helmet, in THREE states (compliant / needs_replacement / incomplete). Which type satisfies which group is read from the type's DECLARED ROLE, never its name — ADR 050 permits renaming, and a string match would report a renaming tenant's whole workforce as non-compliant. An override inherits its template's role unless declassified explicitly. The import file is WIDE (one row per person, one dated column per type); an empty cell is not a fact and produces no transaction. |
| `docs/ADR/059-bulk-ppe-issuance-is-its-own-import.md` | **Read before touching either import, or adding a third.** Bulk PPE issuance is its OWN importer on the Employee PPE tab, not more columns on `EmployeesImport` (which only ever CREATES — a row whose Employee ID exists is skipped) and not on PPE Master (configuration, Super-Admin-only, while issuing is Super Admin + HSE). Both importers share the Document Platform's front door through the `ReadsImportedSpreadsheets` trait — extend that, never copy it. An import matches master data and never invents it. The duplicate guard resolves a type FAMILY (a built-in and a company's override are one kind with two ids), because `(employee_id, ppe_type_id)` is a plain index, not unique. |
| `docs/ADR/058-a-shared-catalogue-is-overridden-not-rewritten.md` | **Read before touching `ppe_types`, `kpi_categories`, or any table where a null `company_id` means "the shared built-in".** A built-in belongs to the installation: it is never rewritten and never deleted by a tenant. An edit is ACCEPTED onto a company-owned override carrying `source_default_id`; a delete deactivates that company's own copy. Read the catalogue through `visibleForCompany()` (per company) or `withoutSupersededTemplates()` (cross-company) — a new surface that reads it through neither will show a built-in beside its override with the wrong one selectable. Generalises ADR 050 from PPE to every shared catalogue. |
| `docs/ADR/057-a-release-must-be-able-to-prove-what-is-running.md` | **Read before reporting any change as delivered, and before touching `deploy.sh`, `app:deploy` or the release panel.** A passing suite and a clean build are statements about the code YOU loaded, never about the code production is running — three releases were reported as delivered while PPE Master went on refusing an action the committed code allowed. `ReleaseStatusService` reports the running version, branch, commit and content signature, and the operations console renders it from a WEB request because that is the process whose answer matters. `app:deploy` asserts the release's schema and fails before `artisan up`. The honest claim after a deploy is "the two signatures match", not "the tests pass". |
| `docs/ADR/055-deleting-an-employee-is-permanent.md` | **Read before touching employee deletion, `EmployeeEraser`, the employee importer's duplicate handling, or anything that adds a foreign key to `employees`.** Deletion is PERMANENT: records that only describe the person go with them, records of their own keep themselves and lose the pointer, and the two documents that PRINT the name keep it as a tombstone written only at erasure. Never re-add `SoftDeletes` to `Employee` — it was the defect, not the safety. The erasure is in application code, not in delete rules, so MySQL and SQLite agree; a schema-reading test fails if a new reference has no policy. Supersedes ADR 054, which is kept for the history of the defect. |
| `docs/ADR/038-account-organization-subscription.md` | **Read before touching registration, login, `isPlatformAdmin()`, or anything that creates a tenant-less user.** Account / Organization / Subscription are three separate things since v2.74.0, and `tenant_id IS NULL` no longer means "platform operator". |
| `docs/PAYMENTS-DUITKU.md` | **Read before touching checkout, the payment callback, or anything about Duitku.** The sandbox runbook, the signature formulas, what the callback refuses and why, the three idempotency layers, reconciliation, and the production cutover checklist. No credential appears in it. |
| `docs/ADR/049-an-add-on-is-a-purchase-with-its-own-clock.md` | **Read before touching capacity add-ons, `updateAdditionalUsers`, the Billing Centre, or the nightly add-on renewal pass.** An add-on is PAID BEFORE IT IS GRANTED, runs on ITS OWN period, is never prorated in either direction, and a renewal paid early extends from the existing end date. Cancelling stops the next renewal and keeps the period already paid for, which is why a cancelled add-on still counts. |
| `docs/ADR/048-complimentary-access-is-an-evaluation.md` | **Read before touching complimentary duration, re-granting, or the nightly lifecycle job.** A free period is an EVALUATION: 3–30 days, re-grantable by hand on an existing subscription, and it ends itself by flipping `billing_mode` back to paid so the ordinary billing flow takes over. Nothing auto-renews and nothing is deleted. |
| `docs/ADR/047-complimentary-access-provisioning.md` | **Read before touching registration provisioning, `TenantProvisioningService`, or anything that grants a tenant access without payment.** A complimentary grant provisions the VERIFIED REGISTRATION through the same body a payment uses; it never raises or settles an invoice, and it never extends access past its dates. |
| `docs/ADR/041-billing-mode-is-not-entitlement.md` | **Read before touching subscription access, the nightly lifecycle job, or anything that decides whether a tenant may write.** `billing_mode` (paid / manual / complimentary) says only who takes the money; it never grants or withholds access, and lifecycle position stays derived from the dates. |
| `docs/ADR/042-support-queue-is-not-an-inbox.md` | **Read before touching `/platform/support`, the support tables, or anything about inbound mail.** Support tickets are platform-owned and deliberately NOT tenant-scoped (role-gated instead); the status rules live on the model; inbound mail ingestion is BLOCKED and must not be faked. |
| `docs/ADR/046-admin-space-is-a-context.md` | **Read before touching Admin Space, the header context, `config/departments.php`, or payments.** ADMIN SPACE = how the account is CONFIGURED; WORKSPACES = how the company OPERATES — and administrative authority must not appear inside a workspace (nor operational functions inside Admin Space). Exactly one context is lit at a time. The payment provider abstraction is where a gateway goes; nothing outside an adapter may name one. |
| `docs/ADR/045-five-spaces-and-the-global-dashboard.md` | **Read before touching workspaces, the sidebar, `/dashboard`, or anything about which plan sees what.** IOMS is FOUR operational workspaces (HSE, People / HRD, Logistics / Warehouse, Management) + Admin Space, over shared data. The Global Company Dashboard is Business-only, is NOT a workspace, and REDIRECTS rather than refuses. Seven departments are retired from the customer-facing product with their code intact. Workspace access is one question for all four — never add a per-workspace role gate. |
| `docs/ADR/044-management-workspace-and-admin-space.md` | **Read before touching the workspace switcher, `users.workspace_focus`, `/management` or `/admin`.** Management is a Business-tier workspace that owns no data and invents no metric; Admin Space is the CUSTOMER's administration and is not Master Admin (`isTenantAdmin()` requires a tenant); workspace focus decides where somebody starts and is read by nothing in the authorization chain. |
| `docs/ADR/039-public-search-identity.md` | **Read before touching public-page metadata, robots.txt, the sitemap, or images in emails.** `config/seo.php` is the one allow-list of indexable pages; everything else is noindex by default. `IOMS_PUBLIC_URL` (not `APP_URL`) is the canonical origin for SEO and for email images. |
| `docs/MODULES.md` | What a specific module (Employees, PPE, Material Request, Projects, KPI, Tasks, Settings) actually does, its key files, and its module-specific business rules. Read before touching a module you haven't worked in yet. **Incident Management is the INITIAL REPORT; HSE Investigation is a separate workspace** — see ADR 036 before assuming either owns the other's fields. |
| `docs/CONVENTIONS.md` | The house style: migration patterns, naming, status-enum conventions, verification habits, things that have caused real bugs before and how they were fixed. Read before writing a migration, adding a role check, or touching anything cache-related. |
| `docs/ADR/*.md` | The *reasoning* behind a handful of specific, larger decisions (why the Approval Engine is shaped the way it is, why "Pending Approval" isn't a stored status, why Tenancy/RBAC/Platform Super Admin are shaped the way they are in `008`). Read the relevant one before revisiting a decision it documents, so you don't re-litigate something that was already deliberately decided with tradeoffs in mind. |

## The product speaks two languages, and which one depends on the slot

Before editing any user-facing string, know this rule (v2.53.0, full version and rationale in
`docs/CONVENTIONS.md` § *The language hierarchy*):

- **English** — module and feature names, navigation labels, page titles, column headers, status
  labels, stat labels, action labels and buttons, accessible names.
- **Indonesian** — subtitles, card descriptions, help text, hints, empty-state guidance, and the
  `whats_new` list. Legal documents are Indonesian deliberately.

So `<Head title>`, `label=`, `<CardTitle>` and `<th>` are English; `subtitle=`, `description=`,
`<CardDescription>` and `emptyTitle=` are Indonesian, **on the same page**. The split is per slot,
not per page. Established terms (HSE, PPE, JSA, HIRADC, PTW, LOTO, CAPA, TBM, NCR, RFQ, FPB, SPK) are
never translated either way.

This is not decoration: a menu item and the page it opens must be recognisably the same thing. The
rule was broken three times in sixteen releases because it previously existed only inside a changelog
summary. `tests/Feature/LanguageHierarchyTest.php` now pins it — and pins it against **both** the JSX
literals and the rendered server props, because copy reaches a page from either.

## The single most important habit in this codebase: verify before building

Across this project's history, the instruction "verify first, don't assume a feature is missing"
has repeatedly caught real gaps that a fresh build would have duplicated (e.g. `ActivityLog` already
existed and was already used 32+ times before the Activity Timeline viewer was built — only the
*viewing* UI was actually missing). It has also caught two genuinely severe bugs that static
reading alone would have missed if the check had stopped at "the code looks right":

- Material Request and PPE Replacement Request routes were once accidentally nested inside a
  `role:super_admin` middleware group, silently locking out every non-Super-Admin user.
- A settings cache (`CompanySetting`) used `Cache::rememberForever()` in a way that could
  permanently hide a newly-registered module from the sidebar, and a first attempt to fix that
  introduced a *second* real bug (a cache-key mismatch between `get()` and `set()`).

Both are written up in `docs/CONVENTIONS.md` because they're the kind of mistake that's easy to
repeat in a slightly different shape. Before adding a new module, route group, or cached setting,
skim that document.

## What this knowledge base is *not*

- Not a substitute for reading actual source code when implementing something — it documents
  shape and reasoning, not line-by-line behavior.
- Not proof that production is running what this repository contains. See
  `docs/ADR/057-a-release-must-be-able-to-prove-what-is-running.md`: that question is answered by
  comparing the signature `app:deploy` prints with the one Master Admin → Running Release prints,
  and by nothing else.
- Not a runtime verification tool. Nothing in this repository's development history has ever been
  confirmed by actually running `php artisan migrate`, `npm run build`, or clicking through the app
  in a browser — every prior verification was static (balance/brace checks, route cross-references,
  prop-contract matching). Treat "documented as working" as "reasoned through carefully," not
  "tested." Say so plainly if you're in the same position.
- Not exhaustive. It covers what's architecturally significant, not every field on every model.

## Maintaining this knowledge base

Update these documents in the same session as a change that makes them stale — not as a separate
cleanup pass later, which tends not to happen. Concretely:

- New reusable engine or shared component → add it to `docs/ARCHITECTURE.md` and the table above.
- New module → add a section to `docs/MODULES.md`.
- A migration/caching/auth mistake gets made and fixed → add it to `docs/CONVENTIONS.md`'s "Known
  Pitfalls" list, the same way the two bugs above were captured, so the *next* person (human or AI)
  doesn't rediscover it the hard way.
- A genuinely significant, non-obvious design decision gets made (something a future reader would
  reasonably ask "why was it done this way?") → write an ADR in `docs/ADR/`, don't just fold the
  reasoning into a code comment where it's easy to miss.
- If a document starts duplicating another, merge or cut — the goal is one clear place per kind of
  question, not maximum coverage.
