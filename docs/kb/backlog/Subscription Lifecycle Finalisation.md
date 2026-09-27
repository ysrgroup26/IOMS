---
title: Subscription Lifecycle Finalisation
type: backlog
status: partly completed, partly blocked
updated: 2026-09-27
tags: [kb/backlog, status/implemented, status/blocked]
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

## What is genuinely open — `#status/blocked`

Two numbers, and both are already configuration, not code:

| Asked | Ships as | Setting |
|---|---|---|
| **7-day** grace | **14 days** | `SAAS_GRACE_DAYS` |
| **H-7** reminder | **H-14** (the renewal invoice is raised in the lead window) | `SAAS_RENEWAL_LEAD_DAYS` |

ADR 033 chose 14 deliberately: *"a renewal invoice in Indonesia routinely crosses a finance
department, a bank transfer and a public holiday."* Halving both shortens the window a paying
customer has before their operation goes read-only.

That is a commercial decision, so it is not being made in code. **To change it, set the two
environment variables** — no deployment of new logic is needed, and the banner, the emails, the
Billing page and the operations console all read them.

> [!question] Decision needed from the owner
> Keep 14/14 (current, documented), or move to 7/7 as the backlog asks, or split them
> (for example a 7-day grace with an H-14 first reminder)?

## Related

[[Project Board]] · ADR [[033-subscription-lifecycle\|033]] · [[Verification Status]]
