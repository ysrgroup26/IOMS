---
title: IOMS Website Redesign - Master Brief
tags: [ioms, website-redesign, source-of-truth]
updated: 2026-09-30
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
