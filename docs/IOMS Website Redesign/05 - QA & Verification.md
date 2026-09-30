---
title: IOMS Website Redesign - QA and Verification
tags: [ioms, website-redesign, qa]
updated: 2026-09-30
status: v2.85.0-verified; new-scope-pending
---

# QA & Verification

Record date, environment, steps, expected/actual result, evidence, and issue reference for each check. Do not mark a check passed without evidence.

## Functional checks

- [ ] Public navigation, links, forms, and CTAs.
- [ ] Existing registration entry point still works.
- [ ] Email verification and login behavior unchanged.
- [ ] Pre-payment dashboard access remains unavailable.
- [ ] Existing company/tenant onboarding and package selection still work.
- [ ] No protected route, action, API, or business rule changed.

## Pricing and entitlement consistency

- [ ] Public package names, prices, periods, and benefits match canonical product configuration.
- [ ] Included user limits match server-side entitlement enforcement.
- [ ] Alternate UI/API paths cannot bypass the stated user limit.
- [ ] Additional-user mechanism, if any, is explicit and enforced.
- [ ] Workspace availability matches actual plan entitlements.
- [ ] No outdated pricing or user-limit claim remains on public pages.

## Purchase and renewal flows

- [ ] Account creation, email verification, login, company details, package selection, payment, and activation sequence preserved.
- [ ] Payment amount and selected package match the verified public plan.
- [ ] Existing renewal/perpanjangan path and subscription lifecycle remain unchanged.
- [ ] Document routes/actions and evidence without exposing sensitive customer data.

## Responsive, browser, and visual checks

- [ ] Small mobile, large mobile, tablet, laptop, and wide desktop layouts.
- [ ] Current supported browsers (record names and versions).
- [ ] Typography, spacing, contrast, focus, hover, and active states.
- [ ] Product UI and imagery render correctly; no dashboard-only storytelling.
- [ ] No clipping, layout shift, broken assets, or obstructive sticky elements.

## Motion checks

- [ ] Motion is purposeful and smooth on representative devices.
- [ ] Reduced-motion preference is respected.
- [ ] Motion does not block reading, navigation, or interaction.
- [ ] No decorative/meaningless animation.

## SEO and metadata checks

- [ ] Page title and description are accurate.
- [ ] Canonical URLs, robots directives, sitemap, and headings are correct.
- [ ] Open Graph/social preview metadata and imagery are correct.
- [ ] Structured data, if used, contains only verified facts.

## Copy and policy checks

- [ ] Only HSE, People / HRD, Warehouse Logistics, and Management are presented as operational workspaces.
- [ ] Admin Space is described only as tenant administration.
- [ ] Global Dashboard is Business only.
- [ ] No unsupported feature, price, metric, customer, security, or industry claim.
- [ ] Website copy contains no em dash character.

## Known issues

| ID | Issue | Severity | Evidence | Owner/status |
|---|---|---|---|---|
| QA-001 | Product repository not yet audited; product, pricing, and entitlement claims are unverified. | Blocking pricing publication | No application repository was available at requested project path during documentation setup. | Pending Phase 01 |

## Verification log

| Date | Phase | Check | Result/evidence | Issue |
|---|---|---|---|---|
| 2026-09-29 | Documentation setup | Six requested Markdown notes exist at the requested path | Verified by directory listing after creation | None |

---

# QA execution, 2026-09-30

Environment: Windows 11, PHP 8.3.30, Node 24.19.0, MySQL 8.4.3, Laravel dev server on 127.0.0.1:8000, Chromium browser pane at 1440x900 and 375x812. Test suite runs on in-memory SQLite per `phpunit.xml`.

This is the first time anything in this project's history has been verified by actually running it. Earlier releases were reasoned through statically, as `CLAUDE.md` records. Every result below was observed, not inferred.

## Automated

