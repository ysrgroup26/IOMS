---
title: IOMS Website Redesign - Implementation Log
tags: [ioms, website-redesign, project-log]
updated: 2026-10-01
---

# Implementation Log

Append one entry for every meaningful project change. Keep entries factual and link to changed files or QA evidence where possible.

## 2026-09-29 - Documentation setup

- **Phase:** Preparation, before Phase 01.
- **Work completed:** Created the six-file Obsidian documentation system from the supplied project instructions.
- **Files changed:** See the six files in this folder.
- **Important decisions:** Public website/landing page only; protected application and transaction logic remains untouched; exactly four operational workspaces; Admin Space is tenant administration; Global Dashboard is Business only; no unverified pricing claims.
- **Verification performed:** Confirmed the requested directory contents after creation. Application code was not inspected or modified.
- **Issues discovered:** Application repository and canonical product/pricing configuration were not present at the supplied location; package/price/entitlement values remain unverified.
- **Commit hash:** None.
- **Next action:** Locate the actual IOMS application repository and complete Phase 01 audit.

## Entry template

### YYYY-MM-DD - Phase NN

- **Work completed:**
- **Files changed:**
- **Important decisions:**
- **Verification performed:**
- **Issues discovered:**
- **Commit hash:**
- **Next action:**

## 2026-09-30 - Phase 01: repository and product-truth audit

- **Phase:** 01, complete.
- **Work completed:** Full audit of the public website against the live codebase. The repository was unavailable when this documentation set was written, so every pricing, entitlement and capability claim was previously unverified. All of them are now traced to source.

### Canonical sources located

| Question | Authoritative source |
|---|---|
| What a plan GRANTS | `config/plans.php` |
| What a plan COSTS | `packages` table, seeded by `database/seeders/PackageSeeder.php` |
| Approved commercial model | `tests/Support/ApprovedCatalogue.php` |
| Public plan shape served to pages | `app/Services/PricingService.php` |
| Public copy | `app/Http/Controllers/PublicController.php` constants |
| Indexable pages and metadata | `config/seo.php` |
| Additional user price | `config/saas.php` |

### Pricing verified against the approved catalogue

`tests/Support/ApprovedCatalogue.php` confirms every figure in the project direction. Prices are never sent from the browser: `PricingService::amountFor()` reads the `packages` row, so a crafted form cannot set its own amount.

| Plan | Monthly | Included users | Workspaces |
|---|---|---|---|
| Starter | Rp189.000 | 3 | HSE |
| Professional | Rp555.000 | 10 | HSE + People / HRD |
| Business | Rp1.249.000 | 25 | HSE + People / HRD + Warehouse Logistics + Management |

Additional user Rp50.000/user/month, one price on every tier. Enterprise is `is_public = false`, retired from sale with grants intact for existing subscribers. `config/plans.php` `operational` pins the four customer-facing workspaces and `global_dashboard` pins the Global Company Dashboard to Business and Enterprise. Entitlement enforcement is server-side and was not modified.

### Defects found in public copy (product-truth violations)

| ID | Defect | Location |
|---|---|---|
| WEB-001 | FAQ states Starter includes 10 accounts, Professional 50, Business 150. Actual: 3 / 10 / 25. A published price and capacity that contradict enforcement. | `PublicController::FAQS` |
| WEB-002 | FAQ markets Enterprise publicly ("Can Enterprise be customized?", "Enterprise has no stated ceiling") although it is retired from sale. | `PublicController::FAQS` |
| WEB-003 | "What is IOMS?" names procurement, assets, maintenance and quality control as covered domains. All retired from the customer-facing product. | `PublicController::FAQS` |
| WEB-004 | How It Works step 02 describes procurement raising a purchase requisition and maintenance opening a Work Order. | `PublicController::HOW_IT_WORKS` |
| WEB-005 | Landing copy names projects, procurement and work orders as sold scope. | `Welcome.jsx` lines 128, 188, 282 |
| WEB-006 | SEO titles and descriptions name projects, procurement and maintenance, and carry the em dash character. | `config/seo.php` |
| WEB-007 | Em dash character present throughout public copy, against the standing rule. 65 occurrences across public surfaces, some in code comments rather than copy. | multiple |
| WEB-008 | FAQ describes PTW Access and My Work as sold concepts; needs verification against current product state. | `PublicController::FAQS` |

- **Important decisions:** No change to `config/plans.php`, `PricingService`, entitlement logic, checkout, webhooks or subscription lifecycle. The audit found the backend correct; every defect is in the presentation layer. Public site stays English, continuing the v2.64.0 decision, with Indonesian retained for legal documents.
- **Verification performed:** Static audit only. Prices cross-checked between `ApprovedCatalogue`, `PackageSeeder` and the pricing migration. No migration or build run yet.
- **Issues discovered:** WEB-001 through WEB-008 above.
- **Commit hash:** None yet.
- **Next action:** Fix the product-truth defects first, since a false published price is the highest-severity item, then the visual redesign.

## 2026-09-30 - Phases 02 to 11: redesign executed

- **Phase:** 02 through 11, complete. Execution order was chosen to put product-truth defects first, because a published price that contradicts server-side enforcement is a commercial liability and a visual redesign is not.

### Work completed

**Product truth (WEB-001 to WEB-008).** All eight defects from the Phase 01 audit are fixed. The false capacity claim (10 / 50 / 150 accounts) now states the real allowance of 3 / 10 / 25 plus Rp50.000 per additional active user. Enterprise is no longer marketed. Procurement, Project Management, Quality Control, Maintenance, purchase requisitions and work orders are gone from every public surface. WEB-008 was investigated and dismissed: PTW Access and My Work are real, verified against `users.ptw_access`, `users.is_field_user` and the `/my-work` route.

**Two components were the largest violations and were rebuilt rather than reworded.** `ConnectedOperations` is the hero diagram and orbited eight departments, four of them retired, making it the most prominent untrue claim on the site. It now carries the four operational workspaces plus Reports & Analytics, five nodes recomputed at 72 degree steps. `FragmentedToConnected` demonstrated a Material Request travelling through Project Management and Procurement; the chain now crosses only sold workspaces and is shorter for it.

**Em dash elimination.** Removed from all public copy across the page constants, `config/seo.php`, eleven JSX pages, three public components, `LegalDocuments`, the Blade layout and the `packages` table.

**Visual system.** Introduced Archivo as the display face and IBM Plex Mono for instrument labels, loaded in one request beside Inter. Inter remains the UI face and `font-sans` is untouched, so no authenticated surface changes. The landing hero was rebuilt from a centred stack over dark to a left-aligned editorial composition with the four workspaces as a specification list. `PublicPageHero` and `SectionHeading` were moved to the same left margin and type treatment, which propagates the system to every public page. `/pricing` was rebuilt onto the shared `PublicPageHero`, removing a fourth hand-rolled copy of the navy band.

### Newly discovered work, not in the original scope

