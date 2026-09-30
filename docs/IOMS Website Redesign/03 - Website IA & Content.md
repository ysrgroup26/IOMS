---
title: IOMS Website Redesign - Website IA and Content
tags: [ioms, website-redesign, content]
updated: 2026-10-01
status: built
---

# Website IA & Content

Working outline only. Validate current routes, audience, product truth, and conversion requirements in Phase 01 before finalizing page hierarchy or copy.

## Proposed public page hierarchy

1. **Home / landing page**
   - Purpose: explain IOMS and direct visitors to the right next step.
   - Messaging: concise, specific value proposition; only verified product claims.
   - Visual: considered hero with product UI plus relevant operational imagery.
2. **Product / four workspaces**
   - Purpose: explain HSE, People / HRD, Warehouse Logistics, and Management.
   - Each area: operational problem, supported workflow, product evidence, verified package availability.
   - Do not add Admin Space as an operational workspace.
3. **Industries / use cases**
   - Purpose: show relevant contexts using real, supportable examples.
   - Content remains pending confirmed industries and product fit.
4. **Pricing**
   - Purpose: compare verified plans, billing terms, benefits, user limits, and explicit add-ons.
   - Publication blocked until 02 - Product & Pricing Truth.md is verified.
5. **Trust / security**
   - Purpose: answer buyer concerns with substantiated facts only.
6. **How to start / purchase explanation**
   - Purpose: explain the existing account, verification, company onboarding, package, payment, and activation sequence without changing it.
7. **FAQ and footer**
   - Purpose: resolve verified common questions, provide contact/legal/navigation links.

This is a content planning model, not a decision to create new routes. Reuse existing public routes where practical and preserve all protected routes/actions.

## Messaging principles

- State what IOMS does in plain, concrete terms.
- Tie each benefit to a real workflow and product evidence.
- Use consistent terms for account, company/tenant, user, workspace, package, subscription, and renewal.
- Distinguish operational workspaces from tenant administration.
- Keep Global Dashboard messaging Business only.
- Do not publish unverified prices, user limits, benefits, industries, customers, performance metrics, certifications, or security claims.
- No em dash character in website copy.

## Content inventory to complete

- Primary audience and buyer roles: audit required.
- Verified product proposition and differentiators: audit required.
- Workspace capabilities and package mapping: audit required.
- Industries and substantiated use cases: audit required.
- Plan names, prices, periods, included users, add-ons: audit required.
- Trust/security evidence: audit required.
- Existing public routes, CTAs, forms, and SEO metadata: audit required.
- Existing onboarding and purchase entry points: audit required; preserve behavior.

## Copy review checklist

- [ ] Every feature and benefit maps to a real capability.
- [ ] Workspace names match the canonical four.
- [ ] Admin Space is not presented as an operational workspace.
- [ ] Global Dashboard is Business only.
- [ ] Pricing and user limits pass the entitlement publication gate.
- [ ] Purchase and renewal descriptions match current flows.
- [ ] No em dash character appears in website copy.
- [ ] Mobile reading order and CTA labels are clear.

---

# Final IA, as built, 2026-09-30

The proposed hierarchy was validated against the existing routes. **No new public routes were created and none were removed.** The pages that existed already matched the proposed model closely enough that inventing routes would have been change for its own sake.

## Public routes, as built

| Route | Page | Purpose |
|---|---|---|
| `/` | Welcome | Landing. Hero, the four workspaces, the problem, domain stories, product preview, industries, pricing, how it works, FAQ, CTA |
| `/platform-overview` | Platform | What the platform is, four pillars, the domains |
| `/solutions` | Solutions | Domain by domain |
| `/how-it-works` | HowItWorks | The five-stage operating loop |
| `/pricing` | Pricing | Three plans, both billing cycles, capacity and add-on |
| `/faq` | Faq | Seventeen answers; the landing page shows the first eight |
| `/sandbox` | Sandbox | A working demonstration, deliberately not a free trial |
| `/contact` | Contact | The four IOMS mailboxes |
| `/privacy`, `/terms`, `/refund-policy` | LegalDocument | Indonesian policy documents |
| `/get-started` | redirect | Account registration. Redirects to `/register`, unchanged |

Not a public marketing route: `/register`, `/login`, `/subscribe`, every token-scoped order page. All `noindex` per `config/seo.php`.

## Content inventory, completed

| Item | Answer |
|---|---|
| Primary audience | Indonesian industrial buyers: HSE managers, HR leads, directors at shipyards, contractors, manufacturers, mining and energy operators |
| Proposition | One record across four workspaces. Field work and the reporting management reads are the same record |
| Workspace to plan mapping | Verified. See `02 - Product & Pricing Truth.md` |
| Industries | Shipyards, construction, manufacturing, mining, energy and marine. Presented as contexts IOMS is built for, never as customer claims |
| Plan names, prices, users, add-ons | Verified and published |
| Trust and security evidence | Tenant isolation at the data-access layer, payment details never touching IOMS, customer data ownership. All substantiated; no certification is claimed because none is held |
| Purchase entry points | Preserved exactly |

## Copy review checklist, final

