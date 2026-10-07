---
title: Full System Audit
type: backlog
status: PARTIAL (v2.106.0) — four sweeps completed, scope deliberately recorded
updated: 2026-10-07
tags: [kb/backlog, status/verified]
---

# Full System Audit

**Source:** owner brief — a complete end-to-end audit of the whole product across every package,
role, tenant, company, operating unit, capability and module · **Board:** [[Project Board]]

## Honest scope

The product surface is **531 routes, 118 models, 100 controllers, 181 Inertia pages, 194
migrations**, already covered by 85 test files. An exhaustive manual pass over all twenty sections
of the brief is not something one session can honestly claim.

What was done instead is **systematic automated sweeps** over the whole surface along four
dimensions, each of which inspects every route or every file rather than a sample, and each of which
left behind a permanent test. The sweeps and their results are recorded below, including the ones
that found nothing, because "swept and clean" is a different statement from "not looked at" and the
next session needs to know which is which.

## Sweep 1 — navigation pointing at nothing

Every `href` in the navigation registry resolved against the real route table.
**71 nav entries, 0 broken.**

## Sweep 2 — route-model binding with no isolation scope

A model with a global scope is scoped at binding, so `{model}` in a URL cannot resolve another
tenant's row. A model **without** one resolves by primary key alone and the controller must assert
ownership itself.

**19 models have no global scope; 8 routes bind one. Seven were verified correct:** six are
`/platform/*`, where Master Admin legitimately operates across tenants, and `Approval` carries its
tenancy check inside `ApprovalEngine::authorize()` (documented there).

**One was a real defect — see "PPE types" below.**

## Sweep 3 — user-facing terminology

Every `.jsx` file scanned for stored identifiers reaching a customer.
**Found and fixed: module keys rendered by swapping underscores, in four Settings tabs, several with
CSS `capitalize` on top.** That rule upper-cases the first letter of every word, so it produced
"Hse Inspection", "Jsa", "Loto" and "Ptw". See `docs/ARCHITECTURE.md`.

## Sweep 4 — mutations without an authorization gate

Every POST/PUT/PATCH/DELETE route checked for authentication and for a role or capability gate.

**11 unauthenticated mutations, all correct:** sign-in, registration, password reset, the sandbox
entry and the two signature-verified payment webhooks.

**8 flagged as having no role gate — all false positives**, and worth recording because the
false-positive was instructive: `SubscriptionController`'s guard lives in `currentSubscription()`,
the shared helper every mutation calls first. One guard on one path beats eight copies, but it is
invisible to a middleware list and to a reader checking method bodies. Pinned by behaviour in
`CommercialAuthorizationTest` instead of duplicated.

## The one real defect: PPE types

`ppe_types` is installation-wide shared reference data — a deliberate v2.63.0 decision, and fine for
reading. **Who may change them was never asked.** Create, update and delete are gated by
`role:super_admin`, the *tenant* administrator, and the policy only checks a role capability because
on an unowned row there is nothing else to check.

So **any customer's administrator could rename, re-interval or delete a PPE type every other
customer was issuing**, and a customer adding their own type put it in everyone's dropdown.
`destroy()` had noticed half of it and refuses to delete a type in use anywhere; `update()` had no
guard at all, and a rename is as destructive to whoever reads the other end of it.

Fixed with the pattern already in this codebase for exactly this (`KpiCategory`, `NumberingFormat`,
`NumberingSequence`): a nullable `company_id` where null is the shared built-in and a value is the
customer's own. Both visible, only the second theirs to change. **Nothing is backfilled** — existing
rows stay null, which is what they are.

## Verified

- `SharedMasterDataOwnershipTest` (7), **confirmed to fail without the guard**
- `ProductTerminologyTest` (7) — label registry, client/server mirror, and the two rendering shapes
- `CommercialAuthorizationTest` (12) — the commercial boundary proven from outside
- **Full suite 999 passed / 5274 assertions**, build clean, ESLint 0 errors
- Browser: Settings → Numbering now reads "Material Request", "Permit to Work", "HSE Inspection",
  "JSA", "LOTO", "NCR"; zero lower-case or mis-capitalised variants on the page

## Not covered, and worth a future pass

Stated plainly so nobody mistakes this note for a clean bill of health:

- **CRUD lifecycle completeness** per module (buttons that do nothing, invalid transitions, stale UI
  after mutation) — brief section 6, not swept
- **Cross-module data flow** end to end (Material Request → PO → Goods Receipt → Warehouse;
  Project → Tasks → Work Orders → Assets) — brief section 5, not traced
- **Responsive and empty-state review** of the 181 pages — brief section 14, not done
- **Per-package matrix** executed against every plan × role combination — partially covered by the
  existing `WorkspaceArchitectureTest` and `AccessControlBoundaryTest`, not re-derived
- `nik` remains globally unique in intent but is now per company (v2.105.0); whether a NIK should be
  unique across an installation is a product question nobody has asked

## Links

- [[Employee Import Production Defects]] · [[Add-On Billing and Billing Centre]]
- `docs/CONVENTIONS.md` § *shared reference data with no owner*, § *a guard in a shared helper*
- `docs/ARCHITECTURE.md` § *The product's own name for a module*
