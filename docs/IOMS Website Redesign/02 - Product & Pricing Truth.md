---
title: IOMS Website Redesign - Product and Pricing Truth
tags: [ioms, product-truth, pricing]
updated: 2026-09-30
status: verified
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
