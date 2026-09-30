---
title: IOMS Website Redesign - Implementation Log
tags: [ioms, website-redesign, project-log]
updated: 2026-09-29
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
- **Commit hash:** recorded below on commit.
- **Next action:** Owner decision on WEB-014. WEB-013 to be scheduled as its own change.
