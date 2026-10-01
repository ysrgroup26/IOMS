---
title: IOMS Website Redesign - Master Brief
tags: [ioms, website-redesign, source-of-truth]
updated: 2026-10-01
status: complete
---

# IOMS Website Redesign

## Project objective

Completely redesign the public IOMS website and landing page so its information architecture, product story, copy, visuals, and pricing accurately represent the current product. Create an original IOMS identity informed by the four references, not a copy of them.

## Scope

In scope: public website structure and storytelling; all public copy; product positioning; four operational workspaces; pricing, packages, subscription details and benefits; user/account information; industries; trust/security messaging; visuals, imagery, motion, responsive behavior, SEO, metadata and OG; removal or replacement of outdated public diagrams and concepts.

Out of scope: redesigning the logged-in application or changing registration, email verification, login, company/tenant onboarding, package-selection logic, payment flow, payment activation, subscription lifecycle, renewal/perpanjangan, or existing backend business logic.

## Non-negotiables

- Preserve all protected application and transaction behavior listed above.
- Public claims must not exceed actual product capabilities or server-side entitlements.
- Audit existing implementation before publishing package, price, benefit, billing, or user-limit claims. Never change only frontend numbers to hide an entitlement mismatch.
- If a mismatch is found, record evidence as an issue and verify the existing entitlement behavior before proposing any business-logic change.
- The four customer-facing operational workspaces are HSE, People / HRD, Warehouse Logistics, and Management. Admin Space is tenant administration and must not be marketed as an operational workspace.
- Global Dashboard is Business only.
- No em dash character in website copy.
- Do not invent customers, metrics, certifications, integrations, prices, entitlements, or capabilities.

## Product truth

Known from current project context:
- The customer-facing operational workspaces are exactly HSE, People / HRD, Warehouse Logistics, and Management.
- Admin Space is for tenant administration, not an operational workspace.
- Global Dashboard is Business only.
- Existing customer journey and protected system scope are captured below.

Unknown until repository/product audit: official package names, feature matrix, prices, billing periods, user limits, additional-user rules, detailed workspace entitlements, and exact implementation routes/actions. See 02 - Product & Pricing Truth.md.

## Pricing truth

No plan or price values have been verified from an application repository in this context. Treat all price/package content as blocked from publication until Phase 01 verifies it against configuration and server-side entitlement enforcement. A stated user limit must be enforced across all relevant UI/API paths unless a documented add-on explicitly permits more.

## Purchase flow to preserve

1. Create account.
2. Receive and complete email verification.
3. Log in.
4. Before payment, dashboard access remains unavailable.
5. Return to the public site and complete the existing company details/onboarding step.
6. Select a package and pay through the existing flow.
7. Existing payment activation grants access.

Only public explanation and presentation may change. Preserve current routes, states, actions, and business rules.

## Renewal flow to preserve

Keep the existing subscription renewal/extension process unchanged. Audit and document its current user journey and verification evidence in Phase 01 and 05 - QA & Verification.md.

## Design direction

Premium, modern SaaS, industrial/technical, calm, precise, operational, and professional. Preserve original IOMS identity and use the existing color language as the foundation; tasteful refinements are allowed. Combine authentic product UI with relevant industrial/environmental imagery, editorial visuals, and purposeful motion/video. Do not make the website dashboard-only. Keep motion smooth, intentional, accessible, and respectful of reduced-motion preferences. Avoid generic SaaS templates, AI-looking layouts, excessive cards/gradients/neon, decorative effects, meaningless animation, and unsupported claims.

Use local design skills ui-ux-pro-max, taste-skill, and anti-slop when implementation begins, if available in that environment.

## Reference principles

See 01 - Design References.md for reference-specific borrow/avoid guidance. Treat references as inspiration, not templates; combine their principles using independent design judgment.

## Execution roadmap

- [x] Phase 01: Audit existing website + product truth
- [x] Phase 02: Information architecture + content
- [x] Phase 03: Visual system + page foundation
- [x] Phase 04: Hero + core storytelling
- [x] Phase 05: Workspace/product sections
- [x] Phase 06: Pricing + purchase journey
- [x] Phase 07: Industries + trust + security
- [x] Phase 08: Motion + imagery + responsive
- [x] Phase 09: SEO + metadata + OG
- [x] Phase 10: Full QA + consistency audit
- [x] Phase 11: Final polish + build + commit

For every phase: inspect, implement within scope, verify, document files/decisions/issues/results, then commit only when authorized by the active project workflow.

## Current project status

- Overall status: Documentation system created; implementation has not started.
- Current phase: Phase 01, pending repository and product audit.
- Last updated: 2026-09-29.
- No application code modified and no commit made.

## Next action

Locate and inspect the actual IOMS application repository. Audit public routes, current product/package sources, server-side entitlements, checkout and renewal behavior, and existing design references. Record verified facts and evidence before drafting final pricing or public claims.

## Documentation rule