| ID | Finding | Status |
|---|---|---|
| WEB-009 | `version.history`, roughly 253 KB of internal changelog, was shared on every Inertia response. An anonymous visitor downloaded the complete release history inline in the HTML before the marketing site rendered, and that history names retired workspaces and internal defects the public site deliberately omits. | FIXED. Scoped to authenticated users. Only `AboutDialog` reads it, mounted only in `AuthenticatedLayout`, and it already renders nothing when absent. Landing page HTML fell from about 370 KB to 103 KB. |
| WEB-010 | `workspace_catalog` served the entire Workspace table to guests, including retired departments. `EntitlementService::grantedWorkspaceKeys()` treats a tenant-less caller as unrestricted, the deliberate v2.13.0 fail-open, so a guest took that branch. | FIXED. Returns an empty collection for guests. Read only by `AuthenticatedLayout` and Settings, both session-gated. Entitlement logic untouched: this changes what is serialized, never what a tenant may reach. |
| WEB-011 | `packages.description` is rendered on pricing cards but lives in a database column, so every prior copy review missed it. Business described itself as "Logistics / PPIC and Warehouse" and never mentioned Management, which it has granted since v2.83.0. All four rows carried em dashes. Enterprise claimed "every operational department", untrue since v2.84.0 narrowed it to Business scope. | FIXED by a reversible data-only migration, applied, rolled back and re-applied against MySQL. `PackageSeeder` updated to match so a fresh install does not reintroduce it. |
| WEB-012 | `LandingPositioningTest` asserted the hero names "projects" and "procurement". It passed because the hero did, so a green test was the reason the retired-workspace copy survived v2.84.0. | FIXED. The assertion is inverted: the four sold workspaces must be present and the retired names must be absent. The verbatim headline regex was dropped, since transcribing the h1 fails on every copy edit while proving nothing. |
| WEB-013 | The Ziggy route manifest ships every internal route name, including `procurement.*`, to public pages. | NOT FIXED, deliberately. Not visible copy; it is route metadata. Filtering Ziggy affects the `route()` helper site-wide, and a wrong allow-list breaks navigation silently rather than loudly. Out of scope for a website redesign and it deserves its own change with its own verification. Recorded in 05 as a known limitation. |
| WEB-014 | `config/ioms.php` sets `edition` to "Enterprise Edition" and `license` to "Commercial Enterprise License". These name the software edition, not the retired subscription plan, and are serialized in props but never rendered publicly. | NOT CHANGED. Renaming a product edition is product direction, not a redesign decision. Flagged for the owner. |

### Important decisions

- **Public site stays English.** Continues the v2.64.0 decision. Legal documents remain Indonesian, and `config/seo.php` keeps `lang: id` for them. Indonesian remains in the pricing page's own explanatory paragraph, which was already there and is the audience's language for a definition that has to be unambiguous.
- **A new display typeface was justified, not decorative.** Inter at headline size is the most recognisable typographic signature of a generated marketing page. Archivo is a grotesque with squarer bowls that reads as industrial signage; the mono labels borrow the convention of a reading taken off a system. The UI font is unchanged, so the cost is one font request on public pages only.
- **Placeholder dashes became "Not set".** Empty-value cells used the em dash as a glyph. Rather than substituting another dash, they now say what they mean.
- **Backend untouched.** No change to `config/plans.php`, `PricingService`, `EntitlementService`, checkout, webhooks, subscription lifecycle, renewal or authorization. The audit found the backend correct; every defect was in presentation. The two middleware changes decide what is serialized to a page, never what anyone may access.

### Files changed

`app/Http/Controllers/PublicController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Support/LegalDocuments.php`, `config/seo.php`, `resources/views/app.blade.php`, `tailwind.config.js`, `resources/js/Layouts/PublicLayout.jsx` (unchanged in the end), `resources/js/Components/shared/PublicPageHero.jsx`, `resources/js/Components/public/{ConnectedOperations,FragmentedToConnected,PlatformShowcase,domainStories}.jsx|js`, `resources/js/Pages/Public/{Welcome,Pricing,Platform,Solutions,HowItWorks,Faq,Sandbox,Contact,Checkout,GetStarted,RegistrationStatus}.jsx`, `database/seeders/PackageSeeder.php`, `database/migrations/2026_09_30_090000_rewrite_package_descriptions_for_public_website.php`, `tests/Feature/LandingPositioningTest.php`.

- **Verification performed:** See `05 - QA & Verification.md`. Full suite 692 passed / 3813 assertions, 0 failures. Production build clean. ESLint 0 errors. Migration applied, rolled back and re-applied against MySQL. Every public route exercised in a real browser at desktop and mobile widths.
- **Commit hash:** `2ed3ac9`, pushed to origin/main.
- **Next action:** Owner decision on WEB-014. WEB-013 to be scheduled as its own change.

## 2026-09-30 - New approved direction: metered PTW and a second user class

- **Phase:** New scope, approved. **No implementation performed. No application code changed.** This
  entry records the decision and the architectural audit that must precede any coding.
- **Work completed:** Read the approved model, audited the existing architecture against it, and
  recorded the required work, the conflicts and the open decisions. The model itself is in
  `02 - Product & Pricing Truth.md`.

### The decision

Two billable user classes (Full User, My Work User) plus a metered PTW document quota with two
pools, included-and-expiring and purchased-and-carrying-forward. Plan prices and workspace scope are
unchanged. See `02 - Product & Pricing Truth.md` for the figures.

---

### Conflicts with the existing system

These are **documented deliberate decisions the new model reverses**, recorded rather than quietly
overwritten, as the approved direction requires.

#### C-1. v2.53.0 retired a second seat pool on purpose. This reintroduces one.

`EntitlementService::ptwUserQuota()` still carries its own reasoning:

> "PTW Access used to be a second seat pool alongside `max_users`, which made every plan card read as
> two numbers a buyer had to reconcile. Capacity is now expressed as TOTAL USERS."

The approved model puts two user numbers back on every plan card, plus a third figure for PTW
documents. The commercial decision is the owner's to make; what must not happen is making it without
noticing it was already made the other way. The pricing page will need to present three numbers per
tier without becoming the reconciliation exercise v2.53.0 removed.

#### C-2. `is_field_user` is a landing preference, not a restricted account. **This is the blocker.**

`SettingsController::updateFieldAccess()` states it outright:

> "`is_field_user` -> which WORKSPACE you land in (My Work vs Dashboard) ... It therefore consumes NO
> quota and grants NO capability."

A "My Work User" **does not exist today as a restricted account**. The flag chooses a landing route
and nothing else; the account retains whatever its role and department grant. There is no reduced
permission set, no data restriction, no narrower session.

The commercial consequence is direct. A My Work User costs Rp10.000 per user per month
(Rp100.000 per pack of 10); a Full User costs Rp50.000. If the two classes are not genuinely
different in what they can do, a tenant buys My Work packs and receives Full User capability at a
fifth of the price. **Selling the cheaper class before the restriction exists is revenue leakage by
construction, not a rollout detail.**

Defining what a My Work User may actually do is therefore a prerequisite for the entire model, not a
later refinement. It is the largest single piece of work identified here.

#### C-3. User counting is one pool and does not know about types.

`EntitlementService::usersUsedCount()` counts every active account in the tenant. Under the new
model a My Work User would consume Full User allowance. Splitting it touches the seat check, the
Settings capacity panel, Admin Space, the Platform Admin tenant list and the downgrade-safety rule
that refuses a plan change which would strand active accounts, which now has to be evaluated per
class.

#### C-4. `ptw_access` is documented and published as free.

It is a permission, granted by HSE to an existing account, costing nothing. The public FAQ shipped in
v2.85.0 says so explicitly. The new model does not change the permission, but it makes the action it
authorizes consume a purchasable resource, so both the internal semantics and the published copy
need revising **in the release that enforces it, not before**.

#### C-5. `ptwUserQuota()` fails open, and a metered resource must not.

