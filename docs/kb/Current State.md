---
title: Current State
type: snapshot
product-version: 2.69.0
product-stage: Beta
measured: 2026-09-14
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
| Version | **2.69.0**, stage **Beta**, edition **Enterprise Edition** |
| Build | `2026.09.14.01`, release date `2026-09-14` |
| Stack | Laravel 12 · Inertia.js · React 18 · Tailwind · MySQL · Sanctum |

The naming rules are not cosmetic — see [[Product Identity and Principles]].

## Scale

| Measure | Count |
|---|---|
| Eloquent models | 111 |
| Controllers | 92 |
| Inertia pages | 164 |
| Migrations | 174 |
| Feature test files | 38 |
| Tests / assertions | **353 / 1563**, all passing |
| ADRs | 30 files (numbering has known gaps — see [[Decision Register]]) |
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

`2.69.0` (2026-09-14) — Employee Cases, Material Request aging, and demand consolidation.
See [[Release History]] and ADRs [[030-material-request-lifecycle-and-demand-consolidation|030]]
and [[031-employee-cases|031]].

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