| Check | Command | Result |
|---|---|---|
| Full test suite | `php artisan test` | **692 passed, 3813 assertions, 0 failures** (690 with 2 failures mid-work; both were tests pinning pre-redesign copy, see WEB-012) |
| Language hierarchy, public readiness, search identity | filtered run | 41 passed, 672 assertions |
| Pricing, entitlement, add-ons, lifecycle | filtered run | 68 passed, 356 assertions |
| Production build | `npm run build` | Clean. 2302 modules. CSS 113.19 kB / 17.20 kB gzip, JS 2,010 kB / 494 kB gzip |
| Lint | `npm run lint` | 0 errors, 4 warnings, all pre-existing in files this work did not touch |
| Migration | up, then rollback, then up | Applied, reversed and re-applied against MySQL |

**The entitlement publication gate is satisfied.** The test named "the active user allowance is enforced server side" passes in `UserEntitlementAndAddOnTest`, which is the evidence required by `02 - Product & Pricing Truth.md` before a user limit may be published. "a customer cannot set their own price" and "capacity cannot be released below the accounts in use" also pass.

## Functional

| Check | Result |
|---|---|
| Every public route returns 200 | `/` `/pricing` `/platform-overview` `/solutions` `/how-it-works` `/faq` `/sandbox` `/contact` `/privacy` `/terms` `/refund-policy` `/robots.txt` `/sitemap.xml` all 200 |
| `/get-started` | 302 by design, redirects to `/register` per the v2.74.2 decision. Unchanged |
| Console errors | None on any public page |
| Registration, verification, login, onboarding, checkout, webhooks | Not modified. `PublicSaasJourneyTest` passes, including that reaching the status page cannot activate a subscription |
| Renewal and subscription lifecycle | Not modified. `SubscriptionLifecycleTest` passes, including "the lifecycle states still behave as before" |

## Pricing and entitlement consistency

Rendered values observed in the browser against `tests/Support/ApprovedCatalogue.php`:

| Plan | Monthly | Annual | Included users | Rendered scope |
|---|---|---|---|---|
| Starter | Rp189.000 | Rp2.268.000 | 3 | HSE |
| Professional | Rp555.000 | Rp6.105.000 | 10 | plus People / HRD |
| Business | Rp1.249.000 | Rp14.988.000 | 25 | plus Warehouse Logistics and Management |

Additional user Rp50.000 renders from `PricingService`, not from copy. Exactly three public plans are served. Business carries the recommended flag. Annual terms render correctly per tier, including Business's fourteen months for twelve payments, which is extra service rather than a discount.

## Copy and policy

| Check | Method | Result |
|---|---|---|
| Em dash absent from public copy | Fetched all 11 public pages, searched the literal character **and** the JSON escape sequence | **0 across all 11 pages** |
| Em dash regression guard | New test "no public page contains an em dash" | Passing. Asserts against the rendered response, so database-sourced copy is covered |
| Retired workspaces absent from visible copy | Browser innerText of the rendered DOM | Clean on every page |
| Four operational workspaces only | Hero, diagram, domain sections, plan cards | HSE, People / HRD, Warehouse Logistics, Management only |
| Admin Space not marketed | Full-site search | Not present as an operational workspace anywhere |
| Global Dashboard is Business only | FAQ and plan cards | Stated only against Business |
| No invented claims | Reviewed every capability against routes and models | PTW Access, My Work, Operating Units, PPE, material requests, Report Center all verified to exist |

**A verification error worth recording.** The first em dash sweep reported zero and was wrong. It searched only the literal character, and Inertia serializes props as JSON where the character appears as an escape sequence. Four occurrences in the `packages` table survived undetected. The corrected sweep searches both forms, and the new automated test does the same, which is why it asserts against the rendered response rather than against source files.

## Responsive and visual

| Width | Result |
|---|---|
| 375x812 mobile | No horizontal overflow, scrollWidth equals clientWidth equals 375, on landing and pricing. Headline scales, workspace list stacks, plan cards single-column |
| 1440x900 desktop | Hero asymmetry holds, five-node diagram spaces evenly, plan cards three-up |