It returns `null` (no ceiling) for an unconfigured tenant, deliberately, following the v2.13.0
fail-open principle that makes entitlement safe to switch on. That is right for **access** and wrong
for a **meter**: failing open on a metered, billable resource means unlimited free consumption. A PTW
quota check must fail in a defined, non-free direction, and must not be built by extending this
method's semantics.

#### C-6. `packages.max_ptw_users` still exists, with the wrong meaning.

The column survives (`2026_09_19_100240_retire_ptw_seat_entitlement.php` retired the behaviour, not
the column). Its old meaning is **PTW-enabled seats**, which is not My Work Users and not PTW
documents. Reusing it because it is conveniently present would put a third meaning on a column that
already had two. Either drop it or leave it dead; do not repurpose it.

#### C-7. Invoices have a single amount and no line items.

`invoices` carries one `amount` column. The current add-on model folds the additional-user charge
into the renewal total via `Subscription::additionalUserCharge()`. The new model adds a recurring My
Work pack charge **and** a one-off top-up purchase. A customer who buys a top-up and later disputes
an invoice has no itemisation to read. Whether to introduce invoice line items is an architectural
decision with a blast radius beyond this feature.

#### C-8. `Invoice::PURPOSES` has no concept of a one-off purchase.

The enum is `onboarding`, `renewal`, `plan_change`. Every one is tied to a subscription period. A PTW
top-up is a one-off purchase that grants a durable, carry-forward resource and belongs to no period.

#### C-9. Only a verified payment webhook may grant anything.

This is a standing and correct constraint: `PaymentWebhookController` is the only route in IOMS
permitted to settle an invoice or activate a tenant, and reaching a confirmation page grants nothing.
Top-up quota must be credited on the verified webhook and nowhere else. This is a constraint to
preserve, not a conflict, but it is the single easiest thing to get wrong in a purchase flow.

#### C-10. The Sandbox and the demo seeder create PTWs.

`DemoTenantSeeder` creates permits directly, and the public Sandbox is a real signed-in session. A
demonstration must not consume a real tenant's quota, and the Sandbox must not be a way to observe
quota exhaustion in a product tour.

---

### Implementation work identified

Nothing below has been started.

**Plans and catalogue**
1. Represent three capacities per plan: Full Users, My Work Users, included PTW documents. Decide between new `packages` columns and `config/plans.php`, following the existing split where what a plan **costs** is the table and what it **grants** is config.
2. Extend `tests/Support/ApprovedCatalogue.php` with the new figures. It is transcribed from the commercial decision on purpose and is the assertion the catalogue is measured against.
3. Price the two add-ons: the recurring My Work pack and the three one-off PTW packs.

**User classes**
4. Define what a My Work User may actually do. See C-2. This gates everything else.
5. Introduce an account class and migrate existing accounts into it, deciding what an existing `is_field_user` account becomes.
6. Split user counting, the seat check, remaining-slots and downgrade safety per class.
7. Update every surface that shows capacity: Settings, Admin Space, Platform Admin, Billing.

**PTW metering**
8. A quota ledger that keeps included and purchased balances distinct, with consumption order defined (see D-3).
9. Consume on PTW creation, at the one real creation path (`PermitToWorkController::store()`), with row-level locking against concurrent creation. Precedent exists: `canEnablePtwAccess()` already uses a row-locking transaction.
10. Block creation when exhausted: the controller gate, `StorePermitToWorkRequest::authorize()`, the My Work tile and the Create button, with an explanatory state rather than a 403.
11. Expire included quota at the period boundary and carry purchased quota forward, in `RunSubscriptionLifecycle` / `SubscriptionLifecycleService`.
12. Exclude the Sandbox and demo seeding from real consumption.

**Billing and purchasing**
13. A purchase flow for PTW packs and My Work packs, reusing the existing invoice and webhook path.
14. A new invoice purpose for one-off purchases, and a decision on line items (C-7, C-8).
15. Credit purchased quota only on the verified webhook (C-9).
16. Include the recurring My Work pack charge in renewal totals alongside the existing additional-user charge.

**Visibility**
17. Customer-facing quota display: remaining included, remaining purchased, period end, and how to buy more.
18. Platform Admin visibility and a per-tenant override, matching the existing `subscriptions.seat_limit` override precedent.
19. Reporting on PTW consumption.

**Website and copy**
20. Rework the pricing cards to carry three figures per tier without becoming a reconciliation exercise (C-1).
21. Add top-up pack pricing to the public site.
22. Rewrite the two FAQ answers that will become false (C-4), in the same release.
23. Re-run the full v2.85.0 verification set: em dash sweep in both encodings, retired-concept sweep, responsive checks.

**Tests**
24. Server-side enforcement tests for both user classes and the PTW meter, to satisfy this project's own publication gate before any figure is published.
25. Boundary tests: exhaustion, period rollover, carry-forward, concurrent creation, downgrade with active accounts of both classes.
26. A test asserting included quota expires and purchased quota does not.

---

### Open decisions required before coding

| ID | Question | Why it blocks |
|---|---|---|
| **D-1** | What can a My Work User actually **not** do? | C-2. Without a real restriction the cheaper class is a discount on the same product. Everything else depends on this answer. |
| **D-2** | What is a "billing period" for PTW quota on an **annual** plan? | Annual Starter: 50 per month for 12 months, or 600 once for the year? The two behave differently at every boundary and give different revenue. |
| **D-3** | Which pool is consumed first, included or purchased? | Consuming included first maximises carry-forward value to the customer; consuming purchased first maximises expiry. This is a commercial choice and must be explicit, not emergent. |
| **D-4** | Business annual grants **14 months of service for 12 paid**. How many PTW allocations? | 12 or 14. The existing annual-terms model already treats this tier as a special case. |
| **D-5** | Does quota reset during **grace**, and what happens while **lapsed**? | The v2.70.0 lifecycle already pauses new records when lapsed, which may make PTW blocking redundant in that state. Grace is undecided. |
| **D-6** | What counts as "newly created"? Does a deleted or rejected draft refund a document? | Determines whether consumption is at draft creation or at submission, and whether a refund path exists at all. |
| **D-7** | What do **existing tenants** receive at migration, including Enterprise, which has null capacities? | Existing subscribers must not be worse off without a decision. Enterprise has no stated ceiling today. |
| **D-8** | Does the My Work pack quantity behave like `additional_users`, surviving a plan change? | v2.82.0 decided purchased capacity belongs to the subscription and survives a plan change. Consistency suggests yes; it should be stated. |
| **D-9** | Is unused purchased quota refundable, and does it survive cancellation? | Carry-forward "until consumed" does not say what happens when the subscription ends. |

---

- **Files changed:** Documentation only. `02 - Product & Pricing Truth.md`, `03 - Website IA & Content.md`, `04 - Implementation Log.md` (this entry), `05 - QA & Verification.md`, `00 - Master Brief.md`.
- **Verification performed:** Architectural audit by reading source: `EntitlementService`, `Subscription`, `Invoice`, `User`, `PermitToWorkController`, `StorePermitToWorkRequest`, `SettingsController`, `SubscriptionLifecycleService`, `RunSubscriptionLifecycle`, `config/plans.php`, the packages schema and the migration history. Confirmed by direct schema inspection that `packages.max_ptw_users` still exists, and by grep that `PermitToWorkController::store()` is the only application PTW creation path. **No tests were run and no code was changed, because nothing was implemented.**
- **Issues discovered:** C-1 through C-10 above; open decisions D-1 through D-9.
- **Commit hash:** documentation only.
- **Next action:** Owner answers D-1 first. It is the prerequisite for the rest, and answering it late would invalidate work built on an assumed answer.

