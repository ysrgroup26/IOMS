---
title: Decision Register
type: index
updated: 2026-10-04
tags: [kb/decisions]
---

# Decision Register

Every Architecture Decision Record, its status, and how it relates to the others.

**The ADR files in `docs/ADR/` are authoritative for the reasoning.** This register holds only what
an index can usefully add: status, relationships, and a one-line reminder of what each one settled —
so you can tell whether a decision applies before opening it.

> [!important] Read the relevant ADR before re-litigating a decision
> Each of these was made with tradeoffs in view. `CLAUDE.md` is explicit that the point of the ADR
> folder is to stop the same argument happening twice.

Status vocabulary is defined in [[Working with This Knowledge Base]].

---

## Later brand decision

**v2.90.0 supersedes the icon-surface split recorded at v2.88.0.** The owner-designated
`ioms-favicon-transparent.svg` is now the source for every browser favicon, Apple touch icon and PWA
icon. Every generated variant stays transparent; the general vector mark used by ordinary product
surfaces and PDFs remains separate and unchanged. The earlier claim that an Apple icon needed an
opaque tile is withdrawn. For maskable manifest icons, the [W3C specification](https://www.w3.org/TR/appmanifest/#icon-masks-and-safe-zone)
requires user agents to composite transparency onto a solid fill they choose; Safari 17.2 documents
spacing adjustments for a transparent custom-shaped icon ([Apple release notes](https://developer.apple.com/documentation/safari-release-notes/safari-17_2-release-notes?language=_5)).
The platform controls that display treatment; IOMS does not add a background to the artwork. Full
implementation and verification notes: [[IOMS Website Redesign/04 - Implementation Log]] and
[[IOMS Website Redesign/05 - QA & Verification]].

---

## Active decisions

| ADR | Settled | Status |
|---|---|---|
| [[001-approval-engine\|001 — Universal Approval Engine]] | One polymorphic `approvals` table + `HasApprovals`, not per-module approval code | `#status/implemented` — **extended by 010** |
| [[004-timeline-engine\|004 — Activity Timeline]] | The recording mechanism already existed; only the viewer was built | `#status/implemented` |
| [[006-material-request-workflow\|006 — Material Request Workflow]] | The full lifecycle, and **why "Pending Approval" is not a stored status** | `#status/implemented` — **extended by 030** |
| [[007-workspace-navigation\|007 — Workspace Navigation]] | One declarative registry; navigation is subordinate to authorization | `#status/implemented` |
| [[008-tenancy-foundation\|008 — Tenancy Foundation]] | Tenant → Company; RBAC installed but **not** the live authorization path | `#status/implemented` — architecture frozen |
| [[009-numbering-engine\|009 — Numbering Engine]] | Centralised, lock-safe document numbering | `#status/implemented` — **refined by 025** |
| [[010-approval-engine-v2\|010 — Approval Engine v2]] | Multi-step / parallel / conditional / escalating chains, **additively** — no flow configured means byte-identical old behaviour | `#status/implemented` |
| [[011-notification-center\|011 — Notification Center]] | Event-driven notifications, never seeded dummies | `#status/implemented` |
| [[012-milestone-numbering\|012 — Milestone Numbering]] | Numbering for Milestones, and why it stops there | `#status/implemented` |
| [[013-activity-center\|013 — Activity Center]] | A cross-record audit surface over the existing log | `#status/implemented` |
| [[014-global-search-generalization\|014 — Global Search]] | Generalised search across modules | `#status/implemented` |
| [[015-identity-hierarchy-clarity\|015 — Identity Hierarchy]] | Master vs Administrator — who is who | `#status/implemented` |
| [[016-tenant-module-workspace-grants\|016 — Module/Workspace Grants]] | **Departments are what is sold**; chrome is not | `#status/implemented` |
| [[017-dynamic-role-management\|017 — Dynamic Role Management]] | Roles editable per tenant, without becoming the authorization path | `#status/implemented` |
| [[018-approval-flow-numbering-tenant-scoping\|018 — Flow/Numbering Tenant Scoping]] | Per-tenant approval flows and numbering formats | `#status/implemented` |
| [[019-analytics-framework\|019 — Analytics Framework]] | | `#status/implemented` |
| [[020-report-center\|020 — Report Center]] | | `#status/implemented` |
| [[021-dynamic-document-engine\|021 — Document Engine]] | Tenant letterhead on every generated document | `#status/implemented` |
| [[022-import-export-mapping\|022 — Import/Export Mapping]] | Column mapping with a preview step | `#status/implemented` |
| [[023-enterprise-integration-verification\|023 — Integration Verification]] | | `#status/implemented` |
| [[025-numbering-sequence-portable-uniqueness\|025 — Portable Numbering Uniqueness]] | Works across MySQL **and** MariaDB | `#status/implemented` |
| [[026-rc1-release-audit\|026 — RC1 Release Audit]] | Deployment-readiness audit findings | `#status/implemented` |
| [[027-deployment-architecture-redesign\|027 — Deployment Architecture]] | One deploy flow for every environment | `#status/implemented` |
| [[028-remove-nodejs-from-production\|028 — No Node.js in Production]] | **Built assets are committed** — production has no npm | `#status/implemented` |
| [[029-production-seeding-without-faker\|029 — Seeding Without Faker]] | Production seeding must not need a dev dependency | `#status/implemented` |
| [[030-material-request-lifecycle-and-demand-consolidation\|030 — MR Lifecycle and Consolidation]] | `consolidating`, aging, and many-MRs-to-one-PR | `#status/verified` (v2.69.0, browser-exercised) |
| [[031-employee-cases\|031 — Employee Cases]] | Case + actions; standing derived; confidentiality narrower than HR | `#status/implemented` (v2.69.0) |
| [[032-obsidian-knowledge-base\|032 — Obsidian Knowledge Base]] | This vault: a map over the documentation, never a copy. Vault root = repository root | `#status/implemented` |
| [[033-subscription-lifecycle\|033 — The Subscription Lifecycle]] | Stored status = what was decided; standing derived from the dates. Expiry is read-only, never a lockout, never destructive. Time is added, never reset. A payment buys time, not reinstatement | `#status/verified` (v2.70.0, browser-exercised) |
| [[034-capability-reach-and-navigation-hierarchy\|034 — Capability Reach and Navigation Hierarchy]] | A capability must be reachable by whoever owns it; two navigation levels, not three type sizes; page KIND (master / operational / monitoring / administration) carried by the shared header | `#status/verified` (v2.71.0, browser-exercised) |
| [[035-application-language-system-feasibility\|035 — Application Language System: Feasibility]] | A real English / Bahasa Indonesia switch is **~3,900 string sites across 190 files** with no translation layer at all (3 `__()` calls, no `lang/` dir). Three real blockers: status labels are DERIVED from stored enum values rather than written anywhere, 151 locale-formatting call sites are hardcoded, and [[Verification Status\|the language-hierarchy test]] would keep passing while protecting nothing. The first deliverable is a PRODUCT decision about which slots are translatable — quite possibly only the explanatory layer. **Deliberately not started**: no `lang/` dir, no unused dependency, no placeholder switch | `#status/investigated` (v2.72.0, measured from the tree; nothing implemented) |
| [[036-incident-report-versus-investigation\|036 — Initial Report vs HSE Investigation]] | Two records, two workspaces, two numbers, two workflows. The report is filed in minutes by whoever was there (5W1H, four required fields) and is NOT editable from the investigation, because it is the source of fact. The investigation carries its own number, team, interviews, three-layer causal analysis, review and closure. Analysis methodologies (5 Why, Fishbone, SCAT, RCA) are optional instruments, explicitly NOT legal requirements | `#status/verified` (v2.73.0, full lifecycle browser-exercised) |
| [[037-pwa-installability-without-caching-tenant-data\|037 — Installable, Without Caching Tenant Data]] | A service worker exists ONLY so browsers will offer to install IOMS. It caches two public prefixes (`/build/`, `/branding/`) as an allow-list and nothing else: a SW cache is keyed by URL and has no concept of identity, so caching an authenticated page would serve one tenant's data to the next person signing in on a shared terminal — bypassing every server-side scope at once. No offline mode, no navigation fallback | `#status/verified` (v2.73.0, cache contents measured after six authenticated pages and a PDF) |
| [[039-public-search-identity\|039 — v2.81.0 addendum]] | `www.iomsuite.com` is 301-redirected to the canonical origin — but only that one alias (not "any non-canonical host", which would bounce a health check by IP, an internal hostname and the legacy domain), and only for GET/HEAD: a client may turn a 301 on a POST into a GET and drop the body, which would silently discard a payment webhook. Production only, prepended, switchable by config. The invoice stops using the shared TENANT letterhead — fed the issuer identity it drew the IOMS lockup beside the word IOMS — and gets its own header on the email navy band; the shared partial is left untouched because every tenant document depends on it. The shipped mark is confirmed byte-identical to the designer master with the background rect removed; at v2.81 the favicon and social card had navy grounds; browser favicons became transparent in v2.88 and Apple/PWA icons joined them in v2.90, while the social card keeps its own designed ground | `#status/verified` (v2.81.0) |
| [[047-complimentary-access-provisioning\|047 — Complimentary access is a provisioning decision]] | Master Admin grants free access by PROVISIONING THE VERIFIED REGISTRATION, through the same body a payment uses with different commercial terms. It never fakes a payment: no invoice is raised, none is marked paid, no transaction is written, and an unpaid invoice is VOIDED rather than settled. It grants nothing extra either: an ordinary subscription with a real end date that lapses to read-only on expiry, because free access that outlived its dates would make `billing_mode` the second source of truth ADR 041 forbids. The request carries three fields (plan, months, reason) and decides nothing else; duration is a closed allow-list, not an integer. Needed NO migration | `#status/verified` (v2.93.0) |
| [[046-admin-space-is-a-context\|046 — Admin Space is a context, and administrative authority lives in it]] | ADMIN SPACE IS A PLACE, NOT A PAGE: exactly one of the workspace selector and Admin Space carries the active treatment, the operational header links disappear inside it, and you leave only by choosing a workspace — previously the Dashboard link, which redirects into a workspace on any plan without the company dashboard, silently dropped an administrator into HSE. THE BOUNDARY RUNS BOTH WAYS: an administrator does not get administrative menus inside a workspace, and operational functionality is not duplicated inside Admin Space. User administration was leaking into HSE at three layers including the ROUTING layer, where v2.46.0 had opened it deliberately; all three are closed and the PTW Access permission is untouched. WAREHOUSE LOGISTICS is one workspace with one name, carried by a data migration because the catalogue label overrides the code. THE SUPPORT 500 was a pending migration, not a support bug — reproduced by dropping the tables, and now REPORTED by the operations console rather than rendered as a blank 500. DUITKU is implemented at the existing provider boundary, with the signature scheme quoted from the official documentation rather than inferred, and nothing anywhere fakes a payment | `#status/verified` (v2.84.1) |
| [[045-five-spaces-and-the-global-dashboard\|045 — One platform, five spaces, shared data]] | THE FINAL SHAPE: four operational workspaces (HSE, People / HRD, Logistics / Warehouse, Management), one administrative SPACE (Admin Space), and a GLOBAL COMPANY DASHBOARD that is Business-only and is not a workspace at all. It corrects ADR 044, which built the right capabilities on the wrong shape. Management had required a ROLE on top of the plan — the only workspace in IOMS that did — and returned 403 to accounts the customer had paid for; the predicate was DELETED rather than relaxed, so one method now answers workspace access for all four. The sidebar’s catch-all state, which merged Reports with Administration and made Admin Space read as one giant application dashboard, became three named spaces with exactly one navigation each. Seven departments left customer-facing navigation, plans and marketing copy with their code, routes and data untouched, and Enterprise stopped being `*` because a retired plan must not be the one place an unsold department can still be granted. A plan without the Global Dashboard is REDIRECTED to its own workspace Overview, never refused: `/dashboard` is the landing route, and a denial there would greet a paying customer on every sign-in | `#status/verified` (v2.84.0) |
| [[044-management-workspace-and-admin-space\|044 — Three questions, three places to ask them]] | THE BUSINESS TIER’S FOURTH NAME NOW NAMES SOMETHING. `management` is a real department-tier workspace, and it deliberately OWNS NOTHING — no table, no cache, no write route, so a management figure cannot drift from the record it describes. Dashboard asks “what is happening now”, a Department Overview asks “what is happening here”, Management asks “how is the company doing”. Nothing is invented: no inventory value (no unit cost exists), no TRIR (no reliable exposure denominator), no compliance score (no denominator at all), and every section reports whether it has data so an empty tenant is told NO DATA rather than shown a confident zero. Two server-side gates, plan AND person — an HSE supervisor is not management. ADMIN SPACE is the CUSTOMER’s administration and is not Master Admin: `isTenantAdmin()` requires a tenant, so an operator never becomes a customer’s administrator, and the reframing added ONE route while every existing form kept its own. WORKSPACE FOCUS is where a person STARTS, never what they may reach: one nullable column no middleware, policy or capability method reads, validated only so a stale focus degrades to All Workspaces instead of stranding somebody on a 403 | `#status/verified` (v2.83.0) |
| [[043-pricing-included-users-and-add-ons\|043 — Included users, paid extras, three annual offers]] | `max_users` becomes an ALLOWANCE, not a ceiling: `seatLimit() = includedUsers() + additional_users`, and the purchase belongs to the SUBSCRIPTION so it survives a plan change and counts on the new plan. A user is an ACTIVE LOGIN ACCOUNT — not a device (no device seats exist), not an employee record (separate tables), not a deactivated account (its slot is freed, which is how capacity is released without deleting history). One add-on price for every plan, from config not a column. The annual benefit is three different offers: 12/12, 11/12 (discount) and 12/**14** (extra service) — Business needs `annual_months` because its price is exactly twelve monthly payments, so no saving can be derived and calling it "2 months off" would advertise a discount not being given. Add-ons are recurring and billed on the next renewal, for the months PAID rather than received. Enterprise retired from sale, not deleted | `#status/verified` (v2.82.0) |
| [[042-support-queue-is-not-an-inbox\|042 — The support queue is not an inbox]] | A ticket is a STATE MACHINE with a conversation attached, not a copy of an email thread. Two transitions are automatic because a manual status ends up wrong: a customer message reopens (even from closed), a support reply hands the ball back. The default queue is the WORK (open + in progress), ordered by how long somebody has waited — not by priority, so an urgent ticket raised a minute ago cannot bury a normal one ignored for three days. Age is derived from the last CUSTOMER message. Identification is an exact email lookup, never a domain guess; an unidentified sender is a supported state, which is why the table is platform-owned and NOT tenant-scoped (role-gated instead, asserted). Replies come from `support@`, not noreply, and are recorded before they are emailed. Inbound mail ingestion is **BLOCKED** on the transport decision and is not faked | `#status/implemented` (v2.80.0) |
| [[041-billing-mode-is-not-entitlement\|041 — Billing mode is not entitlement]] | `paid` / `manual` / `complimentary` answers ONE question — who takes the money. It never answers "what may this tenant use" (the plan grants) or "may they write today" (the derived lifecycle). Complimentary is never invoiced and never chased, but it does **not** extend access past a period end: a flag that could would be a second source of truth for the thing ADR 033 exists to keep single. A free account meant to run indefinitely is `type = lifetime`. An unrecognised value falls back to BILLABLE, because a typo must not make a paying customer free | `#status/verified` (v2.80.0) |
| [[033-subscription-lifecycle\|033 — v2.80.0 addendum]] | The grace window and renewal reminder are **decided**: 7 days and H-7, one policy in two numbers, still configuration. And `Subscription::stateSnapshot()` becomes the ONE place a lifecycle fact is assembled — both sides already derived the same state, but each built its own surrounding facts, which is how two screens start disagreeing about one subscription. Every customer and operator surface spreads the snapshot; `SubscriptionStateParityTest` compares the rendered payloads across all six situations | `#status/verified` (v2.80.0) |
| [[040-master-admin-is-an-operations-console\|040 — Master Admin is an operations console]] | Master Admin answers "how is the platform operating?", never "how is my company operating?". Lifecycle figures are DERIVED (`lifecycleState()`), so the console and the customer's Billing page cannot disagree; payment activity is read provider-agnostically from invoices and transactions. Platform notifications reuse the `Notification` model via `notifyPlatformAdmins()` (lifts the user tenant scope, role-restricted) and never reach tenant users. Support and Notifications stay separate surfaces. **Master Admin is written in Bahasa Indonesia** — an internal console no customer opens, so the customer-facing language hierarchy does not apply | `#status/verified` (v2.79.0) |
| [[037-pwa-installability-without-caching-tenant-data\|037 — v2.78.2 addendum]] | Service-worker freshness split by URL shape: hashed `/build/` stays cache-first, stable-named `/branding/` becomes stale-while-revalidate, `CACHE_NAME` v1→v2 to evict pinned pre-rebrand artwork. Allow-list unchanged (two public prefixes, nothing authenticated). Legacy removed after confirming unused: committed `public/build.zip` (pre-rebrand bundle) and the unread `default_wordmark_path`/`default_icon_path` config keys | `#status/verified` (v2.78.2; production needs a deploy) |
| [[038-account-organization-subscription\|038 — payment-provider review]] | A reviewer uses the ordinary product: self-service signup up to payment, and an operator-created tenant (Platform → Tenants → Add Tenant, Starter) for billing, renewal and upgrade. The reviewer is the per-organization administrator role every customer's first user holds -- no platform access, isolated tenant. No reviewer mode, no fake payment, credentials never in the repository | `#status/verified` (v2.78.1) |
| [[033-subscription-lifecycle\|033 — v2.78.1 addendum]] | A cross-cycle upgrade is two changes: plan prorated in the CURRENT cycle now, cycle switch scheduled for the boundary and preserved when the upgrade is paid (was: yearly prices over a monthly remainder, ~12x overcharge). Plan-change dialog states one server-computed outcome | `#status/verified` (v2.78.1) |
| [[033-subscription-lifecycle\|033 — v2.78.0 addendum]] | Lifecycle email through the existing email system: upcoming expiry = the renewal invoice (copy fixed for existing customers); grace and lapsed once per period via `subscriptions.lifecycle_notified`; lapses older than 7 days not announced; renewed sent only from `applyPaidInvoice()` after commit, never for suspended/cancelled | `#status/verified` (v2.78.0; real inbox delivery not exercised) |
| [[033-subscription-lifecycle\|033 — v2.77.0 addendum]] | Lifecycle unchanged; every state now states its dates and offers one action (Renew subscription / Pay renewal invoice; none for suspended/cancelled). Read-only proven by a sweep of every write route. Own-account writes (`account.`, `verification.`) allowed while lapsed. Man-Hour: `man_hour_logs` is the only source, totals aggregated in the DB over the whole period, `work_date` normalised to `Y-m-d` on write | `#status/verified` (v2.77.0; live Midtrans not exercised) |
| [[039-public-search-identity\|039 — Public search identity, and the email logo]] | `IOMS_PUBLIC_URL` (https://iomsuite.com) is the canonical origin for canonical URLs, sitemap, structured data, social cards and **email images**, separate from `APP_URL`, which still builds every working link. `config/seo.php` is the ONE allow-list of indexable pages; a global middleware sends `X-Robots-Tag: noindex` to everything else, to other hosts and to non-production. Login/register crawlable-but-noindex rather than disallowed. Email logo: official dark lockup flattened onto the header navy, served from the canonical origin. **v2.76.0:** the product definition is visible landing content (H1 + domains + industries), not only metadata; one metadata source (page-level `<meta>` banned); domains told as `StorySection` rows with text always in HTML; favicon = official mark on solid navy, plus `/favicon.ico` and a 48px PNG; no SSR, so a `<noscript>` summary serves non-JS clients; no llms.txt | `#status/verified` (v2.75.0; Search Console steps are manual and **not** done) |
| [[038-account-organization-subscription\|038 — Account, Organization, Subscription]] | Three separate things. An account exists on its own: registering creates a person, not a tenant/company/subscription, and does NOT redirect to plan selection. **The keystone**: `isPlatformAdmin()` reads the ROLE, because `tenant_id IS NULL` used to mean "platform operator" and an unsubscribed account has no tenant by design. `RequireOrganization` is a fail-closed allow-list in the GLOBAL stack, before the entitlement middleware. Google sign-in via Socialite links on the stable `sub`, and on email only when Google reports it verified. Subscribing reuses the existing checkout/webhook/provisioning path unchanged; `tenant_registrations.user_id` tells provisioning to ATTACH rather than create. **Get Started = account registration; Continue setup / Choose a plan = the account + company + plan setup form** (finalised v2.74.2). The public page asks for four things and shows no company, plan, price or payment; a `?plan=` from a pricing card waits in the session until setup opens. The pay-first `POST /get-started` is deleted, not merely unlinked. There is ONE setup form, rendered only by `SubscribeController`, with `account` required — a parallel wizard was built first and removed, because two forms selling one product drift. Registration ends on a FORK ("Continue setup" / "Maybe later"), not in either branch | `#status/verified` (v2.74.0, full journey browser-exercised end to end; Google OAuth architecturally complete but **not** executed — no credentials in this environment) |

## Incident records

Kept as ADRs because the resolution is a decision, not just a postmortem.

| ADR | What happened |
|---|---|
| [[024-stale-config-cache-deploy-incident\|024 — Stale config cache]] | A cached `bootstrap/cache/config.php` survived a deploy and served stale config |
| [[029-public-html-split-directory-incident\|029 — `public_html` split-directory 404s]] | Hosting layout mismatch produced production 404s |

---

## Relationships worth knowing

| Relationship | Detail |
|---|---|
| **001 → 010** | 010 **extends** 001, it does not replace it. The `Approval` table gained four nullable columns; every existing column, relation and route name is unchanged. |
| **006 → 030** | 030 extends the Material Request lifecycle with `consolidating`, aging, and the many-to-many link to Purchase Requisitions. |
| **009 → 025** | 025 makes numbering-sequence uniqueness portable beyond MySQL. |
| **008 → 016, 017, 018** | The tenancy foundation is the base those three build grants, roles and per-tenant scoping on. |
| **027 → 028, 029** | The deployment redesign is what made removing Node.js and Faker from production possible. |

## Decisions recorded outside `docs/ADR/`

Not everything settled has an ADR. These live elsewhere and are equally binding:

| Decision | Where |
|---|---|
| The bilingual **language hierarchy** (English slots vs Indonesian slots) | `CONVENTIONS.md`, [[CLAUDE]] — **supersedes** v1.11.7's "translate everything" policy |
| A stat card may clip its **value**, never its **label** | `CONVENTIONS.md` |
| Colour in a chart must not borrow a meaning the app already assigns it | `CONVENTIONS.md` |
| The Entitlement Dependency Rule | `ARCHITECTURE.md` |
| Two-zone navigation — **designed, deliberately not built** | `UX_ARCHITECTURE_DISCOVERY.md` §4, §22.3 |

---

## Known gaps in the ADR sequence

Stated so nobody spends time looking for missing files:

- **002, 003 and 005 do not exist.** No record explains why; the numbering simply skips them.
- **029 is used twice** — `029-production-seeding-without-faker` and
  `029-public-html-split-directory-incident` are different decisions sharing a number.
- The next ADR should be **040** — 039 is [[039-public-search-identity]]; 038 is [[038-account-organization-subscription|capability reach and navigation hierarchy]].

---

See also: [[Security Decisions and Lessons]] · [[Requirements Register]] · [[Release History]]
