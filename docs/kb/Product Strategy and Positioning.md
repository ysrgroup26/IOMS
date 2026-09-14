---
title: Product Strategy and Positioning
type: reference
updated: 2026-09-14
tags: [kb/product]
---

# Product Strategy and Positioning

Who IOMS is for, what is actually being sold, and the strategic choices behind the shape of it.

---

## The positioning claim

> One platform for how your whole operation actually runs.

IOMS is an operations platform for **industrial, HSE-heavy organisations** — shipyard, construction,
manufacturing, mining and energy. The argument the public site makes is not "we have modules"; it is
that **one record travels the whole loop**: raised in the field, approved behind it, and arriving in
management reporting as data rather than as a stack of spreadsheets that no longer agree.

The explicit anti-positioning, from `ROADMAP.md`: **this is not an ERP**. Simple, fast, minimal
clicks, clean UI. Every module is designed to slot into the existing architecture without a rewrite.

## Who buys it

An Indonesian industrial operator running one or more **Operating Units** under one legal
organisation. That shapes the product in ways that look odd without the context:

- **Currency and invoicing are IDR-first**, and invoices exist as documents because Indonesian B2B
  customers file them.
- **The bilingual slot rule** in [[Product Identity and Principles]] exists because this buyer reads
  explanatory prose more naturally in Indonesian but expects operational vocabulary — PTW, HIRADC,
  Purchase Order — in English, as it appears on their own paperwork.
- **Local document types are first-class**: SP (Surat Peringatan), FPB (Purchase Requisition), SPK
  (Work Order), BAST (handover). See [[Domain Glossary]].

## What is actually sold: departments, not features

The commercial model sells **workspaces** — whole operational departments — rather than feature
flags. A plan grants a set of workspaces and a capacity; the platform underneath is identical for
everyone.

This is why entitlement is enforced at the workspace/module grant level rather than sprinkled
through the code, and why the chrome around a department is never what is being sold. See
[[Pricing Plans and Entitlements]] and ADR [[016-tenant-module-workspace-grants|016]].

### The tier ladder, and the reasoning behind it

| Tier | What it adds | Capacity |
|---|---|---|
| **Starter** | HSE, complete | 10 users, 1 operating unit |
| **Professional** | + People and Workforce (HR) | 50 users, 2 units |
| **Business** | + Project Management, Logistics / PPIC, Procurement | 150 users, 4 units |
| **Enterprise** | Every operational department | Unlimited users and units |

v2.60.0 re-priced this deliberately, and the reasoning is worth keeping because it constrains future
tiering: measured by implemented route prefixes, **Starter is already most of the product** (HSE is
the deepest module set), and Professional was adding a single department for 3.3× the price — the
expensive step was the small one. The ladder now has multipliers that *decrease* as the absolute
price rises.

**Enterprise's product is its capacity**, not its module list: it adds only a handful of route
prefixes over Business, but unlimited operating units and users, plus the per-unit authorization and
legal-entity structures from v2.54.0.

Annual is monthly × 10 on every tier. No percentage is hardcoded anywhere — `PricingService` derives
the saving from the plan's own two prices.

## Strategic constraints that are already settled

These are decided. Re-opening them needs a real reason, not a preference.

| Constraint | Where it was decided |
|---|---|
| Sell departments, not feature toggles | ADR [[016-tenant-module-workspace-grants\|016]] |
| A module must never gate on another *paid* module just because it shares core data | `ARCHITECTURE.md` — the Entitlement Dependency Rule |
| No free trial | v2.60.0 pricing migration |
| No accounting or banking integration | Vendor master scope decision, `MODULES.md` |
| Tenant isolation is structural, not conventional | ADR [[008-tenancy-foundation\|008]], hardened v2.62.0–v2.63.0 |
| The IOMS Sandbox is a real tenant, bounded like a customer | v2.53.0 |

---

See also: [[Pricing Plans and Entitlements]] · [[Modules and Capabilities]] · [[Data Ownership and Boundaries]]