## 2026-09-30 - v2.86.0: the monetization model, implemented

- **Phase:** Implementation, complete and verified. Supersedes the "approved, not implemented" entry above.
- **Work completed:** Two billable user classes and a metered PTW document quota, end to end: schema, entitlement, enforcement, billing, UI and public copy.

### The nine open decisions, resolved

| ID | Question | Final answer | Where it lives |
|---|---|---|---|
| D-1 | What may a My Work User not do? | Everything outside My Work, assigned field work, PTW, tasks and its own account. Enforced server-side by route-name allow-list | `RestrictMyWorkUser` |
| D-2 | Billing period for quota on an annual plan | Monthly windows on every cycle. An annual plan gets 12 allocations, never a lump sum | `PtwQuotaService::windowFor()` |
| D-3 | Which pool is consumed first | Included, then purchased. The customer loses what was going to expire before what they paid extra for | `PtwQuotaService::consume()` |
| D-4 | Business annual, 14 months access | 12 PTW allocations, not 14. Extra access is platform access, not entitlement | `plans.ptw_annual_allocations` |
| D-5 | Grace and lapsed | No new behaviour. The v2.70.0 lifecycle already pauses new records when lapsed, and quota is not consulted for reads | unchanged |
| D-6 | What counts as created, and refunds | Consumption on successful creation. No refund on delete or cancel | `ptw_quota_consumptions`, no cascade |
| D-7 | Existing tenants | Every existing account becomes a Full User. Enterprise keeps null capacities, so it stays unmetered and uncapped | both migrations |
| D-8 | Do My Work packs survive a plan change | Yes, on the subscription, like `additional_users` | `subscriptions.additional_my_work_packs` |
| D-9 | Refunds and cancellation | Purchased quota is non-refundable and carries forward until consumed | `creditPurchased()` |

### Architecture decisions

**`user_type` is a new column, not a reinterpretation of `is_field_user`.** This is the decision the whole feature turns on. `is_field_user` is a landing preference that grants and restricts nothing, and real accounts carry it today: foremen and HSE staff who legitimately reach other workspaces. Treating it as the cheap class would have demoted every one of them on deploy, removing access those customers already pay for. So the two stay separate: `user_type` decides what you may reach and how you are billed, `is_field_user` decides where you land. Every existing account migrates to `full`, which is what it already is.

**The restriction is an allow-list.** A deny-list of forbidden workspaces would need updating whenever a route is added, and forgetting means a cheap account silently gains an expensive capability. An allow-list fails the other way: a new route is unreachable for the class until someone decides otherwise, which is visible and fixable rather than invisible and billable.

**Quota is a grant ledger, not a counter.** `ptw_quota_grants` holds each grant with its own kind, quantity, consumed count and expiry; `ptw_quota_consumptions` records which permit spent which grant. A counter cannot express included-expires and purchased-does-not, and cannot prevent a permit being charged twice. Both properties are enforced by schema: a unique index on `permit_to_work_id`, and a deliberately non-cascading foreign key so consumption outlives the permit. That closes create-delete-repeat by construction rather than by policy.

**Included grants are issued lazily as well as by the nightly job**, made safe by a unique index on (tenant, term, sequence). A tenant that signs up mid-month, or whose cron missed a night, must not be unable to raise a permit because a scheduled task did not run.

**The meter fails open only where the meter is not sold.** A plan with no `ptw_included_monthly` (Enterprise) is unmetered, following the same direction `EntitlementService` takes for an unprovisioned tenant, because the alternative is a misconfigured plan silently blocking a safety-critical permit. A tenant that HAS a figure is metered strictly.

**Invoices gained real line items.** `invoices.amount` stays the authoritative total and the payment path is untouched; `invoice_items` describes what it is made of, written by the same code that computes the total. Every pre-existing invoice was backfilled with one line, so no invoice is left unitemised.

**A top-up is a purchase, not a period.** New `Invoice::PURPOSE_TOPUP`, handled first in `applyPaidInvoice()` and returned from, because everything below moves subscription dates and a top-up moves none. Falling through would have granted a free month per top-up. Credited only from the verified-payment path, idempotent on the invoice.

### Files changed

Migrations: `2026_09_30_120000_establish_user_classes_and_ptw_metering`, `2026_09_30_120100_set_plan_my_work_and_ptw_capacity`.
New: `PtwQuotaService`, `PtwQuotaGrant`, `PtwQuotaConsumption`, `InvoiceItem`, `PtwQuotaController`, `RestrictMyWorkUser`, `Pages/Ptw/Quota.jsx`, `Components/shared/PtwQuotaNotice.jsx`, `UserClassAndPtwQuotaTest`.
Changed: `User`, `Package`, `Subscription`, `Invoice`, `EntitlementService`, `SubscriptionLifecycleService`, `PricingService`, `PermitToWorkController`, `SettingsController`, `SubscriptionController`, `HandleInertiaRequests`, `bootstrap/app.php`, `routes/web.php`, `config/plans.php`, `config/saas.php`, `config/seo.php`, `PublicController`, `Pricing.jsx`, `Welcome.jsx`, PTW `Index`/`MyIndex`, `PricingConsistencyTest`, `TransitiveTenantIsolationTest`.

### Two existing tests were changed, and why

**`PricingConsistencyTest` forbade any PTW-shaped field on a pricing payload**, pinning the v2.53.0 decision that PTW is not sold capacity. The approved model reverses half of that: PTW *documents* are now sold, PTW *seats* stay retired. The assertion is now specific to `max_ptw_users` and additionally requires the document allowance to be stated, so the retired concept still fails loudly while the new one is pinned.

**`TransitiveTenantIsolationTest` caught all three new models**, which is the guard working as designed. `PtwQuotaGrant` and `PtwQuotaConsumption` carry `tenant_id` and every read constrains it; they are declared tenant-owned rather than company-scoped because quota is bought and spent by the tenant. Scoping by company would silently give a tenant one allowance per operating unit.

### Discovered during implementation

- **`Invoice::generateNumber()` takes a tenant id**, and `STATUS_UNPAID` does not exist (`STATUS_ISSUED` does). Found by writing the purchase flow against the real API rather than an assumed one.
- **The default development tenant is on Enterprise**, so it is unmetered. That is the migration behaviour working, and it means the metered paths are covered by tests rather than by clicking this particular tenant.

### Deferred, with reasons

| Item | Why |
|---|---|
| Platform Admin per-tenant quota override | The customer-facing model is complete and enforced. An operator override is an operations convenience, and `subscriptions.seat_limit` already sets the precedent for how it should look. Not required for the model to be correct |
| PTW consumption reporting beyond the last 20 | The quota page shows recent consumption and the ledger holds the full history. A report over it is reporting work, not metering work |
| Buying My Work packs from the UI | Capacity is stored, charged at renewal, enforced and honoured by downgrade safety. The self-service purchase screen for it is not built; an operator sets the pack count today. PTW top-ups ARE self-service, because that is the one a field team hits mid-shift |
| Public top-up pack prices on `/pricing` | The cards state that extra documents can be bought and do not expire, and the FAQ gives the entry price. A three-row pack table on the pricing page was judged to be the "wall of billing terminology" the direction warns against. The full table is in-product on the quota page |

