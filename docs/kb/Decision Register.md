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
- The next ADR should be **035** — 034 is [[034-capability-reach-and-navigation-hierarchy|capability reach and navigation hierarchy]].

---

See also: [[Security Decisions and Lessons]] · [[Requirements Register]] · [[Release History]]
