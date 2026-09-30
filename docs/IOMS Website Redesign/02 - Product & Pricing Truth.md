---
title: IOMS Website Redesign - Product and Pricing Truth
tags: [ioms, product-truth, pricing]
updated: 2026-10-01
status: verified; model implemented in v2.86.0
---

# Product & Pricing Truth

This is the evidence ledger for public product claims. Values remain unverified until checked against the current application repository and server-side enforcement. Do not publish guessed values.

## Verified from the provided project context

| Fact | Current truth | Evidence |
|---|---|---|
| Customer-facing operational workspaces | HSE; People / HRD; Warehouse Logistics; Management (four only) | User-provided project instructions, 2026-09-29 |
| Admin Space | Tenant administration; not a customer-facing operational workspace | User-provided project instructions, 2026-09-29 |
| Global Dashboard | Business only | User-provided project instructions, 2026-09-29 |
| Purchase sequence | Account creation, email verification, login, return to public site, company details/onboarding, package selection, payment, activation | Referenced conversation and current project instructions |
| Renewal | Existing renewal/perpanjangan logic remains unchanged | Referenced conversation and current project instructions |

These are verified as the project owner's intended product/public positioning. Validate implementation details in Phase 01.

## Unverified product and commercial facts

| Fact to verify | Value | Required source/evidence | Status |
|---|---|---|---|
| Package names | Unknown | Current package configuration and public site | Pending |
| Monthly prices | Unknown | Canonical pricing source and checkout amount | Pending |
| Annual prices/discounts | Unknown | Canonical pricing source and checkout amount | Pending |
| Billing periods and tax wording | Unknown | Checkout and subscription configuration | Pending |
| Benefits/features by package | Unknown | Product entitlements and implemented capabilities | Pending |
| Included users per package | Unknown | Server-side entitlement checks and UI/API paths | Pending |
| Additional-user mechanism and price | Unknown | Configuration, checkout, and server-side enforcement | Pending |
| Workspace availability by package | Unknown | Entitlement rules and actual workspace access | Pending |
| Subscription states and renewal terms | Unknown | Subscription service/actions and customer UI | Pending |

## Verification protocol

For every plan and user-limit claim:
1. Find the canonical plan/price/entitlement definitions.
2. Trace the displayed website value to package selection and checkout amount.
3. Inspect server-side entitlement enforcement, including relevant API/action paths and alternate UI paths.
4. Verify included and additional user behavior at boundary conditions.
5. Record source paths, relevant identifiers, observed behavior, and verification date.
6. If public copy and enforcement disagree, document the discrepancy and stop short of changing business logic without a separately approved scope.

## Claim publication gate

A price, user cap, benefit, workspace entitlement, or subscription claim can move to verified only when its authoritative source and enforcement behavior are recorded above. Never treat a frontend display value as proof of entitlement.

---

# Verified, 2026-09-30

Phase 01 is complete. Every value below is traced to a canonical source in the application repository and to server-side enforcement. The publication gate is satisfied and these claims are published.

## Canonical sources

| Question | Source of truth |
|---|---|
| What a plan GRANTS | `config/plans.php` |
| What a plan COSTS | `packages` table, seeded by `database/seeders/PackageSeeder.php` |
| The approved commercial model | `tests/Support/ApprovedCatalogue.php`, deliberately transcribed from the commercial decision rather than derived from code |
| Shape served to pricing surfaces | `app/Services/PricingService.php` |
| Additional user price | `config/saas.php` |

## Verified commercial facts