- [x] Every feature and benefit maps to a real capability
- [x] Workspace names match the canonical four
- [x] Admin Space is not presented as an operational workspace
- [x] Global Dashboard is Business only
- [x] Pricing and user limits pass the entitlement publication gate
- [x] Purchase and renewal descriptions match current flows
- [x] No em dash character appears in website copy, verified against rendered responses in both literal and JSON-escaped form, and pinned by a test
- [x] Mobile reading order and CTA labels are clear

## Language decision, confirmed

The public site is **English**, continuing v2.64.0. Legal documents remain **Indonesian** and are tagged `lang="id"`. One Indonesian paragraph remains on `/pricing`, defining "pengguna aktif", because that definition has to be unambiguous to the buyer and three plausible misreadings were the reason it was written.

This is a deliberate exception to the `CLAUDE.md` language hierarchy, which governs the authenticated product. `LanguageHierarchyTest` pins the public site to one language and passes.

## Visual direction, as built

Left-aligned editorial composition on a shared margin. Archivo for display, IBM Plex Mono for instrument labels, Inter for body and all authenticated UI. Navy, steel, brand and graphite are unchanged: the existing IOMS colour language was the foundation, as the brief required. Product UI appears as evidence in the showcase and the domain stories, never as the hero. No neon, no decorative 3D, no gradient beyond the existing single steel bloom, no animation without a job.

---

# Pending: website changes for the metered PTW model

**Approved 2026-09-30. NOT IMPLEMENTED.** The public site currently describes the enforced model and
is correct. Nothing below may ship before the enforcement it describes exists.

## The presentation problem to solve

A plan card carries **one** capacity figure today. The approved model gives it **three**: Full Users,
My Work Users, and included PTW documents per period. Plus two add-on prices, one recurring and one
one-off, in three pack sizes.

v2.53.0 collapsed a two-number card specifically because it "made every plan card read as two numbers
a buyer had to reconcile". Going to three without recreating that problem is a real design task, not
a matter of adding rows. Likely direction, to be decided when it is built: capacity belongs in a
comparison table rather than on the card, with the card carrying the price, the positioning line and
the workspace scope it carries now.

## Copy that must change in the same release

| Surface | Current, correct today | Becomes |
|---|---|---|
| FAQ, "How are users counted?" | One allowance of active users, Rp50.000 per additional | Two classes counted separately, two add-on prices |
| FAQ, "What is PTW Access?" | "It is not sold capacity and carries no extra charge" | Still a free permission, but creation consumes a metered document |
| Landing pricing section | "Additional active users are Rp50.000 per user per month on every plan" | Must name both add-ons |
| `/pricing` capacity line | "N active users included" | Two figures plus the PTW allowance |
| `config/seo.php` pricing description | "additional users priced per user" | Must not imply one add-on |

## New copy required

- What a My Work User is and cannot do. Unwritable until **D-1** is answered.
- What consumes a PTW document, and what happens at zero.
- The difference between included quota, which expires, and purchased quota, which does not. This
  distinction is the most likely thing for a buyer to misunderstand and the most likely thing to
  generate a billing dispute, so it needs plain, concrete wording rather than a footnote.
- Top-up pack pricing.

## Constraints that still apply

Everything in the v2.85.0 rules stays in force for the new copy: no em dash in either encoding, only
the four operational workspaces named, Admin Space never presented as operational, Global Dashboard
Business only, English on the public site, and no figure published before its enforcement is verified.

---

# BUILT, 2026-09-30: the pricing page now states both classes and the meter

The pending section above is done. v2.86.0.

## What the cards say

Each plan card carries five facts rather than three: price, Full Users with the per-user add-on, My
Work Users with the per-pack add-on, PTW documents per month, and Operating Units.

The unit is printed on both add-ons, because they are priced differently on purpose and "Rp100.000"
without "per 10 pengguna" reads as the price of one account.

## How the three-number problem was avoided

v2.53.0's warning was that two capacity numbers make a card a reconciliation exercise. The answer
here was not to hide a number but to make each one self-explanatory on its own line, with its add-on
price directly beneath it rather than in a footnote. A reader sizing an office team reads one line; a
reader sizing a field crew reads the next; neither has to subtract anything.

## Deliberately NOT on the public pricing page

The three top-up pack prices. The cards state that extra documents are purchasable and do not expire,
and the FAQ gives the entry price of Rp600 each. A three-row pack table beside three plan cards is
the "wall of technical billing terminology" the direction warns against, and a customer choosing a
pack is already signed in, where the full table lives on the quota page.

## Copy changed

- FAQ, "How are users counted?" now describes both classes, both allowances and both add-on prices.
- FAQ, "What is PTW Access?" keeps the permission free and no longer implies the ACTION is unmetered.
- New FAQ, "How many Permits To Work are included?", covering the monthly allowance, what consumes a
  document, that the allowance does not roll over, and that purchased documents do not expire.
- Landing pricing section names both add-ons and the document meter.
- `config/seo.php` pricing description names both user classes.

Verified: 0 em dashes across all 11 public pages in both encodings, no horizontal overflow at 375,
and every published figure matches server-side enforcement.
