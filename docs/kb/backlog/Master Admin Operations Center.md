---
title: Master Admin Operations Center
type: backlog
status: in progress
updated: 2026-09-27
tags: [kb/backlog, status/in-progress]
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

## Still open — `#status/planned`

| Item | Note |
|---|---|
| Information architecture: dedicated **Organizations / Subscriptions / Payments** screens | Today: Tenants, Registrations, Plans, plus tenant detail. A Payments screen listing invoices and gateway events across tenants does not exist |
| Tenant detail: subscription + payment history in one place | Partly there; the payment/invoice history and provider are not surfaced together |
| **Header split — Support *n* · Notifications *n*** | Blocked behind [[Support Inbox]]: half of it has nothing to count yet |
| Notification centre for the operator (list, read/unread, filters) | v2.79.0 shows the latest events on the dashboard; there is no dedicated screen |
| Finish the Indonesian sweep of the older Master Admin pages | Nav and tables are still English. Deliberately not half-done in one release — tracked here |

## Constraints that must hold

- Master Admin must not become a tenant operational dashboard (ADR 040).
- Read-only by nature: the console derives and displays; it does not mutate subscription state.
- Provider-agnostic: never couple a screen to Midtrans or iPaymu specifically.
- Platform data must remain unreachable for tenant users — `restrict.platform-admin` plus the tests
  in `PlatformOperationsTest` and `SubscriptionReadOnlyEnforcementTest`.