- **Verification performed:** See `05 - QA & Verification.md`. 733 tests / 3913 assertions passing, zero failures, up from 692. Clean build, ESLint 0 errors. Both migrations applied, rolled back and re-applied against MySQL. Public pricing verified in a real browser at 1440 and 375.
- **Issues discovered:** None outstanding.
- **Commit hash:** `565ab8f`, pushed to origin/main.
- **Next action:** None blocking. The deferred items above are the natural follow-ups.

## 2026-10-01 - v2.87.0: visual storytelling pass

- **Phase:** Visual redesign, complete and verified. The product, pricing and entitlement model is unchanged; this pass is composition, atmosphere, motion and rhythm.
- **Method:** Ran the local `taste-skill` per the Master Brief's own instruction to use it when implementation begins, in its **Redesign Protocol (section 11)** mode rather than as a greenfield generator. Its defaults assume Next.js, Motion and image-generation tooling, none of which apply here, so the skill's own preservation rules governed: audit first, extract existing tokens, evolve rather than replace.

**Design read:** redesign-preserve of a B2B industrial SaaS landing for Indonesian industrial buyers, calm technical-operational language, on the existing IOMS navy/steel system.
**Dials:** DESIGN_VARIANCE 7, MOTION_INTENSITY 5, VISUAL_DENSITY 4 (redesign-preserve = match existing, motion +1).

### Audit findings, measured rather than asserted

| Finding | Measurement |
|---|---|
| Eyebrow saturation | 23 uppercase tracking labels across a 12-section page. The budget is one per three sections, so 4. Every section opened with the same LABEL / Headline / body rhythm, which is the most reliable signature of a generated marketing page |
| Zigzag repetition | The platform section rendered SIX consecutive left-panel / right-text rows. The cap is two; the third is already a failure. This was the flat middle of the page |
| Tonal monotony | Sections 5 through 10 were six consecutive light bands. The page had exactly three dark surfaces, all of them at the top or the very bottom |
| Hero had no subject | Text on the left, a workspace list on the right, and a diagram below. Nothing on the page was an object |
| Product UI dissolved | The platform showcase is a white interface rendered on a white section, so the strongest proof on the page read as more page furniture |
| Pricing off-balance | The grid asked for four columns at xl while the catalogue sells three, leaving an empty cell. See PAGE-002 |

### Work completed

**The hero has a subject: a Permit To Work.** New `PermitArtifact`, rendered in the document format `Pages/PermitsToWork/Document.jsx` actually produces: the same mono reference, the same field grid, the same approval seal with a named approver and a real role. It is cropped by the right edge and tilted two degrees, so it reads as a sheet on a desk rather than as a picture of one.

The choice is deliberate and is the core of this pass. A dashboard screenshot is what every B2B SaaS hero uses and it means nothing to a yard superintendent; a work permit is the artifact this industry already organises its day around. It is also the honest option: IOMS genuinely generates this document, so nothing here claims a screen that does not exist.

**The hero fits one viewport and carries four text elements.** It was five, including an industries strip at the bottom, which is a documented decoration tell. The workspace list and the industries moved into the band below, which was previously a single centred sentence and is now doing real work. The headline was three lines at 64px in a column too narrow for it; it is two lines at 48px in a wider column.

**The zigzag is capped at two.** The first two domains keep the story layout because they carry the argument. The remaining four became a two-column brief with capability chips: a different layout family, and the right density for content a reader is skimming rather than studying. **No content changed** in `domainStories.js`, so every claim still maps to a real menu item and the product-truth tests still pass.

**Eyebrows cut from 23 to 4.** `SectionHeading` now takes an optional eyebrow, and the label survives only where a genuinely new chapter opens: the hero, Platform, My Work, Pricing. `StorySection` was printing its eyebrow twice, once beside the visual and once above the heading; the duplicate is gone.

**Two dark objects in the light middle.** The connected-operations diagram moved out of the hero and into the platform section, inset on a navy panel, which is both where its argument belongs and a dark surface in the middle of six light ones. The product showcase sits on a tinted ground with a real shadow, so it reads as a screen being shown rather than as part of the page.

**Motion, all of it motivated.** The permit settles once and the approval seal lands a beat after it, because that is the order the events happen in. The new grids stagger at 60 and 70ms, so a list arrives in reading order. Nothing loops. Everything is `motion-safe:` gated with `both` fill, so under reduced motion the classes are stripped and every element renders at full opacity. `Reveal`'s own note argues for ONE gesture rather than a vocabulary of effects, and that principle was respected rather than overridden: the staggers are the same gesture, sequenced.

**Pricing composition.** See PAGE-002. Three columns for three plans, centred and capped, cards stretched to a shared height, and the head block reserved so all three prices sit on one baseline. The recommended card keeps its lift, which is why its price sits 9px higher by design.

### Issues discovered and fixed

| ID | Issue | Severity | Status |
|---|---|---|---|
| PAGE-001 | `PackageSeeder` seeded Enterprise with `is_public => true` while the pricing migration sets it to `false`. Migrations run BEFORE seeders, so on any fresh install or re-seed **Enterprise reappeared on the public pricing page at Rp2.499.000**, a plan nobody can buy advertised beside the three that can be | **High, product truth** | FIXED. The row stays, because tenants are still subscribed to it; only its public visibility changed |
| PAGE-002 | The pricing grid declared `xl:grid-cols-4`, written when the catalogue had four tiers. Enterprise was retired from sale in v2.82.0 and the grid was never narrowed, so at wide viewports it laid out four columns for three cards and left an empty cell. This is why the section read as left-weighted: the composition was balanced around a card that no longer exists | Medium | FIXED |
| PAGE-003 | Hero headline ran to three lines at desktop | Low | FIXED |
| PAGE-004 | An unused `rule-draw` keyframe was added during this pass and then not used | Low | REMOVED rather than left as dead code |

**PAGE-001 was found by a test written during this pass**, not by looking. The new pricing-grid test compares the declared column count against the number of public plans, reported four plans where the catalogue sells three, and the seeder was the reason.

**A mistake worth recording.** The first attempt at the PAGE-001 fix used a string replacement whose marker matched Starter's entry before Enterprise's, so it silently set **Starter** private instead. `ReviewerJourneyTest` failed on the next full run and named it. The lesson is the ordinary one: a first-match string replacement across a file with repeated shapes needs an anchor unique to the target, and the suite is what catches it when it does not have one.

### Two tests added

- `the pricing grid has a column for every public plan`, which is what found PAGE-001 and would have caught PAGE-002 five releases ago. Scoped to the plan-card grid, because the page has other grids including a twelve-column one in the hero.
- `section eyebrows stay within budget`, one per three sections, counted from source so it runs without a browser.

### Deliberately not done

| Item | Reason |
|---|---|
| Photography and environmental imagery | There is no image-generation tool in this environment, and the brief plus `PublicReadinessTest` both rule out stock photography. `StorySection` already accepts `image={{ src, alt }}` and passes it through from `domainStories.js`, so adding real photographs is one field per story with no component change. **This is the one gap that needs the owner: the site would benefit from real photographs of the customer's own operations, and nothing else can supply them honestly.** Slots listed below |
| A scroll-pinned or horizontal-pan section | The skill offers both. Neither earns its place here: this audience evaluates operational software on a laptop in daylight, and scroll hijack on a page a buyer is scanning for a price is hostile |
| Dark mode for the public site | The page is theme-locked light with navy bands, which is the existing brand expression. Adding a second mode is a separate decision, not a visual-polish one |
| Reducing total page height | The page is about 10,250px. Every section on it earns its place and the content is verified; cutting sections is a content decision for the owner |

