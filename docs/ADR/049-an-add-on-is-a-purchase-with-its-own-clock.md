# ADR 049 — An add-on is a purchase with its own clock

**Status:** Accepted (v2.104.0, 2026-10-05)
**Supersedes:** the capacity model introduced in v2.82.0
**Related:** [[041-billing-mode-is-not-entitlement|ADR 041]], `docs/PAYMENTS-DUITKU.md`

## Context

v2.82.0 sold extra capacity as a number on the subscription
(`subscriptions.additional_users`, `additional_my_work_packs`). The quantity went live
immediately and the charge appeared on the **next renewal invoice**. That was a deliberate
decision, and its reasoning is worth restating because it was not wrong about the thing it was
solving:

> charging for a partial month of a Rp50.000 line would produce invoices for a few thousand
> rupiah that cost more to settle than they collect

Avoiding proration was right. Deferring the charge to the subscription's anniversary to achieve it
was what created the problems:

- **Capacity was used before it was paid for.** A customer could add ten seats, use them for a
  year, and never renew. Nothing in the product had been paid for at the moment it was granted,
  which contradicts the rule every other paid thing in IOMS follows.
- **Everything landed on one date.** Capacity bought the day after a renewal waited a full period
  to be billed; capacity bought the day before was billed almost at once. The same purchase cost
  the customer wildly different amounts of free usage depending on when they made it.
- **Releasing and buying were the same action.** One number, one endpoint, two opposite commercial
  meanings.

## Decision

An add-on is a **purchase**, settled the way a PTW top-up already is, and it runs on **its own
period**. Four rules, and they are the whole of it:

| | |
|---|---|
| **Paid before granted** | `purchase()` raises an invoice and creates the add-on `pending_payment`. It grants nothing. Only a **verified payment** activates it. |
| **Its own clock** | the period starts at payment and renews on its own date. Nothing about an add-on touches the subscription's dates. |
| **No proration** | a period is bought whole, in both directions: no discount for buying mid-month, no refund for cancelling mid-period. |
| **Early payment is safe** | a renewal paid early extends from the existing `ends_at`, never from "now". The anniversary never drifts, and the same invoice never extends twice. |

**Cancellation stops the next renewal and nothing else.** `ends_at` is not moved, the capacity keeps
counting until that date, and no refund is computed. This is why `SubscriptionAddOn::isCounted()`
answers yes for a *cancelled* add-on whose period is still running, and why every reader must go
through `isCounted()` / `counted()` rather than testing the status. A customer who bought a period
keeps it.

## What this deliberately does not change

**The two columns on `subscriptions` stay, keep their values, and keep counting.** They now mean
"capacity granted under the earlier arrangement, or granted by an operator from Master Admin". That
capacity is already in use and was already billed that way; re-selling it through the new flow
would bill a customer twice for what they already have. `Subscription::additionalUsers()` is the one
place the two sources are added together, and **no new capacity is written to the column** —
`updateAdditionalUsers()` now refuses increases and points at the purchase.

**Releasing capacity stays free and immediate.** Giving capacity back costs nobody anything and must
not be made harder than taking it.

**PTW usage keeps its existing model.** A top-up is a one-off document credit with no period at all.
It is not an add-on and is not in this table.

## Why not one payment for several invoices

The Billing Centre lists everything owed and offers **Pay Selected** and **Pay All Outstanding**,
but each sends the customer to the **oldest invoice concerned** and offers the next once it settles.
One payment answers for one invoice, on purpose.

Every reconciliation surface IOMS has is built that way: the provider's own dashboard, the
`payment_transactions` row, the amount check in the callback, the three idempotency layers. A single
session covering several invoices would send the provider one amount for several financial records,
and the first time an amount did not match, "which invoice did this money settle" would be
unanswerable. The customer's actual problem — *where is everything I owe, and how do I get through
it* — is solved by the list and the total, not by collapsing the records behind them.

## Consequences

- An add-on's capacity can lapse while the subscription is healthy, which is new and correct: the
  customer stopped paying for that capacity, not for the plan.
- The nightly job gained a second pass, keyed on add-on dates rather than subscription dates.
- `invoices.subscription_add_on_id` says which add-on an invoice buys a period of, so settlement
  does not re-derive it from line items.
- Prices are **snapshotted** on the add-on at purchase, like `subscriptions.agreed_price_monthly`,
  so a catalogue change cannot reprice a customer who already bought.

Pinned by `AddOnBillingFlowTest`, which asserts each of the four rules directly, including that
paying a renewal early does not move the anniversary and that cancelling does not take back a period
already paid for.