Typography confirmed live via computed style: the h1 resolves to Archivo, instrument labels to IBM Plex Mono. Motion is unchanged from the existing `Reveal` and `motion-safe:` implementation, which already honours reduced motion.

## SEO and metadata

Titles and descriptions rewritten in `config/seo.php`: em dashes removed and retired workspaces dropped from the home and platform descriptions. `PublicSearchIdentityTest` passes, covering canonical URLs, the sitemap listing exactly the public pages, noindex on private routes, Indonesian language tagging on legal documents, and that the product name is never expanded. Structured data carries lowPrice 189000 and highPrice 1249000 with offerCount 3, matching the catalogue.

## Performance

Landing page HTML fell from roughly 370 KB to 103 KB, a reduction of about 72%, by scoping `version.history` and `workspace_catalog` to authenticated users. The JS bundle is unchanged at 494 kB gzip and remains above Vite's chunk-size warning threshold; code splitting was not attempted, as it is an application-wide change rather than a website one.

## Known issues

| ID | Issue | Severity | Evidence | Owner/status |
|---|---|---|---|---|
| QA-001 | Product repository not yet audited | Blocking | Superseded | **CLOSED** 2026-09-30. Audit complete, every figure traced to source |
| QA-002 | Ziggy route manifest exposes internal route names including procurement routes on public pages | Low | Search of rendered source. Not visible copy | OPEN. See WEB-013. Deferred deliberately: filtering Ziggy changes the `route()` helper site-wide and fails silently if the allow-list is wrong |
| QA-003 | `config/ioms.php` names the software edition "Enterprise Edition" while Enterprise is also a retired plan slug | Low | Serialized in props, never rendered | OPEN, owner decision. See WEB-014 |
| QA-004 | JS bundle 494 kB gzip, above the chunk-size warning | Low | Build output | OPEN, pre-existing, application-wide |

## Not verified

Stated plainly rather than implied:

- **Payment execution end to end.** No live or sandbox transaction was run against either payment provider. The checkout path was not modified and its tests pass, but no money moved in this session.
- **Renewal over real elapsed time.** Lifecycle behaviour is covered by tests; no subscription was observed crossing a real period boundary.
- **Authenticated application surfaces.** `font-sans` and every authenticated component are untouched by design, and the suite passes, but the logged-in UI was not re-reviewed visually.
- **Cross-browser.** Chromium only. Not checked in Firefox or Safari.
- **Real device testing.** Mobile verified by viewport emulation, not on hardware.

## Verification log

| Date | Phase | Check | Result/evidence | Issue |
|---|---|---|---|---|
| 2026-09-29 | Doc setup | Six notes exist | Directory listing | None |
| 2026-09-30 | 01 | Pricing traced to source | ApprovedCatalogue, PackageSeeder and config/plans.php agree | None |
| 2026-09-30 | 01 | Public copy audited | WEB-001 to WEB-008 raised | 8 defects |
| 2026-09-30 | 02-09 | Defects fixed, redesign implemented | See 04 - Implementation Log.md | None |
| 2026-09-30 | 10 | Em dash sweep, first attempt | Reported 0, **incorrect**, missed JSON escapes | Method defect |
| 2026-09-30 | 10 | Em dash sweep, corrected | 0 literal and 0 escaped across 11 pages | None |
| 2026-09-30 | 10 | Full suite | 692 passed, 3813 assertions | None |
| 2026-09-30 | 10 | Build and lint | Clean, 0 errors | None |
| 2026-09-30 | 10 | Migration up, down, up | Succeeded against MySQL | None |
| 2026-09-30 | 11 | Browser, desktop and mobile | No overflow, no console errors, all routes 200 | None |

---

# Pending verification: metered PTW and the second user class

**Approved 2026-09-30. NOT IMPLEMENTED, therefore NOTHING BELOW HAS BEEN RUN.** This is the check
list the future implementation must satisfy, recorded now so it is not assembled from memory later.

## The publication gate applies in full