### Image slots, if real photography becomes available

Each takes `{ src, alt }` on its `domainStories.js` entry and needs no code change: `industrial-operations`, `hse`, `people`, `field`, `warehouse-logistics`, `management`. Landscape, roughly 4:3, real operations rather than stock.

- **Files changed:** `resources/js/Components/public/PermitArtifact.jsx` (new), `Pages/Public/Welcome.jsx`, `Components/public/StorySection.jsx`, `tailwind.config.js`, `database/seeders/PackageSeeder.php`, `tests/Feature/LandingPositioningTest.php`.
- **Verification performed:** See `05 - QA & Verification.md`. 735 tests / 3920 assertions, 0 failures. Clean build, ESLint 0 errors. Desktop 1440 and mobile 375 exercised in a real browser.
- **Commit hash:** `4951de5`, pushed to origin/main.
- **Next action:** Owner decision on photography. Nothing else is blocking.

## 2026-10-01 - v2.88.0: photography, and the favicon loses its square

- **Phase:** Visual completion. The gap v2.87.0 closed its own log with ("there is no photography, and it needs the owner") is closed.
- **Scope:** Presentation and brand assets only. No backend, auth, billing, entitlement, PTW, user-class, workspace-authorization or pricing change.

### The photography, inspected before placing

Eight photographs arrived. Each was opened and judged individually rather than wired into whatever slot existed:

| Asset | Subject | Decision |
|---|---|---|
| `Operational` | A supervisor on a dock at dusk, holding a tablet | **Hero.** It is the person IOMS is for, doing the thing IOMS is |
| `Shipyard` | Aerial shipyard at sunrise, enormous scale | **Industries tile + the closing band.** The widest, most atmospheric frame in the set |
| `Construction` | Aerial construction site, tower cranes | **Industries tile** and the Field Operations story |
| `Mining` | Open pit mine at sunset | **Industries tile** |
| `Energy` | Two technicians on a refinery walkway | **HSE story.** People doing permit-governed work |
| `Workshop` | Fabrication shop, overhead crane, welding | **People / Workforce story** |
| `Warehouse` | Warehouse aisle, racked pallets, forklift | **Warehouse Logistics story** |
| `Management` | Boardroom over a refinery, IOMS on the wall screen | **Management story** |

**One was deliberately left unused in the stories.** `operations` describes the platform itself, and a photograph of a place would be decoration rather than evidence there, so it keeps the product panel.

### How each photograph is used, and why the treatments differ

**The hero photograph is a LAYER, not a background.** A photograph stretched behind a whole hero with text over it is the stock-photo hero every template ships, and it costs the copy its contrast. This one occupies the right of the navy field and bleeds off the edge, with a gradient dissolving its left side into the text column. The permit artifact sits ON it, so the document and the place it came from are in one frame. Hidden below `lg`, where there is no room for a two-column composition.

**The closing band is the opposite treatment, deliberately.** Full-bleed and heavily scrimmed, because there the photograph is a ground for the call to action rather than the subject. The page used to end on a flat navy block, which read as running out rather than arriving somewhere.

**Industries stopped being seven pills.** A centred row of seven bordered pills is the most generic thing a page can do with a list and said nothing a reader could picture. It is now three establishing shots, which is the one job a photograph does better than any layout. The remaining four sectors are a text line beneath, because there are only three photographs and inventing a fourth tile would mean repeating an image or dropping a sector IOMS serves.

### Performance: the photographs could not ship as delivered

The eight PNGs were **14.6 MB**. PNG is lossless and built for flat-colour graphics; for a photograph it stores an enormous amount of data no viewer can see. Shipping them would have made the landing page heavier than every other asset on it combined.

`scripts/build-website-images.mjs` (`npm run images`) now generates WebP at 640/1024/1600 plus one JPEG fallback, **never upscaling**: three of the sources are only 787px wide and simply do not get the larger derivatives. Originals moved to `public/images/website/source/`.

**14.29 MB of sources produce 2.45 MB of derivatives**, and any one page loads a handful of those. The landing page's largest photograph request on a 1440 desktop is 121 KB.

`Photo.jsx` is the one way a photograph reaches the public site: srcset, `sizes`, WebP source, JPEG fallback, reserved aspect box, lazy by default with `priority` for the hero. `sizes` is set per call site, which is what actually decides whether a phone downloads the 640 or the 1600.

### The favicon

**The supplied file is not vector.** `ioms-favicon-transparent.svg` is a 4096x4096 PNG embedded as base64 inside an SVG wrapper, 679 KB, and the mark is not centred: trimmed it occupies 2246x1991 of the 4096 square.

So the two formats come from two places, deliberately:

- **Rasters** (`favicon.ico`, 32, 48, 96) are rasterised from the supplied file, because it is what the brand owner designated. Trimmed and re-centred first, so the mark sits square with even padding at 16px.
- **The SVG favicon** is the repository's own `ioms-icon.svg`: real vector, 1.8 KB, the same mark, the same colour `#01c1ed`, already transparent with no background rect. Serving the supplied file as a tab icon would cost roughly **three hundred times** the vector.

Both are the same official mark in the same official colour. Nothing was redrawn.

`favicon.ico` is written by hand as a 3-entry container (16/32/48) with PNG payloads, which ICO has carried since Vista. That avoids a dependency whose only job is forty bytes of header.

**What was preserved, explicitly:** the wordmark and both logo variants, the dark logo, the email logo, the OG image, and every PWA icon. The apple-touch-icon and the maskable PWA icons stay **opaque**, because iOS composites a home-screen icon onto its own surface and renders transparency as black, and a maskable icon is cropped to a platform shape that assumes a filled canvas. A transparent touch icon would put a black tile on every iPhone that saved the site.

### Issues discovered

| ID | Issue | Severity | Status |
|---|---|---|---|
| IMG-001 | Photography delivered as 14.6 MB of PNG | High, performance | FIXED. 2.45 MB of WebP/JPEG derivatives, generated by a committed script |
| IMG-002 | The supplied favicon "SVG" is a 679 KB embedded raster, and the mark is off-centre inside its canvas | Medium | FIXED. Rasters trimmed and re-centred from it; the SVG favicon points at real vector |
| IMG-003 | `config/branding.php` declared `favicon_ico => /favicon.ico`, and the comment claimed it had been a 404 until v2.76.0. The file **did** exist, so this was not a live defect, but the .ico was the old navy-square art | Low | Regenerated transparent |

### Decisions

- **A dev dependency was added**: `sharp`, dev-only, for the two generator scripts. The alternative was shipping 14.6 MB or hand-waving the optimisation. The derivatives are committed, like `public/build`, because the deployment target serves files rather than running a pipeline.
- **Story image contract changed** from `{ src, alt }` to `{ name, alt }`. A call site that knows about `-640.webp` is a call site that breaks when the build script changes; the component owns which files exist at which widths.
- **The v2.76.0 opaque-favicon decision is reversed, not deleted.** Its reasoning (Google renders favicons on a light surface, where a transparent mark has less to hold onto) is kept in `config/branding.php` beside the new answer, because it was a trade rather than a mistake.

### Deferred