After every meaningful change, update 04 - Implementation Log.md and 05 - QA & Verification.md, and refresh this brief's status/next action. Record date, phase, work completed, files changed, decisions, verification, issues, commit hash if one exists, and next action.

---

# Status, 2026-09-30

**Overall status:** Redesign executed and verified. All eleven phases complete.

**Pricing truth gate:** SATISFIED. The "no plan or price values have been verified" block recorded above is resolved. Every figure is traced to `config/plans.php`, the `packages` table and `tests/Support/ApprovedCatalogue.php`, and the stated allowance is confirmed enforced server-side by a passing test. See `02 - Product & Pricing Truth.md`.

**Verification:** 692 tests passing with 3813 assertions, zero failures. Clean production build. ESLint zero errors. One reversible migration applied, rolled back and re-applied against MySQL. Every public route exercised in a real browser at desktop and mobile widths. This is the first work in this project's history verified by running it rather than by reading it.

**Protected scope:** Untouched, as required. No change to registration, email verification, login, onboarding, package selection, payment, activation, renewal, subscription lifecycle, entitlement logic, tenant isolation or authorization. Two middleware changes were made, and both decide what is serialized into a page's props, never what anyone may access.

**Outstanding, with reasons:**

| Item | Status |
|---|---|
| WEB-013 / QA-002: Ziggy ships internal route names to public pages | Deferred deliberately. Not visible copy. Filtering Ziggy changes the `route()` helper site-wide and fails silently if the allow-list is wrong, so it warrants its own change with its own verification rather than riding along with a redesign |
| WEB-014 / QA-003: "Enterprise Edition" software edition string | Owner decision. Names the build, not the retired plan, and is never rendered |
| QA-004: JS bundle above the chunk-size warning | Pre-existing and application-wide, not a website concern |

**Not verified, stated plainly:** live payment execution, renewal across a real period boundary, the authenticated UI visually, non-Chromium browsers, and real mobile hardware. See `05 - QA & Verification.md`.

**Next action:** Owner review of WEB-014. Schedule WEB-013 separately.

---

# Scope addition, 2026-09-30: metered PTW and a second user class

**Approved product direction. Not implemented. Not published.**

The website redesign (v2.85.0) is complete and shipped. This is **additional approved scope** for the
IOMS product and, consequently, for the website.

## What was approved

Two billable user classes, Full User and My Work User, counted separately, and a metered PTW document
quota with two pools: an included monthly allowance that expires at the period boundary, and purchased
top-up quota that carries forward until consumed. Plan prices and workspace scope are unchanged.
Enterprise stays retired from public sale.

Figures: `02 - Product & Pricing Truth.md`.
Work, conflicts and open decisions: `04 - Implementation Log.md`.
Website consequences: `03 - Website IA & Content.md`.
Verification required before publication: `05 - QA & Verification.md`.

## Status

| | |
|---|---|
| Product direction | Approved |
| Implementation | Not started. No application code changed |
| Publication | Blocked by the existing publication gate until enforcement exists and is verified |
| Blocking decision | **D-1**, what a My Work User may actually not do |

## Why this is recorded as a reversal, not an update

Two decisions in the current system were taken deliberately and are reversed by this direction:
v2.53.0 retired a second seat pool because two capacity numbers made a plan card a reconciliation
exercise, and v2.82.0 defined a user as one active login account of any kind. Both are documented
with their reasoning in the code itself. The new direction is the owner's to take; recording it as a
reversal keeps the original reasoning available to whoever implements it, so the problems those
decisions solved are re-solved rather than rediscovered.

## The non-negotiables above still apply

Unchanged: public claims may not exceed server-side entitlement; no figure is published before its
enforcement is verified; the four operational workspaces are the four named; Admin Space is not an
operational workspace; Global Dashboard is Business only; no em dash in website copy; nothing invented.

One of them now carries more weight than the rest. The approved model prices a restricted account at
one fifth of a full one, so **the restriction is the product**. Publishing that price before the
restriction is enforced would be the same class of defect as the capacity claim v2.85.0 corrected,
with a direct revenue consequence rather than a credibility one.

## Next action

Owner answers **D-1**. Everything else is designable once that is settled, and building on an assumed
answer would have to be redone.

---

# STATUS, 2026-09-30, end of day: v2.86.0 shipped

The scope addition recorded above is **implemented, verified and published**. Blocking decision D-1
was answered by the owner and all nine open decisions are resolved; see `04 - Implementation Log.md`.

| | |
|---|---|
| Product direction | Approved |
| Implementation | Complete. 733 tests passing, 0 failures |
| Publication | Published. Every figure enforced server-side before it appeared |
| Blocking decisions | None |

**What the model turned on.** A My Work User is sold at a fifth of a Full User's price, so the
restriction is the product rather than a detail of it. It is enforced by a server-side route
allow-list, asserted by direct URL with the strongest tenant role attached, because a restriction
that can be escaped with a role is not a restriction.

**Deferred, and why:** buying My Work packs from the UI (capacity is stored, charged and enforced; an
operator sets it, and PTW top-ups are self-service because that is the limit a field team hits
mid-shift); a Platform Admin per-tenant quota override; and the top-up pack table on the public
pricing page, kept in-product to avoid the wall of billing terminology the direction warns against.

