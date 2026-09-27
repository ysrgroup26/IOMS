---
title: Master Admin Operations Center
type: backlog
status: COMPLETED (v2.80.0) — one optional item remains FUTURE
updated: 2026-09-27
tags: [kb/backlog, status/verified]
---

# Master Admin Operations Center

**Source:** `docs/FUTURE IDEAS/PROMPT 1.md` §4–§6, §12–§13, §16 · **Board:** [[Project Board]] ·
**Decision:** ADR [[040-master-admin-is-an-operations-console\|040]]

## What was asked

Master Admin should answer *"how is the IOMS platform operating?"* rather than being a generic CRUD
dashboard: lifecycle health, payment activity, platform events, a header that separates Support from
Notifications, and an information architecture of Organizations / Subscriptions / Payments /
Support / Notifications. In Bahasa Indonesia.

## Done in v2.79.0 — `#status/verified`

- **Operations view** (`PlatformOperationsService`, Platform Dashboard): subscriptions counted by
  **derived** lifecycle state — expiring, grace, lapsed, active, blocked, lifetime — not by the
  stored status column, which cannot see an expiry.
- **"Perlu Perhatian"**: the work queue — everything expiring, in grace or read-only, soonest first,
  each linking to its tenant.
- **Payment activity**: received and outstanding amounts, overdue count, failed attempts, and a
  per-provider breakdown read from the data rather than hardcoded, so iPaymu needs no change here.
- **Platform notifications** (`NotificationService::notifyPlatformAdmins()`): new subscription,
  renewal/reactivation, plan change, grace started, read-only, payment failed. Delivered to platform
  administrators only — never into a tenant user's notifications, asserted by test.
- **Bahasa Indonesia** for the new section, per ADR 040.

Verified by `PlatformOperationsTest` (8 tests) and in a browser against MySQL.

## Done in v2.80.0 — `#status/verified`

The five items left open above were worked through. Four are finished; one is deliberately left as
FUTURE rather than guessed at.

| Item | State |
|---|---|
| **The Indonesian sweep, finished** | Every Master Admin surface — the shell, navigation, Ringkasan, Tenant, Tenant detail, Hak akses, Pendaftaran, Paket, Pembayaran, Dukungan. `LanguageHierarchyTest` now excludes `/platform` from the JSX scan with ADR 040 as the reason; the rendered-props half already did, and that half-applied exemption was itself the bug |
| **Payments screen, both directions** | `Platform/Payments` lists every payment *attempt* — including failed and expired — and walks payment → tagihan → langganan → tenant. Tenant detail walks the other way, now showing each invoice's attempts and the provider that took them. This was the direction support actually needs: a customer quotes a reference, not a tenant id |
| **Subscription and payment history in one place** | Tenant detail's Tagihan & Pembayaran card carries purpose, period, status, payment date and the provider attempts per invoice. Its old description claimed no payment gateway was connected — stale since v2.55.0, and it made the card look like a manual ledger |
| **Billing composition** | The dashboard separates paying / manual / complimentary, so "how many organizations actually pay us?" has an answer on screen. ADR [[041-billing-mode-is-not-entitlement\|041]] |
| **Derived state everywhere, including the list** | The tenant *list* showed only the account status column, so a tenant whose subscription lapsed weeks ago still read Aktif to the operator. It now carries the derived lifecycle beside the account state, from the shared snapshot |

Verified by `PlatformOperationsTest`, `SubscriptionStateParityTest`, `BillingModeTest`,
`SupportTicketQueueTest`, `LanguageHierarchyTest` and a browser pass over every console page
against MySQL.

### The header split, resolved differently

It was waiting on Support existing. Support now exists — and the split turned out to be the wrong
shape: **Dukungan and Notifikasi are not two counters on one header, they are two destinations**.
A notification is read casually; a queue has to be worked. Dukungan is a navigation item with its
own queue counts per tab, and platform events stay on the dashboard. The separation ADR 040 asked
for is kept; the widget it suggested is not.

## Still open — `#status/planned`

| Item | Note |
|---|---|
| Dedicated **Organizations / Subscriptions** screens | With Tenant, Pembayaran and Dukungan now separate destinations, it is no longer clear these earn their own screens rather than living inside Tenant detail. Left open on purpose: worth deciding after the console has been used for a while, not before |
| Operator notification centre (list, read/unread, filters) | The dashboard shows the latest events. A dedicated screen is worth building when the feed is long enough to need filtering, and not before |

## Constraints that must hold

- Master Admin must not become a tenant operational dashboard (ADR 040).
- Read-only by nature: the console derives and displays; it does not mutate subscription state.
- Provider-agnostic: never couple a screen to Midtrans or iPaymu specifically.
- Platform data must remain unreachable for tenant users — `restrict.platform-admin` plus the tests
  in `PlatformOperationsTest`, `SupportTicketQueueTest` and `SubscriptionReadOnlyEnforcementTest`.
- **Never a second status.** Every lifecycle figure the console shows comes from
  `Subscription::stateSnapshot()`, the same one the customer reads. See the ADR 033 v2.80.0
  addendum, pinned by `SubscriptionStateParityTest`.