| Item | Reason |
|---|---|
| Photography on the sub-pages (`/platform-overview`, `/solutions`, `/how-it-works`) | The brief asked for the landing page, and the set is eight images. Spreading them thinner would weaken the landing page without making a sub-page strong. The `Photo` component and derivatives are ready if that is wanted |
| An AVIF derivative tier | WebP already gives a 6x reduction and is universally supported. AVIF would add a third encode and a third set of files for a marginal further gain |
| A blur-up placeholder | The reserved aspect box already prevents layout shift, which is the defect that actually matters. A blur placeholder is polish on top of a solved problem |

- **Verification performed:** See `05 - QA & Verification.md`. 738 tests / 3931 assertions, 0 failures. Clean build, ESLint 0 errors.
- **Commit hash:** `f4f547f`, pushed to origin/main.
- **Next action:** None blocking.

## 2026-10-01 - v2.89.0: two ends of one operation

- **Coverage:** Public company identity; My Work entry experience; hero story; domain imagery and
  rhythm; the trust/security coverage left open in Phase 07.
- **Identity decision:** `config('ioms.company')` is the public brand **Tahada Group**. The legal
  entity used by legal pages and structured data defaults to **PT Tahada Vistara Bersama**. IOMS
  remains the Organization name in structured data. No global replacement was made; technical and
  internal YSR references remain intact.
- **My Work decision:** keep one authentication system. The login page gives field users a direct
  `/my-work` entry. That route still authenticates first; `user_type` decides the My Work landing
  for My Work Users while the existing field preference continues to apply to Full Users.
- **Hero decision:** retain the PTW artifact. It bridges the management and operations photographs
  as the real record that connects those settings. On mobile it follows the photographs instead of
  being squeezed across two short frames.
- **Domain layout decision:** use a workshop figure, a text-led field handover, a full-width
  warehouse scene and a management image with its copy. Each has a different composition rather
  than turning every asset into an image card.
- **Trust claims:** placed after pricing; wording names only repository-enforced behavior. No
  certification or unverified service-level claim was added.
- **Files changed:** `config/ioms.php`, `resources/views/app.blade.php`,
  `resources/js/Components/shared/AboutDialog.jsx`, `resources/js/Layouts/AuthLayout.jsx`,
  `resources/js/Pages/Auth/Login.jsx`, `resources/js/Pages/Public/Welcome.jsx`,
  `resources/js/Components/public/DomainEditorial.jsx`,
  `resources/js/Components/public/HeroComposition.jsx`, `tailwind.config.js`,
  `app/Models/User.php`, `tests/Feature/LandingPositioningTest.php`,
  `tests/Feature/PublicSearchIdentityTest.php`, `tests/Feature/UserClassAndPtwQuotaTest.php`,
  and generated `public/build` assets.
- **Verification:** 741 tests / 3956 assertions passed. `npm run build` completed; known large-chunk
  advisory remains. ESLint: 0 errors, 4 existing warnings in untouched files. Local Chromium preview
  checked the sign-in entry and story imagery at the available narrow viewport. See
  `05 - QA & Verification.md` for what was not measured.
- **Commit hashes:** `0827cfe` (implementation) and `cfd7096` (documentation closeout); both pushed to `origin/main`.
- **Next action:** none blocking. A 1440px desktop browser review remains unverified and is recorded in `05 - QA & Verification.md`.


## 2026-10-01 - v2.89.0 addendum: the desktop review that was still outstanding

The entry above closed with one thing unverified: *"A 1440px desktop browser review remains
unverified."* This is that review, and what it found.

### The hero composition holds, and one width did not

Checked at 1440, 1280, 1024 and 375.

**Found at 1024:** the hero's right bleed clipped both frame labels, `OFFICE / MANAGEMENT` and
`FIELD / OPERATIONS`. The cause is a width the composition had not been reasoned about rather than a
styling mistake: `max-w-7xl` has side margins at 1440, so pulling the column 96px right stays inside
the viewport, and at 1024 the container fills the screen and the same 96px goes off the edge.

Fixed by making the bleed breakpoint-aware. Both labels now sit at 992px of 1024, fully visible, and
the wider bleed is kept for `xl` where there is margin to spend. Verified at every width above.

Everything else held: no horizontal overflow at any width, a two-line headline at all of them, the
hero inside the viewport at 1440 (832px of 900) and at 1024 (690px of 860), and the mobile sequence
reading office, field, then the record.

**One crop decision confirmed rather than assumed.** The mobile and desktop crops of the field
photograph are set independently because they want different subjects: at 375 the supervisor is the
frame, and at 1440 the dock and cranes are.

### Sub-page photography, previously deferred

v2.88.0 deferred this on the grounds that spreading eight images thinner would weaken the landing
page. That reasoning held for the landing page's own sections and not for the sub-page headers, which
had no imagery at all and opened on the same navy rectangle, so the site lost its atmosphere the
moment a visitor left the landing page.

`PublicPageHero` gained an optional photograph: behind the whole band at low contrast, deliberately
quieter than the landing hero, because a sub-page header should not compete with the page it is
subordinate to. `/platform-overview` takes the shipyard, `/solutions` the mine, `/how-it-works` the
workshop. It is opt-in, so transactional headers such as an order or a payment status keep the plain
band; atmosphere is not what somebody checking a payment needs.

Still deferred, and now for a stated reason rather than by omission: `/pricing`, `/faq` and
`/contact` are decision and reference pages, and a photograph behind a price comparison competes with
the comparison.

### Two findings worth keeping, beyond the fixes

**The audit boundary was wrong, not the audits.** The sign-in page had advertised two retired
workspaces since v2.84.0. Every copy audit missed it because they scoped themselves to
`Pages/Public`, and the auth shell lives under `guest`. A prospect reaches sign-in from the marketing
site, so its claims are public claims. The lesson is about where the boundary is drawn, and
`03 - Website IA & Content.md` now records it.

**A config file cannot read config.** The first attempt at defaulting `legal.entity_name` used
`config('ioms.legal_entity')`, which silently resolved to null, because a config file is loaded
before the config repository exists. It was caught by checking the resolved value rather than by
assuming the edit worked. The default is a literal now, with that reason stated beside it.

- **Verification performed:** 741 tests / 3956 assertions, 0 failures. Clean build, ESLint 0 errors.
  Multi-width desktop and mobile review completed in Chromium. Zero em dashes across twelve public
  pages including `/login`, in both encodings.
- **Next action:** none blocking.


## 2026-10-01 — v2.90.0: one transparent source across every app icon

This is an owner-directed correction to the icon scope inside the completed website redesign. It
does not reopen the photography, My Work entry, company identity or editorial-story work from
v2.88–2.89.0.

### Discovery

The v2.88.0 pass removed the ground from browser favicons but deliberately retained separate opaque
assets for Apple and PWA installation. That left one brand symbol appearing with two different
background treatments depending on how the visitor saved or installed the site. The designated
source, `public/branding/ioms-favicon-transparent.svg`, is a 4096×4096 PNG embedded in an SVG wrapper
(679 KB); its visible mark is 2246×1991 and is offset inside the source canvas. The source file is
preserved byte-for-byte.

### Decisions

- All browser formats (`favicon.ico`, SVG, PNG), the Apple touch icon and both PWA purposes are now
  generated from that single transparent source. Empty canvas is trimmed and the mark is centred in
  required square rasters; no ground, mark path or colour is added or redrawn.
- The served SVG favicon is a compact SVG wrapper around a 512×454 transparent derivative. It uses
  the official pixels in a tight viewBox rather than serving the 679 KB source canvas or switching
  back to a second vector master.
