---
title: 041 — Billing mode is not entitlement
type: adr
status: accepted
decided: 2026-09-27
version: 2.80.0
tags: [adr, status/verified, subscription, billing]
---

# ADR 041 — Billing mode says how a tenant is PAID FOR, never what it may use

## Status

Accepted, implemented and verified in **v2.80.0**.

## The problem

IOMS could not say whether an organization pays.

Three genuinely different commercial arrangements were one indistinguishable `active`
subscription row:

| Arrangement | What it is | How it looked before v2.80.0 |
|---|---|---|
| **Complimentary** | Free by decision — a pilot, an internal account | An ordinary active subscription |
| **Manual** | Real money, settled by bank transfer and recorded by an operator | An ordinary active subscription with manually-paid invoices |
| **Paid** | Settled through a payment gateway | An ordinary active subscription with payment transactions |

Two concrete consequences, both real rather than theoretical:

- The nightly lifecycle job raised **real invoices** against accounts nobody intends to
  bill. Only the demo tenant was excluded.
- The operations console counted a free pilot as revenue-bearing workload, and nobody
  could answer *"how many organizations actually pay us?"* without inspecting invoices.

## The decision

One column — `subscriptions.billing_mode`, one of `paid` / `manual` / `complimentary`,
defaulting to `paid` so every pre-existing row keeps behaving exactly as it did.

**It answers exactly one question: who takes the money.** It is deliberately *not*
allowed to answer either of the other two questions the system already answers:

| Question | Answered by | Not by |
|---|---|---|
| Who takes the money? | `billing_mode` | — |
| What may this tenant *use*? | the plan's module and workspace grants | `billing_mode` |
| May they write *today*? | `Subscription::lifecycleState()` — the dates | `billing_mode` |

### What billing mode changes

- The nightly lifecycle command **skips invoicing** a complimentary subscription,
  in the same place and for the same reason the demo tenant is skipped.
- The operations console reports the **composition of the book** (paying / manual /
  complimentary) and leaves complimentary subscriptions out of the *Perlu Perhatian*
  work queue, because there is nothing to chase.
- Master Admin can set it, and the change is named in the activity log — turning a
  paying customer complimentary is a commercial decision somebody must be able to
  account for later.

### What it must never change

**It grants and withholds nothing.** A complimentary subscription whose period has
ended is still `lapsed` and still read-only.

That looks harsh until you consider the alternative. If `billing_mode` could keep a
tenant active past its dates, there would be **two** sources of truth for "may this
customer write today" — and the whole subscription lifecycle design (ADR
[[033-subscription-lifecycle|033]] §1) exists to keep that answer single and derived.
A stored flag that overrides the dates is the same mistake as a stored `status` column
that disagrees with them.

A free account genuinely meant to run indefinitely is expressed with the mechanism that
already exists and is honest about itself: **`type = lifetime`**, which has no period end
to run out. `BillingModeTest` asserts both halves of this — that complimentary does not
extend access, and that `lifetime` does.

## Consequences

- An existing tenant becomes paying by **changing one column**. Its id, users, operating
  units, subscription history and payment history are untouched, because nothing is
  recreated. (The stronger version of that requirement — that a *payment* never
  recreates a tenant — was already true and is proven end to end by
  `SubscriptionReadOnlyEnforcementTest`.)
- An unrecognised value in the column falls back to the **billable** behaviour, not the
  free one. A typo must not silently make a paying customer free.
- `manual` and `paid` are both billable: the difference between them is who takes the
  payment, not whether there is one. `isBillable()` is the question most callers
  actually want.

## Alternatives rejected

**A `is_complimentary` boolean.** It cannot express `manual`, which is the mode most
Indonesian enterprise customers actually use, and the distinction between "free" and
"billed outside the gateway" is exactly what an operator needs.

**A separate `billing_arrangements` table.** Nothing about a billing mode varies over
time in a way the subscription itself does not already record, so a second table would
be a join with no facts in it.

**Letting complimentary subscriptions skip lapsing.** Rejected above: it would make
billing mode a second entitlement system.

## Related

ADR [[033-subscription-lifecycle|033]] · ADR [[038-account-organization-subscription|038]] ·
ADR [[040-master-admin-is-an-operations-console|040]] · [[Project Board]]
