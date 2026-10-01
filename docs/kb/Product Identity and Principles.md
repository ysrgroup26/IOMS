---
title: Product Identity and Principles
type: reference
updated: 2026-10-01
tags: [kb/product]
---

# Product Identity and Principles

What IOMS is, what it may be called, and the rules that constrain every change to it.

---

## The name is a product decision, not a label

**IOMS** is a standalone product brand, like SAP or Oracle. `config/ioms.php` is the single source
of truth for it and states the rule directly:

- **`IOMS`** is the product name. Use it alone.
- **`Industrial Operations Platform`** is the `descriptor` — a positioning line, never a substitute
  for the name.
- **"Integrated Operations Management System" is forbidden as branding.** The expansion is not the
  product name.

### The company behind the product (v2.89.0)

**Tahada Group** is the public-facing company identity. The registered legal entity is **PT Tahada
Vistara Bersama**. These are separate facts: the public site, sign-in and company attribution use the
group brand; legal pages and structured `legalName` use the legal entity; structured Organization
`name` remains **IOMS**. `config/ioms.php` keeps these values in their separate roles. Do not globally
replace YSR: technical and internal YSR identifiers are not public company copy.

That prohibition exists because it was violated: v2.38.0 found the long form hardcoded as a
fallback in **twelve** places — `app.jsx`, the shared Inertia props, four Excel/PDF export classes,
the About dialog, layout tooltips and `config/excel.php` — so it surfaced in browser titles *and* in
generated customer documents whenever a tenant had not set its own company name.

Former names, which appear in older documents and commit history: *Shipyard Management System*,
and before that *SAFETY LOG*.

### What IOMS is, in one line (updated v2.89.0)

> **IOMS — Industrial Operations Platform.** One platform connecting Health, Safety & Environment,
> People / HRD, Logistics / Warehouse and Management across industrial operations.

That sentence is the positioning, and it is written into the product in exactly three places, which
must be changed together:

| Where | What it carries |
|---|---|
| Landing hero (`Pages/Public/Welcome.jsx`) | Product descriptor, one-record value proposition and the four current operational workspaces |
| `config/seo.php` → `home` | Search title and meta description; keep public claims consistent with the four workspaces |
| `app.blade.php` structured data | Organization remains `IOMS`; `legalName` is the legal entity; SoftwareApplication details stay aligned with verified public capabilities |

**IOMS is not an HSE-only product.** HSE is one of four operational workspaces. The page leads with
the operation, and HSE appears where it belongs in the sequence — see [[UX and Design Principles#Landing page storytelling]].

**No claim without a menu item.** Every capability the public site names exists in
`resources/js/lib/workspaces.js`; `LandingPositioningTest` checks the list against it.

**Search and AI summaries are an outcome, not a target.** There is no `llms.txt`, no text written for
machines, and nothing that tries to steer AI Overview wording. The work is to make the page itself say
clearly what IOMS is. Details and the no-JavaScript caveat: [[039-public-search-identity]].

> [!tip] Treat it like a vendor name
> `CONVENTIONS.md` (v2.22.0) says it plainly: IOMS is treated like SAP/Workday/ServiceNow, not like
> its own full expansion.

---

## The product speaks two languages, by slot

This is the rule most likely to be broken by a well-meaning edit, and it was broken three times
across sixteen releases. It is stated in full in [[CLAUDE]] and `CONVENTIONS.md`; in brief:

| Slot | Language |
|---|---|
| Module and feature names, navigation, page titles, column headers, status labels, stat labels, action labels, accessible names | **English** |
| Subtitles, card descriptions, help text, hints, empty-state guidance, `whats_new` | **Indonesian** |
| Legal documents (terms, privacy, refunds) | **Indonesian**, deliberately |

So `<Head title>`, `label=`, `<CardTitle>` and `<th>` are English while `subtitle=`,
`description=`, `<CardDescription>` and `emptyTitle=` are Indonesian — **on the same page**. The
split is per slot, not per page.

**Why it is not negotiable:** a user must be able to say *"open Regulations & Standards"* out loud
and have it match the screen. When a menu item and the page it opens disagree, they stop being
recognisably the same thing.

Established terms are never translated either way: HSE, PPE, JSA, HIRADC, PTW, LOTO, CAPA, TBM,
NCR, RFQ, SP, FPB, SPK. See [[Domain Glossary]].

Pinned by `tests/Feature/LanguageHierarchyTest.php`, which checks **both** JSX literals and
rendered server props — because copy reaches a page from either, and every earlier attempt checked
only one.

---

## Engineering principles

From `ROADMAP.md`'s guiding principles, which have held across the whole history:

1. **Additive only.** New capability arrives as new tables, columns and pages — never a rewrite.
2. **Preserve data.** Migrations never drop or reset existing data.
3. **Keep the UI lightweight.** Reuse the existing card/table/dialog patterns; no redesigns for
   their own sake.
4. **Project as a container.** Any operational module may optionally belong to a Project and append
   to its timeline through the existing polymorphic events table.
5. **One piece of information, one module.** Data is never re-entered across modules. Manpower
   lives in Project Manpower; PPE lives in PPE Distribution; everything else references or derives.
6. **No hardcoded master data.** Anything a customer might change — PPE types, replacement
   intervals, categories, statuses — is a configurable table, not a code constant. This is what
   makes the product usable beyond its first two customers.

## Two habits the codebase enforces on itself

**Verify before building.** Repeatedly, "this feature is missing" turned out to be "the mechanism
exists, only the UI is missing" — `ActivityLog` was already used 32+ times before the Activity
Timeline viewer was built. Check before you add.

**Derive, do not store, anything that can go stale.** If a value can be computed from records that
already exist, it is not state. `Employee::profile_status`, `PurchaseOrderItem::delivered_quantity`,
Vendor Performance and `Employee::currentDisciplinaryStanding()` all follow this rule. The clearest
case: a disciplinary warning lapses on a date, and a stored status column would not notice.

---

See also: [[Product Strategy and Positioning]] · [[UX and Design Principles]] · [[Domain Glossary]]
