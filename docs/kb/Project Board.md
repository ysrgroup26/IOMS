---
title: Project Board
type: board
updated: 2026-09-30
tags: [kb/board]
---

# Project Board

**Where every major piece of IOMS work stands today.** One screen, five states, each row linking to
the note that holds the detail.

> [!important] The repository is the source of truth
> A row here says COMPLETED only when the code exists **and** it was exercised or pinned by a test.
> If this board and the code ever disagree, the code is right and this board is stale — fix it in
> the same session you notice it.

Related: [[Requirements Register]] (the long tail of smaller items) · [[Decision Register]] (why
things are the way they are) · [[Release History]] (what shipped when) · [[Verification Status]]
(what was actually run).

---

## The five states

| State | Tag | Means |
|---|---|---|
| **FUTURE** | `#status/planned` | Agreed as wanted. Nobody is on it. |
| **IN PROGRESS** | `#status/in-progress` | Being built now. Part of it may already ship. |
| **COMPLETED** | `#status/implemented` · `#status/verified` | Built, and verified or pinned by a test. |
| **BLOCKED** | `#status/blocked` | Cannot proceed until something outside the code is decided or supplied. |
| **DEFERRED** | `#status/deferred` | Deliberately not now, with a reason and a trigger. |

Full definitions and the update loop: [[Working with This Knowledge Base]].

---

## IN PROGRESS

| Work | Where it stands | Next |
|---|---|---|
| [[Support Inbox]] | v2.80.0 shipped the whole ticket domain, the queue, the five states, assignment, ageing, sender identification and outgoing replies from `support@`. **Inbound mail ingestion is BLOCKED** (see below) and the queue page says so on screen | Build the ingestion adapter once the transport is chosen. Everything else is done |

## BLOCKED

| Work | Blocked on | Who can unblock |
|---|---|---|
| [[iPaymu Payment Provider]] — the adapter itself | Sandbox credentials and the **current official** iPaymu API documentation: the signature scheme, the callback payload and the status vocabulary. Neither exists in this environment, and writing them from memory would produce code that looks finished and fails on first contact | Owner |
| [[Support Inbox]] — inbound mail ingestion only | How mail for `support@iomsuite.com` reaches the application: IMAP polling, an inbound-mail webhook (Mailgun / Postmark / SES), or forwarding to an application address. The answer decides the whole ingestion design; everything downstream of it is already built | Owner |

## FUTURE

| Work | Why it matters |
|---|---|
| [[iPaymu Payment Provider]] — the adapter | A second provider behind the existing abstraction, so payments do not depend on one gateway. The **seam** it drops into is finished and pinned (v2.80.0); only the provider-specific class waits on the dependencies above |
| Master Admin information architecture | With Tenant, Pembayaran and Dukungan now separate destinations, the remaining question is whether Organizations / Subscriptions deserve their own screens rather than living inside Tenant detail. Not urgent, and not worth guessing before the console has been used for a while |

## COMPLETED (recent, and the evidence)