**Still open from v2.85.0:** QA-002 (Ziggy route manifest) and QA-003 (the "Enterprise Edition"
string), both unchanged and both recorded in `05 - QA & Verification.md`.

---

# STATUS, 2026-10-01: v2.87.0, the visual pass

The website is now visually finished to the extent this environment allows, with one honest gap.

| | |
|---|---|
| Product, pricing, entitlement | Unchanged. This pass touched composition, atmosphere, motion and rhythm only |
| Visual storytelling | Done. The hero has a subject, the zigzag is capped, the eyebrows are rationed, the product reads as an object |
| Motion | Done and motivated. Nothing loops, everything honours reduced motion |
| Pricing composition | Fixed, and the cause was a real defect rather than taste. See PAGE-002 |
| Photography | **OPEN, and it needs you.** Nothing else can supply it honestly |

## The one thing the site still needs from the owner

Real photographs of the customer's own operations. Stock photography is ruled out by this brief and
by a passing test, and there is no image-generation tool in this environment, so inventing imagery
would mean either faking it or shipping something the brief forbids. The slots are already wired:
each domain story takes one `{ src, alt }` field and needs no code change. Six slots, listed in
`04 - Implementation Log.md`.

Until then the page is carried by product evidence and typography. That is the honest version of this
site rather than the complete one, and the difference is worth stating plainly.

## A defect worth knowing about

`PackageSeeder` was seeding Enterprise as publicly visible while the pricing migration retires it.
Migrations run before seeders, so any fresh install or re-seed put **Enterprise back on the public
pricing page at Rp2.499.000**, a plan nobody can buy. Fixed, and now pinned by a test. It was found
by a test written during this pass rather than by looking, which is the argument for writing the test.

---

# STATUS, 2026-10-01: v2.88.0, the photography landed

**The gap is closed.** v2.87.0 ended by saying the site needed real photographs and that nothing else
could supply them honestly. They arrived, and the page is now carried by real industrial atmosphere
rather than by product UI and typography alone.

| | |
|---|---|
| Photography | Done. Six placements from eight assets, each chosen per image rather than wired into slots |
| Performance | 14.29 MB of source PNG produces 2.45 MB of responsive WebP. Generated by a committed script |
| Favicon | Done. The official mark on transparency, no square. Installed-app icons deliberately stay opaque |
| Product, pricing, entitlement | Untouched |

## What was decided rather than asked

**The hero photograph is a layer, not a background**, and the closing band is the opposite treatment
on purpose. One keeps its detail beside the copy; the other is a scrimmed ground under a call to
action. Using the same treatment twice would have made the page feel like it had one idea.

**Industries stopped being seven pills** and became three establishing shots, because that is the one
job a photograph does better than any layout.

**The supplied favicon file was not used as the SVG.** It is a 679 KB raster in an SVG wrapper; the
repository's own vector is the same mark in the same colour at 1.8 KB. The rasters ARE generated from
the supplied file, trimmed and re-centred, because it is the designated source.

## Still open

Nothing blocking. Photography on the three sub-pages is deferred rather than skipped: the set is
eight images and spreading it thinner would weaken the landing page without making a sub-page strong.
`Photo` and the derivative pipeline are ready if that is wanted.

QA-002 (Ziggy route manifest) and QA-003 (the "Enterprise Edition" string) remain open and unchanged.

---

# STATUS, 2026-10-01: v2.89.0, the operation has two ends

The v2.88.0 photography and responsive WebP pipeline remain complete. This continuation addressed
the next uncovered website items: public company identity, the My Work sign-in entry, the missing
trust/security story, and a more editorial treatment of the operational-domain section.

| | |
|---|---|
| Public company identity | **Tahada Group**. The legal entity remains **PT Tahada Vistara Bersama**. Technical/internal YSR identifiers were not globally replaced |
| My Work entry | One authentication system. The login page links to My Work; `/my-work` still requires authentication and the account class decides its landing route |
| Hero | Office/management and field/operations photographs meet around the real PTW artifact. Keep the artifact: it makes the shared-record story concrete |
| Domain storytelling | Workshop, warehouse and management photographs are placed in distinct editorial layouts; field remains a text handover. They are not repeated image cards |
| Trust and security | Added after pricing with claims checked against tenant isolation, payment callback verification, approval records and the read-only subscription state |
| Product and commercial logic | Pricing, workspaces, entitlement, PTW rules, account authentication mechanism, billing and subscription lifecycle are unchanged |

**Verification:** 741 tests / 3956 assertions passed; production build completed; ESLint reported 0 errors
and four existing warnings; Chromium local preview confirmed the hero, four-domain story sequence,
login entry and company identity. The specific QA evidence and limits are in `05 - QA & Verification.md`.

**Still open:** QA-002 (Ziggy route manifest) and QA-003 (the "Enterprise Edition" string) are
unchanged. No cPanel work was performed.
