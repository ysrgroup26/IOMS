---
title: Decision Register
type: index
updated: 2026-09-16
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