| Fact | Value | Evidence | Status |
|---|---|---|---|
| Package names | Starter, Professional, Business | `Package::public()->active()` returns exactly these three | VERIFIED |
| Monthly prices | Rp189.000 / Rp555.000 / Rp1.249.000 | ApprovedCatalogue and packages table agree; rendered in browser | VERIFIED |
| Annual prices | Rp2.268.000 / Rp6.105.000 / Rp14.988.000 | Same | VERIFIED |
| Annual terms | Starter pays 12 for 12. Professional pays 11 for 12, a discount. Business pays 12 for 14, extra service | `ApprovedCatalogue::ANNUAL`, `PricingService::annualTerms()` | VERIFIED |
| Included active users | 3 / 10 / 25 | `config/plans.php` `included_users`, `packages.max_users`, and a test asserting the two agree | VERIFIED |
| Allowance is not a ceiling | Additional active users purchasable on every plan | `UserEntitlementAndAddOnTest` | VERIFIED |
| Additional user price | Rp50.000 per user per month, one price on every plan | `config/saas.php`, `PricingService::additionalUserPrice()` | VERIFIED |
| Operating units per plan | 1 / 2 / 4 | `packages.max_companies` | VERIFIED |
| Workspace entitlement | Starter HSE. Professional adds People / HRD. Business adds Warehouse Logistics and Management | `config/plans.php` `workspaces` | VERIFIED |
| Four operational workspaces | hse, hr, logistics, management | `config/plans.php` `operational`, which the navigation layer intersects every answer against | VERIFIED |
| Global Company Dashboard | Business and Enterprise only | `config/plans.php` `global_dashboard` | VERIFIED |
| Enterprise | Retired from sale, `is_public = false`, grants and subscribers intact | packages table; excluded from `publicPlans()` | VERIFIED |
| Server-side enforcement | The stated allowance is enforced, a customer cannot set their own price, capacity cannot drop below active accounts | `UserEntitlementAndAddOnTest`, all passing | VERIFIED |

## What a user is

An **active login account**. Not a device: one account may sign in from several. Not an employee record: most people in a yard never log in, and employees and users are separate tables. A deactivated account frees its slot. A tenant administrator is counted once, like anybody else. Source: `UserEntitlementAndAddOnTest`, which asserts all three wrong readings.

## Correction to a previously recorded assumption

This document previously listed "Warehouse Logistics" among the four workspaces with the internal key unstated. The key is `logistics`. The standalone `warehouse` shell is a separate, retired concept, and `config/plans.php` records that Business buys Logistics and Warehouse as one domain. The public name is Warehouse Logistics; the granted key is `logistics`.

## Facts that are NOT public

- Enterprise pricing and scope. Retired from sale; still shown to its existing subscribers on their own Billing pages, which is why its description was corrected rather than deleted.
- The software edition string "Enterprise Edition" in `config/ioms.php` names the build, not the plan. It is serialized in props but never rendered. Flagged as WEB-014 for an owner decision.

---

# APPROVED FUTURE MODEL: metered PTW and a second user class

**Approved 2026-09-30. NOT IMPLEMENTED. NOT PUBLISHED.**

> [!warning] This section describes a model the code does not yet enforce
> Everything above this heading is the **current, enforced, published** truth. Everything below is
> **approved product direction awaiting implementation**.
>
> The publication gate in this document applies unchanged: a price, capacity or quota claim may not
> appear on the public website until its server-side enforcement exists and is verified. Publishing
> the figures below before then would recreate exactly the defect v2.85.0 was written to fix, where
> the FAQ advertised capacities the billing layer did not enforce.

## The approved model

| Plan | Price/month | Full Users | My Work Users | Included PTW documents per billing period | Workspaces |
|---|---|---|---|---|---|
| Starter | Rp189.000 | 3 | 10 | 50 | HSE |
| Professional | Rp555.000 | 10 | 30 | 200 | HSE + People / HRD |
| Business | Rp1.249.000 | 25 | 50 | 500 | HSE + People / HRD + Warehouse Logistics + Management + Global Company Dashboard |

Enterprise remains retired from public sale and public pricing. Plan prices and workspace scope are
unchanged from the current enforced model; what is new is the second user class and the PTW meter.

## User types

**Full User.** A normal IOMS account using the workspaces its plan and role authorize. Additional
Full Users remain **Rp50.000 per user per month**, unchanged.

**My Work User.** A restricted field or operational account, intended for My Work and PTW-related
field activity. Counted **separately** from Full Users. Additional capacity is sold in **packs of 10
at Rp100.000 per pack per month**, a recurring charge.

