---
title: 043 — Included users, paid extras, and three different annual offers
type: adr
status: accepted
decided: 2026-09-28
version: 2.82.0
tags: [adr, status/verified, pricing, entitlement, subscription]
---

# ADR 043 — The plan carries an allowance, not a ceiling

## Status

Accepted, implemented and verified in **v2.82.0**.

## The approved model

| Plan | Monthly | Included active users | Scope | Annual |
|---|---|---|---|---|
| **Starter** | Rp189.000 | 3 | HSE | Rp2.268.000 — pay 12, access 12 |
| **Professional** | Rp555.000 | 10 | HSE + People/HRD | Rp6.105.000 — pay **11**, access 12 |
| **Business** | Rp1.249.000 | 25 | HSE + People/HRD + Logistics/Warehouse | Rp14.988.000 — pay 12, access **14** |

**Additional active user: Rp50.000 per user per month, on every plan.**

## Three decisions that were not obvious

### 1. `max_users` is now an allowance, and the column stayed

The old model sold a large hard cap — 10 / 50 / 150 accounts — and hitting it was a wall. The new
model sells a small allowance and lets a customer buy past it.

The column was **reused rather than renamed**. It has always meant "how many login accounts this plan
carries"; what changed is that exceeding it is a purchase instead of a refusal. Renaming it would
have touched the plan editor, its validator, the seeder, the Master Admin UI and four test files to
express the same fact, and every one of those is a chance to get it half-done.

What a tenant may hold is therefore assembled in one place:

```
Subscription::seatLimit()  =  includedUsers()            (plan, or an operator override)
                            + additional_users           (what this customer bought)
```

`subscriptions.additional_users` belongs to the **subscription**, not the package, because it is that
customer's commercial arrangement — and that is also why it survives a plan change and counts
towards the limit on the new plan.

### 2. A user is an active login account — and three plausible readings are wrong

The entitlement layer had counted every account, active or not, and said so in a comment: *"if the
business would rather free a seat on deactivation, this one method is the only place that has to
change."* That decision has now been made, and with it three others that each would have overcharged
or undercharged somebody:

| Not a user | Why it matters |
|---|---|
| **A device** | One account signs in from a phone, a tablet and a site terminal. That is one user. There are deliberately no device seats anywhere in IOMS |
| **An employee record** | Most people in a yard never log in. `employees` and `users` are separate tables precisely for this, and nothing counts the former |
| **A deactivated account** | Deactivating now frees the slot, which makes it the supported way to release capacity **without deleting somebody's history** — what a system of record for safety compliance has to allow |

A Tenant Admin is a capability on an account, not a second kind of seat, and is counted once like
anybody else.

### 3. The annual benefit is not one rule, and Business's lives in the PERIOD

Three offers sit on one ladder and only two of them are discounts:

```
Starter       pay 12 → 12 months      nothing to say
Professional  pay 11 → 12 months      a price discount
Business      pay 12 → 14 months      extra SERVICE at the same price
```

Business is why `packages.annual_months` exists. Its annual price is **exactly twelve monthly
payments**, so there is no saving to derive from the two prices — a surface reading only
`annualSaving()` would present the strongest offer in the catalogue as having no annual benefit at
all. Worse, describing it as *"diskon 2 bulan"* would advertise a discount that is not being given.

So both halves are published (`paid_months`, `service_months`) and every surface renders the sentence
from them: **"Bayar 12 bulan, akses 14 bulan."** `addCycle()` is the single place a period length is
computed, so the benefit applies once and cannot be granted twice.

## Additional users are recurring, and billed with the plan

A charge, not a purchase. Nothing is invoiced at the moment capacity changes: the new quantity is
live immediately and priced into the **next renewal invoice**, which is the same document, the same
lifecycle and the same verified-payment path everything else uses.

**Why no immediate prorated invoice.** A plan upgrade issues one, because it changes what the product
*is* mid-period. Capacity does not — the customer is buying room inside a period they have already
paid for, and a partial month of a Rp50.000 line would produce invoices costing more to settle than
they collect. The page states this rather than implying it.

**On an annual cycle the add-on is billed for the months that are PAID for**, not the months
received. One rule for the whole invoice: Professional pays eleven months for the plan and eleven for
its extra users; Business pays twelve for both and receives fourteen months of everything. Billing
the service months instead would charge Business *more* for an add-on than the benefit it was given,
which inverts the offer.

**Releasing is free, immediate, and floored by usage.** Capacity may never drop below the accounts
currently active — the customer deactivates accounts first, which frees the slots. Nothing ever
deactivates a user on the customer's behalf, in this flow or in a downgrade.

## Enterprise is retired from sale, not deleted

It leaves the public catalogue (`is_public = false`) and keeps its row, its price, its grants and its
subscribers. Deleting it would orphan live subscriptions and rewrite the history of paid invoices;
hiding it stops it being sold while every existing customer continues exactly as before.

For the same reason this release **reprices nothing that already exists**. The
`agreed_price_monthly` / `agreed_price_yearly` snapshot added in ADR 033's era exists precisely so a
catalogue change cannot reprice a live customer, and no subscription row's price was touched.

## What was deliberately not done

- **No per-plan add-on price.** An extra account is the same thing on Starter as on Business, and
  charging more for it at the top would make an upgrade read as a penalty for the customers who grew.
  It is one config value, not a column, so there is one answer to one question.
- **No device or session limits.** Explicitly out of scope, and none exist.
- **No "Coming Soon" modules on any pricing surface.** Project Management and Procurement left the
  Business scope, and the Business plan *description* was rewritten with them — a card advertising
  departments the plan no longer grants is worse than one that says less.
- **No second entitlement path.** Everything goes through `EntitlementService` and the shared
  `Subscription::stateSnapshot()`, so the customer's Billing page and Master Admin read one answer
  (asserted by `UserEntitlementAndAddOnTest` and `SubscriptionStateParityTest`).

## Open question for the owner

**"Management" in the approved Business scope has no IOMS department to map to.** The scope was given
as *HSE + People/HRD + Logistics/Warehouse + Management*. The first three map exactly onto shipped
department workspaces; the fourth does not — the nearest real thing is the **Reports / Analytics**
layer, which is global tier and already granted to every plan.

Business therefore grants `hse`, `hr`, `logistics`, `warehouse`, and nothing was invented to fill the
gap: granting a workspace key that does not exist is the v2.58.0 empty-sidebar defect, and inventing
a department would sell something that is not built. If "Management" was meant to name a distinct
capability, it needs defining before it can be sold.

## Related

ADR [[033-subscription-lifecycle|033]] (the lifecycle this bills into) ·
ADR [[041-billing-mode-is-not-entitlement|041]] (how a tenant is paid for, a separate axis) ·
[[Project Board]] · [[Verification Status]]