- `any` and `maskable` remain distinct manifest purposes. The maskable mark uses a 76% scale; its
  furthest source pixel reaches about 0.389 of the icon size, inside the W3C safe-circle radius of
  0.4. Its remaining pixels are transparent. The [Manifest specification](https://www.w3.org/TR/appmanifest/#icon-masks-and-safe-zone)
  says user agents composite transparent pixels onto a solid fill of their choice. The [Safari 17.2
  release notes](https://developer.apple.com/documentation/safari-release-notes/safari-17_2-release-notes?language=_5)
  document spacing adjustment for transparent custom-shaped icons. Neither requires IOMS to bake a
  background colour into these files.
- The existing vector at `ioms-icon.svg` remains the ordinary product mark used by watermarks and
  controlled documents. Those surfaces are not favicon or install-icon references and stay as they
  are.
- The manifest full name now uses the exact form `IOMS - Industrial Operations Platform`; its
  `short_name` stays `IOMS`.

### Implementation and scope

`scripts/build-favicons.mjs` now generates the complete icon family. `config/branding.php` remains
the path registry; the page head adds the Apple 180×180 dimensions; `WebAppManifestController`
supplies the full name. `BrandIconsTest` covers alpha on every raster, maskable safe-zone bounds,
the cropped SVG, ICO transparency and references. `PwaInstallabilityTest` pins the manifest name.

No authentication, product claims, pricing, entitlement, workspace, billing, PTW behaviour, public
company identity, lockup art, PDF identity or cPanel configuration changed.

- **Verification:** full suite 741 tests / 3978 assertions; production build succeeded in an isolated
  output directory with the existing large-chunk advisory; ESLint 0 errors and 4 existing warnings.
  Generated icons were visually reviewed on light and dark surfaces. A real browser tab and Apple
  device were not exercised.
- **Next action:** none blocking.

## 2026-10-01 - v2.91.0: the hero becomes one photograph with the platform running on it

- **Scope:** The hero only. No other section, no branding, no product logic.
- **Direction:** The previous hero was not matching the intended visual direction. It was revised rather than redesigned: the copy, its hierarchy and the CTA pair are unchanged.

### What was there, and why it was wrong

Through v2.89.0 the hero was **a navy field with framed photographs sitting on it**. Each environment had its own bordered frame, a hairline ran between them, and navy showed around and between. The frames and the navy were the composition; the photography was an inset. That is the diptych-of-cards reading the direction rules out.

### What it is now

**One panoramic scene filling the section.** Management occupies the left, field the right, and they meet through a feathered overlap rather than an edge:

- **The right photograph is masked, not butted up.** Its left edge fades out over roughly a third of its width with a mask gradient, so the office dissolves into the dock. There is no divider, no border and no gap for a background to show through, because the two images physically overlap in the blend zone.
- **The seam crosses two quiet regions.** The boardroom's right side is dark wall; the dock's left is hull and shadow. Feathering between two busy areas is what makes a composite look like a composite.
- **One grade over both.** A single navy wash and one readability gradient sit above both photographs rather than per image. Per-image scrims were a large part of why the old version read as two separate pictures.

**Navy is no longer the hero's field.** It survives only as the grade that keeps the copy legible.

### The PTW card is gone, replaced by the platform running

`PermitArtifact` is removed from the hero and **the component is deleted**, because nothing referenced it afterwards and this repository does not keep dead code. Git history holds it.

In its place, `HeroDashboard`: a translucent IOMS interface floating across the blend, cycling slowly through workspaces.

- **It reads the same data as the showcase.** `MODULES` is now exported from `PlatformShowcase` and shared, so the two product previews on this page cannot drift into describing two different products.
- **Subdued by construction.** Low-opacity navy glass with a hairline edge and a backdrop blur, not a white card. An opaque panel over a photograph is a screenshot with a drop shadow.
- **It sits across the join**, which is the one place on the page where an interface genuinely connects an office to a dock.
- **Motion:** one module every five seconds, a slow cross-fade, the rail's active item moving with the content. Nothing slides, flashes or loops faster than reading speed.

### One product-truth correction to the brief

The requested cycle named **Dashboard, Permit to Work, HSE, Projects, Warehouse, Dashboard**. Two of those cannot be advertised:

- **Projects** was retired from the customer-facing product in v2.84.0. Putting it in a loop on the landing page would promise a workspace no plan opens.
- **Permit To Work** is a capability inside HSE rather than a workspace, so it has no rail entry of its own.

The loop runs the five real workspaces instead: **Dashboard, HSE, People / HRD, Warehouse Logistics, Management**. The brief described the sequence as an example, and this is the truthful version of it.

### Accessibility

The panel is `aria-hidden`. It is decorative narrative, not a control, and a screen reader user gains nothing from a silent carousel of figures they cannot act on. The same numbers are read properly in the showcase further down.

Under `prefers-reduced-motion` the cycle **does not start at all** and the panel holds its first state. An element that changes by itself is precisely what that preference asks not to see, so pausing it is not enough.

### Responsive

Mobile keeps both worlds rather than cropping one away: below `md` the scene stacks vertically, management above and field below, blended through the same feather rotated ninety degrees. The dashboard is hidden there, because at phone width it would either cover the photography it is supposed to float over or shrink past being readable.

Two headline wraps were found and fixed during the width pass, at 1024 and at 1440, each caused by the copy column narrowing faster than the type scale.

### Issues found and fixed during this pass

| ID | Issue | Status |
|---|---|---|
| HERO-002 | Headline ran to three lines at 1440 in the narrower copy column | FIXED, column widened to six of twelve |
| HERO-003 | Headline ran to three lines again at 1024 | FIXED, separate type step at `lg` |
| HERO-004 | The first grade was heavy enough that the boardroom read as a dark blue field rather than an office | FIXED, wash and left scrim both reduced |
| HERO-005 | `PermitArtifact` left orphaned by the replacement | FIXED, component deleted |

### Deferred

| Item | Reason |
|---|---|
| The dashboard on mobile | It would cover the photography or become unreadable. The mobile hero is the scene and the copy |
| Pausing the cycle on hover or focus | The panel is not interactive and takes no focus, so there is nothing to pause for. Reduced motion already covers the case that matters |

- **Verification performed:** See `05 - QA & Verification.md`. 741 tests / 3978 assertions, 0 failures. Clean build, ESLint 0 errors.
- **Commit hash:** recorded on commit.
- **Next action:** none blocking.

## 2026-10-01 - v2.92.0: the hero story connects field activity to management

- **Coverage:** Owner-directed hero copy and mobile image treatment only.
- **Copy:** The hero body now uses the four supplied paragraphs verbatim. The existing descriptor,
  headline and CTA pair remain in place; no other public-page copy changed.
- **Mobile:** The long body copy uses a smaller type size below the desktop breakpoint. The mobile
  background uses the management photograph alone. The field photograph remains in the desktop
  composition.
- **Desktop:** Its type size and styling, photography, dashboard, spacing, CTA and navigation are
  unchanged. No other website section or page changed. No product, pricing, workspace, authentication,
  billing, entitlement or PTW behavior changed.
- **Verification:** Full PHPUnit suite: 742 tests / 3986 assertions, 0 failures. Production build
  succeeded in an isolated output directory with the existing 2,031 KB chunk advisory. ESLint:
  0 errors, 4 existing unused-disable warnings. No browser visual review was performed. Details in
  `05 - QA & Verification.md` and [[Verification Status]].
- **Commit hash:** recorded after the release commit.
- **Next action:** Continue the remaining redesign coverage from the project board.