## PTW document metering

Each newly created PTW consumes one PTW document from available quota.

Two pools, which must remain distinguishable:

| Pool | Source | Expiry |
|---|---|---|
| Included monthly quota | The plan (50 / 200 / 500) | **Expires** at the end of the applicable billing period |
| Purchased quota | Top-up packs | **Carries forward** across billing periods until consumed |

> [!important] One counter is not acceptable
> The approved direction states explicitly that the implementation must be able to distinguish
> included monthly quota from purchased carry-forward quota. Collapsing them into a single remaining
> figure loses the expiry rule and cannot be reconstructed afterwards.

## PTW top-up packs

| Pack | Price | Effective per document |
|---|---|---|
| 50 PTW | Rp50.000 | Rp1.000 |
| 150 PTW | Rp120.000 | Rp800 |
| 500 PTW | Rp300.000 | Rp600 |

One-off purchases, not recurring. Quota carries forward until consumed.

## Behaviour when PTW quota is exhausted

- Creating a **new** PTW becomes unavailable and locked.
- My Work remains accessible.
- Existing assigned work remains accessible and workable.
- The customer can upgrade the subscription or purchase additional PTW quota.

Existing operational work is **never** locked merely because new-PTW creation quota is exhausted.
This is consistent with the v2.70.0 lifecycle principle already in force: expiry pauses new records
and never withholds what is already recorded.

## What this model changes about IOMS's own definitions

| Concept | Current enforced meaning | Meaning under the approved model |
|---|---|---|
| A "user" | One active login account, any kind. `EntitlementService::usersUsedCount()` counts them all | Two billable classes counted separately |
| `is_field_user` | A **landing preference**. Chooses My Work over Dashboard. Grants nothing, consumes no quota | Becomes, or is replaced by, a **billable account class** |
| `ptw_access` | A free permission to create a PTW. Costs nothing, is not capacity | Still gates authorization, but creation additionally consumes a metered, purchasable resource |
| Plan capacity | One number, total users | Two user numbers plus a document meter |

These are reversals of decisions taken deliberately in v2.53.0 and v2.82.0, not oversights. They are
recorded as reversals in `04 - Implementation Log.md` so the reasoning that produced the current
shape is not lost.

## Currently published copy that will contradict this model

The public FAQ, shipped in v2.85.0 and correct today, says:

> "PTW Access is a permission granted to specific accounts so they can raise a Permit To Work. It is
> not sold capacity and carries no extra charge."

and

> "Every plan carries an allowance of included active users: 3 on Starter, 10 on Professional, 25 on
> Business ... Additional active users are Rp50.000 per user per month on every plan."

Both are accurate against the enforced system today and both become wrong the day the new model
ships. They must change **in the same release that enforces it**, never before.

---

# STATUS UPDATE, 2026-09-30: the model above is now IMPLEMENTED and PUBLISHED

The section above was written as approved-but-unbuilt. It is now built, enforced server-side, verified
and published on the public website. v2.86.0.

The publication gate is satisfied for every figure: Full User and My Work allowances are counted and
enforced per class, the PTW meter blocks creation at zero, included quota expires at the period
boundary, purchased quota does not, and a top-up is credited only by a verified payment. Evidence is
in `05 - QA & Verification.md`, and the full decision record in `04 - Implementation Log.md`.

## Two clarifications the implementation settled

**A My Work User is defined by `users.user_type`, not by `is_field_user`.** The two are separate and
stay separate: `user_type` decides what an account may reach and how it is billed, `is_field_user`
decides where it lands after signing in. Every account that existed before v2.86.0 is a Full User,
which is what it already was.

**Enterprise is unmetered and uncapped on both new capacities.** It carries null for
`max_my_work_users` and `ptw_included_monthly`, which already means "no stated ceiling" everywhere
else on `packages`. Its subscribers bought a plan with no stated limit, so giving it a number would
have been a silent downgrade of a live customer.
