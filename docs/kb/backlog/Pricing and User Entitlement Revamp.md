---
title: Pricing and User Entitlement Revamp
type: backlog
status: COMPLETED (v2.82.0) — one scope item waits on the owner
updated: 2026-09-28
tags: [kb/backlog, status/verified]
---

# Pricing and User Entitlement Revamp

**Source:** owner decision, 2026-09-28 · **Board:** [[Project Board]] ·
**Decision:** ADR [[043-pricing-included-users-and-add-ons|043]]

## The approved model

| Plan | Monthly | Included active users | Scope | Annual |
|---|---|---|---|---|
| Starter | Rp189.000 | 3 | HSE | Rp2.268.000 — bayar 12, akses 12 |
| Professional | Rp555.000 | 10 | HSE + People/HRD | Rp6.105.000 — bayar **11**, akses 12 |
| Business | Rp1.249.000 | 25 | HSE + People/HRD + Logistics/Warehouse | Rp14.988.000 — bayar 12, akses **14** |

**Additional active user: Rp50.000/user/month, identical on every plan.**

## Every layer that had to move

Pricing was represented in more places than a pricing page, which is why this was audited before it
was edited:

| Layer | Change |
|---|---|
| `packages` table | Prices, allowances, new `annual_months`; Enterprise `is_public = false` |
| `subscriptions` table | New `additional_users` |
| `config/saas.php` | `additional_user_price` — one value, every plan |
| `config/plans.php` | Business scope narrowed; `warehouse` left the shell list; `included_users` for copy |
| `Package` | `includedUsers()`, `annualMonths()`, `annualPaidMonths()` |
| `Subscription` | `includedUsers()` / `additionalUsers()` / `seatLimit()` split; `additionalUserCharge()`; snapshot keys |
| `EntitlementService` | Counts **active** users; allowance, purchase, remaining, required-for |
| `SubscriptionLifecycleService` | `addCycle()` is plan-aware; renewal = plan + add-on |
| `PricingService` | `included_users`, `additional_user`, `annual_terms` |
| `SubscriptionController` | Capacity endpoint; billing payload; downgrade blocker |
| `PlatformController` | Active count and add-on on tenant detail; operator can set capacity |
| Pricing page, Billing page, Master Admin | Copy and controls |
| Seeder + `ApprovedCatalogue` | Kept in step so fresh and upgraded installs match |

## What is deliberately preserved

- **Historical billing integrity.** No subscription was repriced (the agreed-price snapshot exists
  for exactly this) and no invoice was rewritten. Asserted.
- **The lifecycle.** Active → Grace (7 days) → Lapsed/read-only, with suspended and cancelled as
  deliberate statuses. Untouched, and re-asserted with capacity in play.
- **`stateSnapshot()` as the one source of truth**, now carrying the capacity figures so the
  customer's Billing page and Master Admin cannot disagree.
- **Provider abstraction.** Nothing provider-specific was added; add-ons ride the existing invoice.

## Open: what is "Management"?

The approved Business scope reads *HSE + People/HRD + Logistics/Warehouse + **Management***. The
first three map exactly onto shipped department workspaces. The fourth does not.

The nearest real thing is the **Reports / Analytics** layer — which is global tier and already
granted to *every* plan, so it cannot be a Business differentiator. Nothing was invented to fill the
gap: granting a workspace key that does not exist is precisely the v2.58.0 empty-sidebar defect, and
inventing a department would sell something that is not built.

> [!question] Decision needed from the owner
> Either define "Management" as a real capability to be built, or drop the word from the Business
> scope. Business currently grants HSE, People, Logistics / PPIC and Warehouse.

## Related

ADR [[043-pricing-included-users-and-add-ons|043]] · ADR [[033-subscription-lifecycle|033]] ·
ADR [[041-billing-mode-is-not-entitlement|041]] · [[Verification Status]]
