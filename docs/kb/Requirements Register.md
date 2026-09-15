---
title: Requirements Register
type: register
updated: 2026-09-15
tags: [kb/requirements]
---

# Requirements Register

Tracked work, and what state it is in. **This register is authoritative for the *status* of a work
item.** `ROADMAP.md` remains authoritative for product direction and the guiding principles; where
the two disagree about whether something exists, the code wins and this register records the
resolution.

Status vocabulary: [[Working with This Knowledge Base]]. Update the row in the same session you do
the work.

> [!warning] `ROADMAP.md`'s "Near-term" sections are partly stale
> Several items listed there as deferred have since shipped — see [[#Resolved against the code]] at
> the bottom. Do not read that file as a to-do list without checking here first.

---

## Deferred — decided, with a reason

These are **not** forgotten. Each has a reason, and a condition that would change the answer.

| Item | Why it is deferred | What would change it | Status |
|---|---|---|---|
| **Two-zone navigation model** — a permanent workspace rail plus a context panel, replacing the sidebar that swaps between department and global nav | It affects the **administrator's zoomed-out state only**; department and field users are already correct. It is a shell restructure justified by one reported experience, and the v2.68.0 reassessment found no new evidence for it while finding several unambiguous defects. Design is complete in `UX_ARCHITECTURE_DISCOVERY.md` §4. | A second independent report, or a workspace count high enough that the swap becomes unmanageable | `#status/deferred` |
| **Settings decomposition** — `Settings/Index.jsx` is a 2,500-line, 17-tab monolith | Converting its dialog forms piecemeal adds edit risk to that file without delivering the structural benefit; it should be done as one pass where the file is split anyway | Any substantial Settings work — do the split first | `#status/deferred` |
| **Workflow form pass** — ~20 approval-bearing forms (PTW, Incidents, JSA, HIRADC, PO, Goods Receipt, Daily Reports, Tasks, Leave, LOTO, TBM, RFQ, Work Orders, NCR …) not yet on the Form Experience System | Out of scope for the master-data phase, and several are approval-bearing — they deserve their own pass with the workflow question ("what happens when I submit?") in view. Material Request is the reference. | A dedicated pass | `#status/deferred` |
| **RBAC migration** — move authorization from the `role` column to `spatie/laravel-permission` | Migrating now would be a genuinely breaking change across dozens of call sites for a problem (per-tenant custom permissions) that customers have not asked for. The package is installed, tenant-scoped and editable; only the *enforcement* path is unchanged. | A customer needing per-tenant permission customisation | `#status/deferred` |
| **Bundle size** — one ~1.8 MB JS chunk (~440 KB gzipped), warned on every build | Code-splitting an Inertia app is real work with its own verification needs, and this is a performance concern rather than a correctness one | A measured performance complaint, or a mobile-first push | `#status/deferred` |
| **Universal Attachment Engine** — one attachment mechanism across Incident / MR / PPE / Inspection / PTW / Daily Report / Asset | Genuinely large (storage, validation, per-type preview). Deserves the "build it properly once, not seven times shallowly" treatment the Approval Engine got. Today each module has its own table (`VendorDocument`, `DailyReportPhoto`, …) | A third or fourth module needing attachments | `#status/deferred` |
| **Generic Import Engine** — extract a shared base from `EmployeesImport` | Abstracting a reusable pattern from a **single** example guesses the wrong shape. The next importer built should look hard at what is actually common first | A second real importer | `#status/deferred` |

## Planned — wanted, nobody is on it

| Item | Why it matters | Status |
|---|---|---|
| **Midtrans production verification** — one real transaction end to end against live credentials | Everything in the payment path is verified against a signed payload this codebase constructs itself. What has **never** been exercised is a genuine Midtrans notification: their real signature, their real `transaction_status` vocabulary, and their retry behaviour. The code is written to their documented contract, not to an observed one. Needs credentials, a registered Payment Notification URL, and a sandbox transaction — all outside this repository. See ADR [[033-subscription-lifecycle\|033]] and [[Verification Status]] | `#status/planned` |
| **Automatic recurring charging** — card charged at each period end without a customer action | IOMS bills invoice-per-cycle. Midtrans Subscription/Recurring needs supported channels plus separate merchant-side activation, and would be a real integration with its own webhook handling — not the boolean flag v2.70.0 removed for promising exactly this and delivering nothing | `#status/planned` |
| **Field-level Audit Log** — structured before/after values per field | Explicitly distinct from `ActivityLog`'s free-text description. `ActivityLog.meta` could store it but does not today. Needs its own design pass, not a quick reuse of the existing table. **Verified absent:** no `old_value`/`new_value` columns exist | `#status/planned` |
| **Release-date test** | 16 releases in `version_history` are dated *after* the current release date, and two pairs are non-monotonic. A test would have caught it. See [[Known Issues and Limitations]] | `#status/planned` |
| **`CHANGELOG.md` backfill** — 71 releases exist only in `config/ioms.php` | Reconstructing them is its own task and should not be improvised inside an unrelated release. [[Release History]] now indexes both sources so nothing is unreachable in the meantime | `#status/planned` |
| **PPE Inventory** — unassigned stock | The structural blocker is already gone (`employee_ppe.employee_id` is nullable). No "add to inventory" UI exists | `#status/planned` |
| **Report Configuration UI** | `report_configurations` table and model exist; no controller, routes or Settings page. No schema change needed first | `#status/planned` |
| **Finance workspace** | Registered in navigation, routes to Coming Soon. No capability behind it | `#status/planned` |
| **HR placeholders** — Recruitment, Performance, HR KPI, Documents, Reports | `disabled: true` navigation entries advertising intent | `#status/planned` |

## Known limitations accepted for now

Tracked in [[Known Issues and Limitations]] rather than duplicated here.

---

## Recently implemented

The last release's work, kept here briefly so the register shows momentum and the evidence trail.
Older completions live in [[Release History]].

| Item | Version | Status | Evidence |
|---|---|---|---|
| Subscription lifecycle — renewal, grace, read-only lapse, plan changes | 2.70.0 | `#status/verified` | ADR [[033-subscription-lifecycle\|033]]; `SubscriptionLifecycleTest` (38 tests); browser-exercised across active / grace / lapsed, including a real renewal invoice and a scheduled downgrade |
| `tenants.status` enforced — the suspend control previously wrote a column nothing read | 2.70.0 | `#status/implemented` | `EntitlementService::tenantIsUsable()`; `SubscriptionLifecycleTest` |
| Platform plan change re-prices and re-entitles | 2.70.0 | `#status/implemented` | Runs the same `SubscriptionLifecycleService::applyPlanChange()` the customer path uses |
| Bank-transfer settlement extends the period | 2.70.0 | `#status/implemented` | `PlatformController::markInvoicePaid()`; test-covered |
| `recurring_enabled` removed — it promised automatic charging nothing implemented | 2.70.0 | `#status/implemented` | `config/payment.php`; the flag, its env var and its UI copy are all gone |
| Employee Cases — HR employee relations and discipline | 2.69.0 | `#status/implemented` | ADR [[031-employee-cases\|031]]; `EmployeeCaseTest` (14 tests) |
| Material Request aging and outstanding view | 2.69.0 | `#status/verified` | ADR [[030-material-request-lifecycle-and-demand-consolidation\|030]]; browser-exercised |
| Demand consolidation, many MRs → one PR | 2.69.0 | `#status/verified` | ADR 030; consolidation of two requests exercised end to end in a browser |
| Language hierarchy applied to department Overviews and the public site | 2.68.0 | `#status/verified` | `LanguageHierarchyTest`; browser-verified |
| Error page reachable on full-page requests | 2.68.0 | `#status/verified` | `ErrorPagePresentationTest`; browser-verified |
| Application shell accessibility and responsive fixes | 2.67.0 | `#status/implemented` | Layout browser-measured at 13 widths; **keyboard/screen-reader behaviour contract-tested, not interaction-verified** — see [[Verification Status]] |

---

## Resolved against the code

Where `ROADMAP.md` and the implementation disagreed, resolved in favour of the code and the ADRs.
Recorded so nobody re-reads those sections as pending work.

| `ROADMAP.md` says | Reality | Resolution |
|---|---|---|
| Notification Center — "near-term, deferred" | Built. `Notification` model, `NotificationService`, notification centre in the shell | `#status/superseded` — ADR [[011-notification-center\|011]] |
| Global Search — "near-term" | Built. `GlobalSearchController`, `GlobalSearch.jsx` | `#status/superseded` — ADR [[014-global-search-generalization\|014]] |
| Smart Dashboard widgets — "near-term" | Built. Pending approvals, overdue items, expiring PPE all surface on the Dashboard and Work Center | `#status/superseded` |
| Document Engine — "foundation in place" | Built. `DocumentEngine` resolves per-tenant letterhead and templates | `#status/superseded` — ADR [[021-dynamic-document-engine\|021]] |
| Import/Export column mapping | Built. `FieldMappingService`, `MasterDataDetector` | `#status/superseded` — ADR [[022-import-export-mapping\|022]] |
| "Delivered — v1.2 … v1.6.7" sections | Historical release notes, duplicated by [[Release History]] | Historical; read [[Release History]] instead |

---

See also: [[Current State]] · [[Known Issues and Limitations]] · [[Decision Register]]