No figure from the new model may appear on the public website until its server-side enforcement
exists and a passing test demonstrates it. v2.85.0 exists because that gate was not applied to the
user allowance, and the site advertised capacities the billing layer did not enforce.

Specifically required before publication:

- [ ] Full User allowance enforced server-side, per class
- [ ] My Work User allowance enforced server-side, per class
- [ ] A My Work User genuinely cannot do what a Full User can, asserted by test, not by configuration
- [ ] PTW creation blocked at zero quota, at the controller **and** the form request
- [ ] Included quota expires at the period boundary
- [ ] Purchased quota does not expire
- [ ] Top-up quota credited only by the verified payment webhook
- [ ] Prices recomputed server-side; the browser sends a pack choice, never an amount

## Functional checks

- [ ] PTW creation decrements exactly one document, from the pool defined by **D-3**
- [ ] Concurrent creation cannot double-spend the last document, tested under contention
- [ ] At zero: My Work still opens, assigned work still workable, existing permits readable and
      exportable, only new creation blocked
- [ ] The block is an explanatory state with a route to upgrade or top up, not a bare 403
- [ ] Sandbox and demo seeding consume no real quota
- [ ] Deactivating an account frees capacity in the correct class
- [ ] Downgrade refused when it would strand active accounts **of either class**

## Boundary checks

- [ ] Period rollover: included resets, purchased survives
- [ ] Annual plans behave per the answer to **D-2**
- [ ] Business annual behaves per **D-4**
- [ ] Grace and lapsed behave per **D-5**
- [ ] Plan change carries purchased quota and pack quantities per **D-8**
- [ ] Cancellation behaves per **D-9**
- [ ] Existing tenants migrated per **D-7**, and no existing subscriber is left worse off unnoticed

## Billing checks

- [ ] Renewal total includes the recurring My Work pack charge beside the additional-user charge
- [ ] A one-off top-up invoice is not mistaken for a renewal by the lifecycle job
- [ ] A paid invoice is never rewritten by a later price change, the existing v2.82.0 guarantee
- [ ] Platform Admin override behaves like the existing `subscriptions.seat_limit` override

## Website checks, on the release that publishes it

- [ ] Every published figure matches `ApprovedCatalogue` and enforcement
- [ ] Three capacities per tier are legible without reconciliation
- [ ] Both add-ons named with correct prices and units
- [ ] Included versus purchased quota explained in plain terms
- [ ] Em dash sweep, literal **and** JSON-escaped, across all public pages
- [ ] Retired-concept sweep
- [ ] Desktop and mobile, no horizontal overflow
- [ ] The two FAQ answers that become false are corrected in the same release

## Known risk to verify against

The cheaper class is the one to watch. A My Work User at an effective Rp10.000 per month beside a
Full User at Rp50.000 means any gap in the restriction is a five-to-one arbitrage. The test that
matters most is not that a My Work User can reach My Work; it is that a My Work User **cannot** reach
what a Full User pays for, attempted by direct URL against every operational workspace. That is the
same method the v2.84.0 plan matrix suite already uses, and it is the right precedent here.

---

# QA execution, v2.86.0, 2026-09-30

Same environment as the v2.85.0 run. Everything below was executed.

## Automated

| Check | Result |
|---|---|
| Full suite | **733 passed, 3913 assertions, 0 failures** (692 before this work) |
| New suite `UserClassAndPtwQuotaTest` | 40 passed, 136 assertions |
| Public site suites | 48 passed, 759 assertions, including the em dash guard |
| Production build | Clean |
| ESLint | 0 errors, 4 pre-existing warnings in untouched files |
| Migrations | Both applied, rolled back and re-applied against MySQL |

## The publication gate, satisfied

Every figure now published is enforced and asserted:

