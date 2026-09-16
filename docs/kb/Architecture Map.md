---
title: Architecture Map
type: reference
updated: 2026-09-16
tags: [kb/architecture]
---

# Architecture Map

Where the reusable machinery lives, and what each piece is for. **Authoritative source:
`ARCHITECTURE.md`** — read it before building anything that might duplicate an engine. This note is
the index and the "which one do I want" guide.

---

## The pattern

Build one general mechanism, expose it as a trait or service, and have modules opt in — rather than
reimplementing similar logic per module. Material Request is the reference implementation with all
of them wired together.

> [!tip] The check that keeps paying off
> Ask "does this already exist?" first. `ActivityLog` was already used 32+ times before anyone built
> a viewer for it — the gap was the UI, not the mechanism. See [[Product Identity and Principles]].

---

## Traits a model opts into — `app/Concerns/`

| Trait | Gives the model | Use it when |
|---|---|---|
| `HasApprovals` | Polymorphic `Approval` rows, `submitForApproval()` | There is a submit → approve/reject **decision** |
| `HasWorkflow` | A guarded `status` state machine via a `$transitions` map, plus an `ActivityLog` entry and a notification per move | The model has a **lifecycle**, including moves with no approval at all |
| `HasSecureDocument` | Signed, access-controlled document delivery | The model produces a file that must not be guessable |

**`HasApprovals` and `HasWorkflow` are complementary, not alternatives.** The first is the decision;
the second is the whole lifecycle around it. ADRs [[001-approval-engine|001]] and
[[006-material-request-workflow|006]].

> [!warning] `transitionTo()` is not free in a loop
> It notifies the record's owner, which reads a relation. Transitioning many models means
> eager-loading that relation first or paying an N+1 — a recorded pitfall from v2.69.0.

## Ownership traits — `app/Models/Concerns/`

`BelongsToCompany` and `BelongsToCompanyThrough`. See [[Data Ownership and Boundaries]].

---

## Services — `app/Services/`

Grouped by what they are for rather than alphabetically.

### Workflow and identity

| Service | Purpose |
|---|---|
| `ApprovalEngine` · `ApprovalFlowResolver` | Multi-step, parallel, conditional and escalating approval chains. **Falls back byte-for-byte to the simple path when no flow is configured** — ADR [[010-approval-engine-v2\|010]] |
| `NumberGeneratorService` | All document numbering (`MR-2026-00001`), lock-safe and per-tenant configurable. ADRs [[009-numbering-engine\|009]], [[025-numbering-sequence-portable-uniqueness\|025]] |
| `NotificationService` | The notification centre. ADR [[011-notification-center\|011]] |
| `TaskService` · `WorkCenterService` | Cross-department action surfaces |

### Documents and reporting

| Service | Purpose |
|---|---|
| `PdfGeneratorService` | Thin wrapper over dompdf |
| `DocumentEngine` | Tenant letterhead and per-document templates. ADR [[021-dynamic-document-engine\|021]] |
| `InvoiceDocumentService` | The one document that runs *toward* the customer |
| `ReportTemplateResolver` · `KpiReportService` · `AnalyticsService` | Report Center and analytics. ADRs [[019-analytics-framework\|019]], [[020-report-center\|020]] |
| `FieldMappingService` · `MasterDataDetector` | Import mapping and master-data detection. ADR [[022-import-export-mapping\|022]] |

### Tenancy and commerce

| Service | Purpose |
|---|---|
| `TenantContext` · `TenantProvisioningService` · `TenantReadinessService` | Tenant lifecycle |
| `EntitlementService` | Plan limits — seats, operating units, workspace grants — plus the derived lifecycle standing the shell and the route gate both read |
| `SubscriptionLifecycleService` | **Every subscription state transition, in one place**: renewal-invoice issuance, period extension (always `max(end, now) + cycle`), plan changes, and the one grant sync allowed to *remove*. ADR [[033-subscription-lifecycle\|033]] |
| `PricingService` | Derives every displayed price and saving from the plan's own two prices |
| `Payments/` | Gateway integration |

> [!important] Only the webhook may extend a subscription
> `SubscriptionLifecycleService::applyPaidInvoice()` has exactly two callers:
> `PaymentWebhookController` (after a verified signature) and `PlatformController::markInvoicePaid()`
> (a Platform Admin recording a bank transfer under their own identity). Nothing a browser does
> reaches it.

### Operations

`DashboardStatsService` (tenant-safe aggregate helper — `resolveCompanyIds()` is the pattern other
dashboards reuse), `CalendarService`, `StockService`.

---

## Scheduled work — `routes/console.php`

Three commands, all requiring the server's cron to reach `php artisan schedule:run` every minute.

| Command | Cadence | What it does |
|---|---|---|
| `approvals:escalate` | Hourly | Escalates approvals past their `escalate_after_hours` |
| `reports:dispatch-scheduled` | Hourly | Sends due scheduled reports |
| `subscriptions:lifecycle` | Daily, 02:00 | Issues renewal invoices, sends renewal reminders, applies plan changes waiting on a period boundary |

> [!important] The scheduler never gates access
> `subscriptions:lifecycle` issues billing documents; it does not decide what a customer may do.
> Subscription standing is derived from the dates on every read, so a stopped cron delays an invoice
> and a reminder without locking anyone out — or letting anyone in. ADR
> [[033-subscription-lifecycle|033]].

---

## Support — `app/Support/`

`CurrentTenant` (request-scoped tenant holder), `SecureDocumentRegistry`, `LegalDocuments`,
`ErrorMessagePresenter` (decides whether an exception's own message is fit to show a user).

---

## Frontend architecture

| Piece | Purpose |
|---|---|
| `resources/js/lib/workspaces.js` | **The navigation registry** — one declarative source for the workspace switcher and sidebar. ADR [[007-workspace-navigation\|007]] |
| `Components/shared/form/` | The IOMS Form Experience System — `FormSection`, `FormField`, `FormActions`, `ErrorSummary`, `SearchableSelect` |
| `Components/shared/` | The components every module is expected to reuse: `StatusBadge`, `PageHeader`, `EmptyState`, `StatCard`, `ApprovalActions`, `ActivityTimeline`, `EmployeeSelector`, `AgingIndicator` |
| `lib/useFocusTrap.js` · `lib/useMediaQuery.js` | The shell's two behavioural primitives — what makes an overlay a *dialog* rather than a div |
| `lib/navigationMemory.js` | **v2.71.0** — session-scoped navigation memory: the rail's scroll offset (per department, restored before paint, clamped) and the last active department, which resolves a route owned by several. Exists because every page wraps its own layout, so the rail remounts on every navigation. ADR [[034-capability-reach-and-navigation-hierarchy\|034]] |
| `Components/shared/PageHeader.jsx` | The module-page surface, and since **v2.71.0** the carrier of a page's `kind` — `master` / `operational` / `monitoring` / `administration`. Only the first, third and fourth are labelled; operational is the unlabelled default |

Rules for using these are in [[UX and Design Principles]].

> [!warning] `EmployeeSelector` exists for a reason that keeps recurring
> Ten separate pages have shipped the entire employee directory to the browser to render a dropdown.
> When you add an employee field, reach for `EmployeeSelector` and **do not** add an `employees`
> prop to the controller.

---

See also: [[Data Ownership and Boundaries]] · [[Operational Workflows]] · [[Decision Register]]
