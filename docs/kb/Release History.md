---
title: Release History
type: index
updated: 2026-09-20
tags: [kb/releases]
---

# Release History

Every release of IOMS, in one place — because until now there was no such place.

> [!important] Two sources, neither complete on its own
> **`config/ioms.php` → `version_history` is authoritative for v1.6.0 onward.** It is what the
> in-app About dialog renders, so it is a product surface, not a document — which is why it stayed
> current while `CHANGELOG.md` did not.
>
> **`CHANGELOG.md` is authoritative for v1.1.0 – v1.5.4 only**, which predate that array. It stops
> at v2.0.0, leaving **71 releases reachable only through `config/ioms.php`**.
>
> This note indexes both. The **headlines below are derived, not authored** — the full summary for
> each release lives in the authoritative source. Regeneration instructions:
> [[Working with This Knowledge Base#Regenerating the release index]].

> [!warning] The dates are unreliable; the ordering is not
> 16 of these entries are dated *after* the current release date, and two pairs run backwards
> (v2.12.0 → v2.13.0, and v2.64.0 → v2.65.0 by 20 days). Version order is correct. See
> [[Known Issues and Limitations]].

---

## How to read a release

Roughly, the history falls into eras:

| Era | Versions | Theme |
|---|---|---|
| Foundation | 1.1 – 1.6.x | SAFETY LOG → Shipyard Management System. Employees, PPE, KPI, Projects |
| Engines | 1.6.8 – 1.6.9.1 | Approval Engine, Workflow Engine, Activity Timeline — Material Request as the reference |
| Industrial core | 1.7 – 1.11.x | HSE depth, PTW, the department workspaces, the industrial modules |
| Tenancy | 2.0 – 2.1x | Milestone 2: Tenant → Company, RBAC infrastructure, Platform Super Admin |
| Platform engines | 2.1x – 2.3x | Milestone 3: numbering, notifications, search, analytics, reports, documents |
| Commercial | 2.4x – 2.6x | SaaS productization, pricing, payments, the public site, deployment |
| Product quality | 2.62 – 2.71 | Structural tenant isolation, language coherence, UX correction, operational lifecycles, the commercial lifecycle completing its own loop -- and the navigation and information architecture catching up with all of it |

---

## Index

| Version | Date | Headline |
|---|---|---|
| `2.74.2` | 2026-09-20 | GET STARTED CREATES AN ACCOUNT |
| `2.74.1` | 2026-09-20 | ONE SUBSCRIPTION SETUP FORM, TWO WAYS INTO IT |
| `2.74.0` | 2026-09-20 | ACCOUNT, ORGANIZATION AND SUBSCRIPTION ARE THREE THINGS |
| `2.73.0` | 2026-09-20 | HSE REASSESSMENT: THE REPORT, THE INVESTIGATION, THE PERMIT, AND AN INSTALLABLE APP |
| `2.72.0` | 2026-09-17 | THE BRAND, THE SEAL, AND THE DOCUMENT FORM |
| `2.71.0` | 2026-09-16 | CAPABILITY REACH, NAVIGATION HIERARCHY, AND THE MASTER/OPERATIONAL DISTINCTION |
| `2.70.0` | 2026-09-15 | THE SUBSCRIPTION LIFECYCLE, COMPLETED |
| `2.69.0` | 2026-09-14 | THREE OPERATIONAL GAPS, AND THE ONE SCHEMA LIMIT UNDERNEATH TWO OF THEM |
| `2.68.0` | 2026-09-10 | ONE PRODUCT, ONE VOCABULARY |
| `2.67.0` | 2026-09-09 | THE APPLICATION SHELL |
| `2.66.0` | 2026-09-09 | MASTER DATA FORM EXPERIENCE ROLLOUT |
| `2.65.0` | 2026-09-09 | THE IOMS FORM EXPERIENCE SYSTEM |
| `2.64.0` | 2026-09-29 | LANDING PAGE: THE OPERATING LOOP, plus two defects the redesign uncovered on the way |
| `2.63.0` | 2026-09-28 | SECURITY FOLLOW-UP: THE BLIND SPOT v2.62.0 LEFT, FOUND BY AUDITING ITS OWN COVERAGE TEST |
| `2.62.0` | 2026-09-28 | SECURITY: TENANT DATA ISOLATION IS NOW STRUCTURAL, NOT CONVENTIONAL |
| `2.61.0` | 2026-09-27 | THE PUBLIC SITE, REDESIGNED AROUND WHAT THE PRODUCT ACTUALLY IS |
| `2.60.0` | 2026-09-26 | THE FOUR-TIER PRICING MODEL, and the two silent failures the shape of the old one was hidi |
| `2.59.0` | 2026-09-25 | Landing page experience and brand consistency |
| `2.58.0` | 2026-09-24 | THE EMPTY SIDEBAR, and it was two layers disagreeing rather than one bug |
| `2.57.0` | 2026-09-23 | Production email configuration, and two defects that only a live mail transport would ever |
| `2.56.0` | 2026-09-22 | Checkout corrections after the v2.55.0 review, and one pricing derivation that stopped bei |
| `2.55.0` | 2026-09-21 | Infrastructure, domain, email and payment-verification readiness |
| `2.54.0` | 2026-09-20 | The organizational model, stated once: IOMS -> Organization -> Operating Unit(s) -> Depart |
| `2.53.0` | 2026-09-19 | Product, UX, content and SaaS consistency pass |
| `2.52.0` | 2026-09-18 | Consolidated product revision |
| `2.51.0` | 2026-09-17 | SaaS Product Finalization |
| `2.50.0` | 2026-09-16 | SaaS Finalization |
| `2.49.0` | 2026-09-15 | My Work brought into the shared card system, and the root cause of its drift removed |
| `2.48.0` | 2026-09-14 | Focused visual correction: the icon chip and the card surface are drawn from TWO different |
| `2.47.0` | 2026-09-13 | Completion pass for the global visual refresh |
| `2.46.0` | 2026-09-12 | PTW Access authorization fix + tinted-surface refinement |
| `2.45.0` | 2026-09-11 | Global visual alignment |
| `2.44.0` | 2026-09-10 | Depth, material and real data visualisation across the product |
| `2.43.0` | 2026-09-09 | IOMS-wide visual system, propagated through SHARED COMPONENTS rather than page-by-page sty |
| `2.42.0` | 2026-09-08 | Product/UX correction pass driven by real use of the deployed app, not another audit |
| `2.41.0` | 2026-09-07 | Per-tenant document numbering + tenant branding assets |
| `2.40.0` | 2026-09-06 | Full-system remediation, anchored on a P0 tenant-isolation defect in company_settings |
| `2.39.0` | 2026-09-05 | Truthful zero states + repository consolidation |
| `2.38.0` | 2026-09-04 | SaaS foundation (Master Audit P1) |
| `2.37.0` | 2026-09-03 | Security + testability (Master Audit P0) |
| `2.36.0` | 2026-09-02 | Visual System 2.0: a genuine, visible transformation rather than another token-only pass |
| `2.35.0` | 2026-09-02 | Visual System + Information Architecture Refinement: fixed a real reported mobile bottom-n |
| `2.34.0` | 2026-09-02 | Post-Deployment Product Gap pass: audit-first review of six named production concerns foun |
| `2.33.0` | 2026-09-01 | Phase 4 (Operational Documents + Waste + Backup/Security Audit): audit-first pass across D |
| `2.32.0` | 2026-09-01 | Interior UI Completion Phase 3B (People, Operations, KPI): Employees Index and Man-Hour/Wo |
| `2.31.0` | 2026-09-01 | Interior UI Transformation Phase 3 (People, Operations, Management/KPI, Settings, Micro-in |
| `2.30.0` | 2026-09-01 | Interior UI Transformation Phase 2 (Domain Experience): actual domain-page redesigns, not |
| `2.29.0` | 2026-09-01 | Authenticated UI Visual Transformation: shared-component-first pass, not a page-by-page au |
| `2.28.0` | 2026-09-01 | Product Experience Transformation, Deliverable B (PTW A4 Document): PTW Document.jsx and p |
| `2.27.0` | 2026-08-31 | Public Website & Auth Visual Transformation: redesigned Hero with a platform visualization |
| `2.26.0` | 2026-08-31 | Final Copy Consistency Check: naturalized the Dashboard hero subtitle, Material Requests s |
| `2.25.0` | 2026-08-31 | Global UX & Copywriting Polish: naturalized 18 confirm() dialogs from English "Remove this |
| `2.24.0` | 2026-08-31 | Complete Product UI/UX Transformation, closing the remaining gaps: Purchase Requisition (P |
| `2.23.0` | 2026-08-31 | Complete Product UI/UX Transformation, continued: extended the identity-first list pattern |
| `2.22.0` | 2026-08-31 | Complete Product UI/UX Transformation pass: new fixed mobile bottom navigation bar (Home + |
| `2.21.0` | 2026-08-31 | Final Visual Polish pass (P0 scope): PTW Index desktop table consolidated from 9 equal-wei |
| `2.20.0` | 2026-08-31 | PTW Experience & Visual Polish pass: redesigned PTW Document/PDF around a numbered informa |
| `2.19.0` | 2026-08-31 | PTW Access Management Correction pass: fixed settings.users.ptw-access being gated role:su |
| `2.18.0` | 2026-08-31 | Public Website / Landing Page Foundation: `/` is now a genuinely public route (previously |
| `2.17.1` | 2026-08-31 | PTW Field Workflow Verification & Correction pass: fixed the max_users(10)/max_ptw_users(1 |
| `2.17.0` | 2026-08-31 | PTW Field Workflow Foundation + Controlled PTW Access: new per-user "PTW Access" grant (us |
| `2.16.0` | 2026-08-31 | Global Mobile UX Hardening pass: fixed ModuleTabNav (PPE\'s tab bar) causing page-level ho |
| `2.15.0` | 2026-08-30 | Product UI/UX Finalization: fixed the dialog component app-wide so tall forms no longer cl |
| `2.14.0` | 2026-08-30 | SaaS Productization / Pricing Foundation: Package confirmed as the canonical Plan entity ( |
| `2.13.0` | 2026-08-30 | SaaS Phase 1 |
| `2.12.0` | 2026-09-07 | Product Finalization pass: fixed three real cross-tenant leaks found by a fresh security a |
| `2.11.0` | 2026-09-06 | Field/Foreman Experience pass, Phase 3E-3H: Digital Checklist reworked into one-tap OK/Not |
| `2.10.0` | 2026-09-05 | PTW Document Polish pass (Phase 3D): fixed a real PDF/browser-document parity gap (rejecti |
| `2.9.0` | 2026-09-04 | Field/Foreman Experience pass (Phase 3C |
| `2.8.0` | 2026-09-03 | PTW Mobile / Task-First pass (Phase 3B): mobile card list for PTW Index (status always vis |
| `2.7.0` | 2026-09-02 | Field/Foreman Experience pass (Phase 3A): the universal dashboard route now branches to a |
| `2.6.0` | 2026-09-01 | PTW Document View pass: new in-browser, printable PTW document presentation (company heade |
| `2.5.0` | 2026-08-31 | Field HSE Experience pass (Phase 2): CAPA Open/Overdue/In Progress/Closed summary + Overdu |
| `2.4.0` | 2026-08-30 | PTW UX + Field Operations pass (Phase 1): PTW PDF document generation, Create form progres |
| `2.3.0` | 2026-08-29 | HSE Operations pass: new Waste Container Inventory (physical drum/IBC/jumbo-bag stock, sep |
| `2.2.0` | 2026-08-25 | IOMS OS Ecosystem pass: Man-Hour 500 root-caused and fixed (ambiguous SQL column), Quick A |
| `2.1.0` | 2026-08-25 | HSE Starter package made genuinely operational: Man-Hour opened to HSE (entitlement-depend |
| `2.0.0` | 2026-08-16 | Milestone 2: Tenancy Foundation, Platform Super Admin, Package/Subscription structure, RBA |
| `1.6.10` | 2026-08-11 | Material Request complete workflow (HasWorkflow trait); RBAC options evaluated (Spatie Per |
| `1.6.9` | 2026-08-09 | Universal Approval Engine and Activity Timeline viewer |
| `1.6.8` | 2026-08-08 | Employee Import from Excel; Report Export architecture prepared; two severe runtime bugs f |
| `1.6.7` | 2026-08-06 | ModuleTabNav reusable navigation; several desktop density passes; Material Request and PPE |
| `1.6.6` | 2026-08-03 | PPE employee-centric restructure; navigation redesign; Browser QA stabilization (white fla |
| `1.6.5` | 2026-08-02 | About Dialog rebuilt from scratch; sidebar branding hierarchy fixed; six shared foundation |
| `1.6.4` | 2026-08-01 | Universal Task Engine foundation; QA stabilization pass across Dashboard, Sidebar, Dark Mo |
| `1.6.3` | 2026-07-28 | Dashboard/Sidebar/TopNav/Theme/Company Branding usability pass; real Global Search; Forgot |
| `1.6.2` | 2026-07-27 | Root-cause fix for watermark clipping; exact 240px/70px/16px/24px branding specs; searchab |
| `1.6.1` | 2026-07-26 | Fixed a real PHP parse error; Dashboard Today\'s Summary; Top Department Workload; About f |
| `1.6.0` | 2026-07-25 | Enterprise UI refresh: centralized config/ioms.php, About dialog scrolling fixed, branding |

---

## Earlier releases — `CHANGELOG.md` only

These 12 versions predate `version_history`. `CHANGELOG.md` holds their full detail.

`1.6.9.1`, `1.5.4`, `1.5.3`, `1.5.2`, `1.5.1`, `1.5.0`, `1.4.0`, `1.3.2`, `1.3.1`, `1.3.0`, `1.2.0`, `1.1.0`

---

See also: [[Current State]] · [[Decision Register]] · [[Requirements Register]]