- [x] Full User allowance per plan, counted and enforced separately
- [x] My Work allowance per plan, counted and enforced separately
- [x] A My Work User cannot reach what a Full User pays for, attempted by DIRECT URL against eight operational and administrative routes, with the strongest tenant role attached to prove the restriction is on the class and cannot be escaped with a role
- [x] PTW creation blocked at zero, at the controller and again inside the creation transaction
- [x] Included quota expires at the period boundary
- [x] Purchased quota survives the boundary
- [x] Top-up credited only by the verified payment path
- [x] Prices recomputed server-side; the browser sends a pack key, never an amount

## Behaviour verified

| Rule | Evidence |
|---|---|
| Consumption order, included then purchased | The approved worked example reproduced exactly: 50 included plus 150 purchased, create 70, leaves 0 and 130 |
| Included expires, purchased carries forward | 10 unused included expire at the boundary, a fresh 50 arrives, 130 purchased untouched |
| Creation locked at zero, unlocked by a top-up | Asserted both directions |
| Deleting a permit does not refund | Balance unchanged after delete; the consumption row survives the permit |
| A permit consumes exactly once | Charged three times, spent one |
| Annual is monthly, not a lump sum | Business annual shows 500 for the month, not 6000 |
| Business annual gets 12 allocations, not 14 | Walked 14 monthly windows, 12 grants exist |
| Granting the same window twice is a no-op | Three calls, one grant |
| An unmetered plan is unmetered, not zero | `metered: false`, creation allowed, balance null |
| Low balance flagged before exhaustion | Flips at the configured threshold |
| Downgrade refused while it would strand My Work accounts | Business to Starter with 20 field accounts refused, naming the My Work reason |
| Purchased packs count towards the target plan on downgrade | Same move with 2 packs allowed |
| A user created without a class is a Full User | A My Work User is never produced by an omitted field |
| Creating a My Work account checks the My Work pool | Full pool at its limit does not block it |
| A top-up does not extend the subscription period | `ends_at` unchanged after a paid top-up |
| A replayed payment notification credits once | Applied three times, credited once |
| An ordinary member cannot buy quota | 403 |
| A customer cannot set their own top-up price | Submitted amount ignored, config price used |
| Renewal invoice itemises and the items sum to the total | Three lines, summing to `amount` |

## Browser

`/pricing` at 1440 and 375. All three cards render the new capacities, verified against the served Inertia props:

| Plan | Full | My Work | PTW/month | Pack |
|---|---|---|---|---|
| Starter | 3 | 10 | 50 | Rp100.000 / 10 |
| Professional | 10 | 30 | 200 | Rp100.000 / 10 |
| Business | 25 | 50 | 500 | Rp100.000 / 10 |

No horizontal overflow at 375. No console errors. All public routes 200. Em dash sweep across all 11 public pages, both literal and JSON-escaped: **0**.

## Not verified

- **Live payment for a top-up.** No transaction was executed against a provider. The credit path is asserted through `applyPaidInvoice()`, which is what the verified webhook calls, but no money moved.
- **The authenticated quota page in a browser.** The development tenant is on Enterprise and therefore unmetered, so the metered states are covered by feature tests through real HTTP rather than visually. Switching a real tenant's plan to photograph a screen was judged a worse trade than testing it.
- **Concurrency under real contention.** Consumption locks the grant rows `FOR UPDATE` and a permit can only consume once by unique index, both asserted logically; no parallel load test was run.
- **Cross-browser and real devices.** Chromium and viewport emulation only.

## Known limitations

| Item | Status |
|---|---|
| Buying My Work packs from the UI | Not built. Capacity is stored, charged, enforced and honoured by downgrade safety; an operator sets the count. PTW top-ups are self-service because that is the limit a field team hits mid-shift |
| Platform Admin per-tenant quota override | Not built. `subscriptions.seat_limit` is the precedent for how it should look |
| Public top-up pack table on `/pricing` | Deliberately absent. The cards say extra documents are purchasable and do not expire, and the FAQ gives the entry price; the full table lives in-product |
| QA-002, Ziggy route manifest | Still open, unchanged from v2.85.0 |
| QA-003, "Enterprise Edition" edition string | Still open, owner decision |
