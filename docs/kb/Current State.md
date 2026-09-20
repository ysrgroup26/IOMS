---
title: Current State
type: snapshot
product-version: 2.74.0
product-stage: Beta
measured: 2026-09-20
tags: [kb/state]
---

# Current State

A snapshot of what IOMS actually is right now. Everything here was measured from the repository on
the date in the frontmatter, not remembered.

> [!note] How to re-measure
> Version comes from `config/ioms.php` (`version`, `stage`, `build`), which is the single source of
> truth for it. The counts come from the commands in the footnote at the bottom. If you are reading
> this long after `measured:`, re-run them rather than trusting the numbers.

---

## Identity

| | |
|---|---|
| Product | **IOMS — Industrial Operations Platform** |
| Version | **2.74.0**, stage **Beta**, edition **Enterprise Edition** |
| Build | `2026.09.20.02`, release date `2026-09-20` |
| Stack | Laravel 12 · Inertia.js · React 18 · Tailwind · MySQL · Sanctum |

The naming rules are not cosmetic — see [[Product Identity and Principles]].

## Scale

| Measure | Count |
|---|---|
| Eloquent models | 112 |
| Controllers | 105 |
| Inertia pages | 173 |
| Migrations | 180 |
| Feature test files | 48 |
| Tests / assertions | **460 / 1939**, all passing |
| ADRs | 36 files (numbering has known gaps — see [[Decision Register]]) |
| Workspaces in the navigation registry | 12 |

## Workspaces

Ten department workspaces plus two global ones, from `resources/js/lib/workspaces.js`. What a user
actually sees is filtered by role, enabled modules and tenant grants — navigation never widens
access. See [[Data Ownership and Boundaries]].

| Workspace | Key | Built? |
|---|---|---|
| Human Resources | `hr` | Yes |
| Health, Safety & Environment | `hse` | Yes — the deepest module set |
| Project Management | `project-management` | Yes |
| Logistics / PPIC | `logistics` | Yes |
| Warehouse | `warehouse` | Yes |
| Procurement | `procurement` | Yes |
| Asset Management | `asset-management` | Yes |
| Maintenance | `maintenance` | Yes |
| Quality Control | `quality-control` | Yes |
| Finance | `finance` | **Placeholder** — routes to a Coming Soon page |
| Reports | `reports` | Yes (global tier) |
| Administration | `administration` | Yes (global tier) |

Several built workspaces still carry individual `disabled: true` placeholder items (HR has 5, e.g.
Recruitment and Performance). A disabled item is an advertised intention, not a capability.

## Roles

Six, from `App\Models\User`. The `role` column plus `isX()`/`canX()` methods is still the **live
authorization path**; `spatie/laravel-permission` is installed and tenant-scoped but no controller
has been migrated to it. That is deliberate and recorded — see [[Data Ownership and Boundaries]].

`super_admin` · `hse` · `hrd` · `manager` · `warehouse` · `platform_admin`

`platform_admin` is not a tenant role at all: it is the IOMS operator, with `tenant_id = null`.

## What shipped most recently

`2.71.0` (2026-09-16) — capability reach and navigation hierarchy. Material Request was unreachable
for HSE on the two plans that sell HSE; the sidebar's scroll reset on every navigation and its group
headers rendered smaller than their own children; and nothing said which screens configure the system
versus record daily work. ADR [[034-capability-reach-and-navigation-hierarchy|034]].

> [!important] The invariant worth carrying forward
> **A capability must be reachable by whoever owns it.** IOMS answers "may this person use this
> module" in three places — `User::canManageX()`, `config/departments.php`, `config/plans.php` — and
> when they disagree, the routing and entitlement layers become stricter than the permission they
> defer to. Found three times now (`permits-to-work`, `man-hour`, `material-requests`);
> `DepartmentCapabilityReachTest` now asserts it.

`2.70.0` (2026-09-15) — the subscription lifecycle, completed. Renewal invoices, a read-only lapse
instead of a lockout, self-service plan changes, and five controls that claimed to work and did not.
See [[Release History]] and ADR [[033-subscription-lifecycle|033]].

> [!important] The one fact to carry forward
> **Subscription expiry never removes data and never blocks reading.** Fourteen days of full access,
> then new records are paused and everything already recorded stays readable, searchable and
> exportable. Subscription state governs *access*; it is not a lifecycle for the customer's system
> of record. Asserted by test, not assumed.

Where a subscription sits in time (`active` / `grace` / `lapsed`) is **derived on every read**, not
stored — so a stopped scheduler delays an invoice and cannot lock anyone out. See
[[Pricing Plans and Entitlements]].

## What is deliberately not being worked on

The largest open item is the **two-zone navigation model** — designed, documented, and *not* built
on purpose. The reasoning is in [[Requirements Register]] and `UX_ARCHITECTURE_DISCOVERY.md` §22.3.
Do not start it casually; it is a shell restructure justified by one reported experience.

---

## Footnote — the commands behind the counts

```bash
grep -E "^\s*'(version|stage|build|release_date)' =>" config/ioms.php
ls app/Models/*.php | wc -l
ls app/Http/Controllers/*.php | wc -l
find resources/js/Pages -name '*.jsx' | wc -l
ls database/migrations/*.php | wc -l
php vendor/bin/phpunit          # PHP lives under Laragon; see LOCAL-VERIFICATION.md
```

---

See also: [[Modules and Capabilities]] · [[Known Issues and Limitations]] · [[Verification Status]]