| Work | Shipped | Verified by |
|---|---|---|
| Metered PTW and the My Work user class | v2.86.0 | Two billable user classes counted separately, plus a PTW document meter with two pools that never merge. The class restriction is a server-side route allow-list, asserted by DIRECT URL with the strongest tenant role attached, because a restriction escapable with a role is not one. 733 tests / 3913 assertions, 0 failures; both migrations applied, rolled back and re-applied against MySQL; public pricing verified in a browser at 1440 and 375 |
| [[Admin Context and Billing Flow]] — Admin Space as a real context, administrative authority out of the workspaces, Warehouse Logistics, the Support 500 root cause, and Duitku | v2.84.1 | `AdminContextAndBillingFlowTest` (17), both migrations applied, browser-verified on all three plans and as the operator. **Duitku needs its credentials set on the deployment before a customer can pay** |
| [[Final Workspace Architecture]] — four operational workspaces, Admin Space, and a Business-only Global Company Dashboard | v2.84.0 | `WorkspaceArchitectureTest` (18): the plan matrix asserted by DIRECT URL for all three tiers, the Global Dashboard redirecting rather than refusing, no plan granting a retired department, the registry offering none of them, Admin Space separate from both reporting and Master Admin, and focus proven to neither grant nor revoke. Browser end to end on Starter, Professional and Business, plus mobile |
| [[Management Workspace and Admin Space]] — the Business tier’s fourth name becomes a real workspace, administration becomes its own space, and a workspace focus that grants nothing | v2.83.0 | `ManagementAndAdminSpaceTest` (24): entitlement by plan, capability by role, tenant-isolated aggregates, an empty tenant reporting NO DATA rather than zero, and focus proven not to grant or revoke. Browser end to end, ADR [[044-management-workspace-and-admin-space\|044]] |
| [[Pricing and User Entitlement Revamp]] — three tiers, included allowance + paid extras, per-tier annual terms | v2.82.0 | `UserEntitlementAndAddOnTest` (28), `PricingConsistencyTest`, migration rolled back and re-applied, browser end to end. The one open scope item, **“Management”, was built in v2.83.0** |
| [[Website and Brand Consistency]] — invoice redesign, `www` → canonical redirect, official mark audited and resized | v2.81.0 | `InvoiceDocumentTest` (12), `CanonicalHostRedirectTest` (10), browser at desktop and 375px. **Hosting-level `www` redirect and a Google re-crawl remain manual** |
| [[Subscription Lifecycle Finalisation]] — **timing decided**: 7-day grace, H-7 reminder | v2.80.0 | `SubscriptionStateParityTest` (boundary asserted at day 7 and day 8), browser against MySQL |
| **One lifecycle truth on both sides** — `Subscription::stateSnapshot()`, spread by every customer and operator surface | v2.80.0 | `SubscriptionStateParityTest` (10): identical payloads across active, grace, lapsed, suspended, cancelled, renewed |
| [[Billing Modes and Complimentary Tenants]] — paid / manual / complimentary, and what it may never change | v2.80.0 | `BillingModeTest` (12), ADR [[041-billing-mode-is-not-entitlement\|041]] |
| [[Support Inbox]] — ticket domain, queue, states, ageing, identification, replies from `support@` | v2.80.0 | `SupportTicketQueueTest` (16), browser: a real reply sent and threaded, ADR [[042-support-queue-is-not-an-inbox\|042]] |
| Payment traceability both ways, and a provider-agnostic seam | v2.80.0 | `PaymentProviderAbstractionTest` (6): no domain file names a provider |
| [[Master Admin Operations Center]] — operations view, notifications, **and the console finished in Bahasa Indonesia** | v2.79.0 – v2.80.0 | `PlatformOperationsTest`, `LanguageHierarchyTest`, browser against MySQL |
| [[Subscription Lifecycle Finalisation]] — state machine, grace, read-only, renewal date rule, duplicate-webhook safety | v2.70.0 | `SubscriptionLifecycleTest`, `SubscriptionReadOnlyEnforcementTest` |
| Lifecycle customer UX — banners and Billing page state, dates, one renewal action | v2.77.0 | browser against MySQL, [[Verification Status]] |
| Lifecycle emails — grace, lapsed, renewed, and the renewal invoice reworded | v2.78.0 | `SubscriptionLifecycleEmailTest` |
| Payment-provider review readiness + cross-cycle upgrade fix | v2.78.1 | `ReviewerJourneyTest` |
| Man-Hour operational data and aggregation | v2.77.0 | `ManHourTest` |
| Public search identity, brand and favicon consistency | v2.75.0 – v2.78.2 | `PublicSearchIdentityTest`, `BrandIconsTest` |

Older completed work: [[Release History]].

## DEFERRED

Held in [[Requirements Register#Deferred — decided, with a reason]] — two-zone navigation, Settings
decomposition, the workflow form pass, RBAC migration, bundle size, the attachment engine, the
import engine. Each carries its reason and the trigger that would change the answer.

---

## Waiting on the owner, outside the code

Not BLOCKED work — these are finished pieces whose last step is not in the repository.

| Item | What remains | Where it is written down |
|---|---|---|
| `www` → non-www | Confirm in cPanel that `www.iomsuite.com` routes to the same document root, and preferably add the redirect at the web-server level too. The application half is done and tested | ADR [[039-public-search-identity\|039]] § Manual steps, items 8–9 |
| The logo in Google results | Request re-indexing in Search Console. Every asset and every piece of metadata in the repository already points at the current mark; what a result shows is what Google last crawled | ADR 039 § Manual steps, item 10 |
| Lifecycle timing, if it is ever revisited | `SAAS_GRACE_DAYS` / `SAAS_RENEWAL_LEAD_DAYS` — configuration, no deployment of logic needed | [[Subscription Lifecycle Finalisation]] |

## How work enters this board

```
docs/FUTURE IDEAS/          an idea or a prompt, written however you like
        │
        ▼                   audited against the repository
docs/kb/backlog/<note>.md   a tracked item with a status and real state
        │
        ▼                   status moves as the work moves
This board                  FUTURE → IN PROGRESS → COMPLETED
        │
        ▼                   the evidence trail
Release History · Verification Status · Decision Register · ADRs
```

The intake folder is **kept**, not replaced: see [[FUTURE IDEAS README|the intake note]]. A source
prompt is never deleted, because the reasoning in it outlives the task.
