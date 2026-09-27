---
title: Subscription Lifecycle Finalisation
type: backlog
status: COMPLETED (v2.80.0)
updated: 2026-09-27
tags: [kb/backlog, status/verified]
---

# Subscription Lifecycle Finalisation

**Source:** `docs/FUTURE IDEAS/PROMPT 1.md` §1–§3 · **Board:** [[Project Board]]

## What was asked

`ACTIVE → H-7 reminder → expiry → 7-day full-access grace → read-only → renew → ACTIVE`, with
renewal never costing the customer the time they already paid for, and duplicate webhooks never
extending twice.

## What the repository actually does — `#status/verified`

Audited against the code, not assumed. All of this shipped between v2.70.0 and v2.78.1:

| Asked for | State | Where |
|---|---|---|
| Lifecycle active → grace → lapsed | **Done**, derived from dates on every read | `Subscription::lifecycleState()`, ADR [[033-subscription-lifecycle\|033]] |
| Read-only enforced server-side | **Done**, and proven across *every* write route | `EnforceSubscriptionWriteAccess`, `SubscriptionReadOnlyEnforcementTest` |
| Authenticate while lapsed; data stays readable | **Done** | same test |
| Renewal before expiry keeps remaining time | **Done** — `max(period end, now) + cycle` | `extendPeriod()`, ADR 033 §6 |
| Duplicate webhook cannot double-extend | **Done** — idempotency ledger + early return | `PaymentWebhookController`, `SubscriptionLifecycleTest` |
| Renewal restores access, same tenant | **Done** | `SubscriptionReadOnlyEnforcementTest` |
| In-app banner + Renew CTA + remaining time | **Done** | v2.77.0, `AuthenticatedLayout`, `Settings/Billing.jsx` |
| Reminder / grace / lapsed / renewed emails | **Done**, once per state per period | v2.78.0, `SubscriptionLifecycleEmailTest` |
| Agreed price, billing cycle, upgrade/downgrade rules preserved | **Done** | ADR 033 §7, v2.78.1 fixed a cross-cycle overcharge |

**Nothing in §1–§3 requires new code.** The lifecycle is finished.

## The timing was decided — `#status/verified` (v2.80.0)

The two numbers that were blocked on a commercial decision have been decided by the owner:
**7-day grace, H-7 reminder.** They now read as one policy — told a week before, a week after.

| Asked | Ships as | Setting |
|---|---|---|
| **7-day** grace | **7 days** | `SAAS_GRACE_DAYS` |
| **H-7** reminder | **H-7** | `SAAS_RENEWAL_LEAD_DAYS` |

They remain configuration, because they are commercial decisions and because lifecycle position
is derived on every read: changing the number takes effect immediately and retroactively, with
nothing to migrate and no job to re-run. Reasoning and the boundary assertions are in the ADR 033
v2.80.0 addendum.

Found while changing it: two test fixtures had the 14-day window baked in as magic numbers and
broke. They now express their dates relative to `Subscription::graceDays()`, so a test that names
the grace window keeps testing it after the next decision.

## And one answer on both sides — `#status/verified` (v2.80.0)

The prompt's hardest requirement was not a state but an *agreement*: Master Admin must never show
`Active` for a subscription the customer's own Billing page calls read-only.

Both sides already called `lifecycleState()`, so the calculation was never duplicated — but each
assembled its own surrounding facts, which is how two screens start disagreeing about one
subscription. `Subscription::stateSnapshot()` is now the only place a lifecycle fact is assembled,
and every surface spreads it (customer Billing, the Settings panel, Master Admin tenant list and
detail, the payment ledger, the support ticket context).

`SubscriptionStateParityTest` compares the two **rendered payloads** across active, grace, lapsed,
suspended, cancelled and renewed. Verified in a browser too: one tenant three days past its period
end read *Masa tenggang · read-only mulai 1 Okt 2026* to the operator and *Grace · READ-ONLY FROM
October 1, 2026* to the customer.

## Nothing in this note is open

This item is **COMPLETED**. Later subscription work belongs in its own note.

## Related

[[Project Board]] · ADR [[033-subscription-lifecycle\|033]] · [[Verification Status]]
