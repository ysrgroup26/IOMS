---
title: Project Board
type: board
updated: 2026-09-27
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
| [[Master Admin Operations Center]] | v2.79.0 shipped the operations view (derived lifecycle counts, work queue, payment activity) and platform notifications | Organizations / Subscriptions / Payments information architecture; header split once Support exists; finish the Indonesian sweep |

## BLOCKED

| Work | Blocked on | Who can unblock |
|---|---|---|
| [[Subscription Lifecycle Finalisation]] — grace window and reminder timing | The backlog asks for a **7-day** grace and an **H-7** reminder; both ship today as **14** by deliberate decision (ADR 033). Changing them is a commercial decision, not a code change — they are already configuration | Owner |
| [[iPaymu Payment Provider]] | Sandbox credentials and the current official iPaymu API documentation. Neither exists in this environment | Owner |
| [[Support Inbox]] | How mail for `support@iomsuite.com` reaches the application (IMAP polling, a forwarding webhook, or a provider's inbound API). The answer decides the whole ingestion design | Owner |

## FUTURE

| Work | Why it matters |
|---|---|
| [[Support Inbox]] | Customer email is not represented in the product at all today. Everything else about support depends on ingestion being decided first |
| [[iPaymu Payment Provider]] | A second provider behind the existing abstraction, so payments do not depend on one gateway |
| [[Billing Modes and Complimentary Tenants]] | The *migration* already works — an existing tenant can pay without being recreated (v2.78.1). What is missing is the product being able to **say** whether a tenant is complimentary, manual or paying |

## COMPLETED (recent, and the evidence)

| Work | Shipped | Verified by |
|---|---|---|
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
