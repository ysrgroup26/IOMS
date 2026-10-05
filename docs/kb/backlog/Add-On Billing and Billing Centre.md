---
title: Add-On Billing and Billing Centre
type: backlog
status: COMPLETED (v2.103.0 – v2.104.0) — capacity is a purchase, and everything owed is in one place
updated: 2026-10-05
tags: [kb/backlog, status/verified]
---

# Add-On Billing and Billing Centre

**Source:** owner brief — add-on billing with immediate payment, activation only after payment, no
deferred billing, no proration, an independent billing and renewal date per add-on, early payment
that neither re-charges nor shifts the schedule; a Billing Centre with Pay Selected and Pay All
Outstanding; add-on cancellation that stops future renewal while preserving the paid period; PTW
Usage to keep its existing model · **Board:** [[Project Board]] ·
**Decision:** ADR [[049-an-add-on-is-a-purchase-with-its-own-clock|049]]

## What changed, and what it replaced

v2.82.0 sold capacity as a number on the subscription: the quantity went live at once and the charge
appeared on the **next renewal invoice**. Avoiding proration that way was right about the problem it
solved; deferring the charge to the subscription's anniversary to achieve it is what created two
others.

- **Capacity was used before it was paid for.** Ten seats could be added, used for a year, and never
  renewed.
- **Everything landed on one date.** Capacity bought the day after a renewal waited a full period to
  be billed; the day before, almost none. The same purchase bought wildly different amounts of free
  usage depending on timing.

An add-on is now a purchase with its own clock, settled the way a PTW top-up already was. The four
rules and the cancellation rule are in the ADR and pinned directly by `AddOnBillingFlowTest`.

## What was deliberately left alone

- **Capacity already granted** under the earlier arrangement, or granted by an operator, keeps its
  value and keeps counting. Re-selling it through the new flow would bill a customer twice for what
  they already have. `Subscription::additionalUsers()` adds the two sources once; no new capacity is
  written to the column.
- **Releasing capacity** stays free and immediate. Giving it back costs nobody anything.
- **PTW usage** keeps its existing model, as the brief required: a top-up is a one-off document
  credit with no period and is not an add-on.

## Why Pay All Outstanding does not make one payment

The Billing Centre lists everything owed with a total and offers Pay Selected and Pay All
Outstanding, but each sends the customer to the **oldest invoice concerned** and offers the next once
it settles.

One payment answers for one invoice because every reconciliation surface IOMS has is built that way:
the provider's dashboard, the `payment_transactions` row, the callback's amount check, the three
idempotency layers. Collapsing them would send the provider one amount for several financial records
and make "which invoice did this money settle" unanswerable the first time an amount did not match.
Changing `PaymentGatewayInterface` to carry a group would have touched every money path, and the
Duitku sandbox settlement is still externally blocked, so it could not have been proven end to end.
**This is a deliberate limitation, not an oversight** — the customer's real problem, "where is
everything I owe and how do I get through it", is solved by the list and the total.

## Also in this release (v2.103.0)

Two navigation gates that disagreed with the server, found while auditing My Work and PTW Access:

- **A My Work User was offered Admin Space and the operational workspaces**, every one of which
  `RestrictMyWorkUser` answers 403 for. Worse, a Business tenant's My Work User — sold at a fifth of
  a Full User's price — could open the **Global Company Dashboard**, the cross-workspace view that
  tier is sold for. It now redirects to My Work, the same treatment an unentitled tenant gets.
- **PTW Access granted, and no way to use it.** The permits page received
  `can.manage = canManageHse()`, while the server gate is `canCreatePtw()` — which also honours a
  granted, quota-checked, billed PTW Access. A granted account could raise a permit by URL and had
  no button to do it with: the customer pays for the access and it appears to do nothing. This is
  the **mirror** of the v2.98.0 defect — there the interface offered more than the server allowed,
  here it offered less.

## Verified

- **Migration applied, rolled back and re-applied** against MySQL.
- **`AddOnBillingFlowTest` (26)**, `MyWorkNavigationBoundaryTest` (8), `PtwAccessOfferTest` (5) —
  the My Work and PTW defects were each confirmed failing before the fix.
- The existing **`TransitiveTenantIsolationTest` caught the new model** and required it to be
  declared with a reason, which is exactly what that guard is for.
- `UserEntitlementAndAddOnTest`'s pricing test was **moved onto the purchase endpoint** rather than
  deleted, so the pricing-chain coverage survives the model change.
- **Full suite 953 passed / 5125 assertions.** Build clean, ESLint 0 errors. Browser: the Billing
  Centre, the add-on list and both pay buttons render against a real tenant with a pending and an
  active add-on.

## Links

- ADR [[049-an-add-on-is-a-purchase-with-its-own-clock|049]] · [[Employee Import and Admin Space Naming]]
- `docs/ARCHITECTURE.md` § *Capacity add-ons*, § *The Billing Centre*
