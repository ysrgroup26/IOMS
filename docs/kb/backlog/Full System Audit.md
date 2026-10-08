---
title: Full System Audit
type: backlog
status: PARTIAL (v2.107.0) — eight sweeps completed, remaining scope recorded
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

## Second pass (v2.107.0) — the four sections that were outstanding

### Sweep 5 — dead controls

Every `route()` referenced anywhere in the client, resolved against the real route table.
**447 unique route names across 279 files, 0 dead.** No button or link points at a route that does
not exist.

### Sweep 6 — CRUD lifecycle and deletions that orphan

Every DELETE route cross-referenced against the 333 foreign keys in the schema, looking for a model
whose dependents RESTRICT with no check in the controller. **Two were real**, both described in
`docs/CONVENTIONS.md`: deleting a user either threw (27 RESTRICT keys) or silently nulled the actor
on 59 relationships, and projects were the same shape. Both now refuse and name the operation the
product already has — deactivating an account, closing a project.

### Sweep 7 — empty states

All 181 pages scanned for a table that renders rows with no "nothing here yet" branch. **7 of 77
list pages had none** and now do, in the in-table shape `Ppe/Index` and `Tasks/Index` already used.
Several are states a brand-new tenant reaches immediately: no shifts, no waste types, a LOTO form
before its first isolation point.

### Sweep 8 — the package × role matrix

`PackageRoleNavigationMatrixTest` parses the real navigation registry, applies the same gates the
sidebar applies, and asks the server for every item it concludes a customer would be shown —
**15 combinations, every navigation entry in each**.

**Two items were offered and refused:** Employee Cases (needs HRD) and Stock Out / Transfer /
Adjustment (needs Warehouse). The cause was structural — navigation had five gates and all of them
ask about a role class or about the plan, so none could express "this page needs a capability". A
sixth gate now names one of the `can_*` booleans the server already publishes.

This is the **fourth** time this codebase has shipped a navigation entry that could only produce a
refusal (v2.98.0 Admin Space, v2.103.0 My Work, v2.103.0 PTW Access in the opposite direction). This
matrix is the sweep that would have caught all four, and it was confirmed to fail when the new gate
is removed.

### Confirmed correct rather than changed

**Project Management, Daily Reports, Milestones and Handover Records are refused on every plan.**
That is the deliberate v2.84.0 retirement (ADR 045), not a fault — navigation correctly offers none
of them. Now asserted on the server side too, because "unreachable" and "broken" look identical from
outside and the difference is the whole point of a retirement.

## Still not covered

Stated plainly so nobody mistakes this note for a clean bill of health. What the second pass
*did* cover is listed above; this is what it did not.

- **Cross-module data flow driven end to end with real records.** The structural half is done —
  every foreign key in the schema was cross-referenced against every delete, which is how the two
  orphaning deletions were found. What has *not* been done is walking a Material Request through
  Purchase Order → Goods Receipt → Warehouse stock, or a Project through Tasks → Work Orders →
  Assets → Maintenance, and checking each handover carries what the next step needs. That needs
  seeded records and a browser, not a sweep.
- **Invalid state transitions and stale UI after mutation.** Dead controls were swept (0 found) and
  deletions were swept (2 found), but "can this record move from submitted straight to closed" and
  "does the list refresh after this dialog" are per-module questions a sweep cannot ask.
- **Responsive layout at phone and tablet widths.** Empty states are done across all 181 pages;
  overflow, clipping and unusable dialogs at narrow widths are not, and need a real viewport.
- **Email and generated-document review.** PDFs were checked for identity and letterhead
  (v2.100.0, v2.102.0) but not read end to end as business documents across every module.
- `nik` is now unique per company (v2.105.0). Whether a NIK should instead be unique across an
  installation is **a product question nobody has asked**, and is the one item here that needs a
  decision rather than work.

## Links

- [[Employee Import Production Defects]] · [[Add-On Billing and Billing Centre]]
- `docs/CONVENTIONS.md` § *shared reference data with no owner*, § *a guard in a shared helper*
- `docs/ARCHITECTURE.md` § *The product's own name for a module*
