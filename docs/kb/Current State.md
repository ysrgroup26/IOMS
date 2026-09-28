---
title: Current State
type: snapshot
product-version: 2.82.0
product-stage: Beta
measured: 2026-09-28
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
| Version | **2.82.0**, stage **Beta**, edition **Enterprise Edition** |
| Build | `2026.09.28.01`, release date `2026-09-28` |
| Stack | Laravel 12 · Inertia.js · React 18 · Tailwind · MySQL · Sanctum |

The naming rules are not cosmetic — see [[Product Identity and Principles]].

## Scale

| Measure | Count |
|---|---|
| Eloquent models | 114 |
| Controllers | 105 |
| Inertia pages | 170 |
| Migrations | 183 |
| Feature test files | 64 |
| Tests / assertions | **632 / 3379**, all passing |
| ADRs | 40 files (numbering has known gaps — see [[Decision Register]]) |
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

`platform_admin` is not a tenant role at all: it is the IOMS operator. It is the **role** that makes
someone a platform operator, not a null `tenant_id` — since v2.74.0 an ordinary account can exist
before it has an organization, so `isPlatformAdmin()` reads the role. See ADR
[[038-account-organization-subscription|038]].

## What shipped most recently

`2.82.0` (2026-09-28) — the pricing revamp. Three tiers replace four, each carrying a small
**included** active-user allowance instead of a large hard cap, with extra active users purchasable
at Rp50.000 each per month on every plan. A user is now an **active login account** — not a device,
not an employee record, and not a deactivated account, all three of which are asserted. The annual
offer stopped being one rule: Starter pays 12 for 12, Professional pays 11 for 12 (a discount) and
Business pays 12 for **14** (extra service, which lives in the period because there is no price
saving to derive). Enterprise is retired from sale, not deleted.
ADR [[043-pricing-included-users-and-add-ons|043]].

`2.81.0` (2026-09-27) — the invoice, one address, and the mark. The subscription invoice rendered
through the shared **tenant** letterhead, so it drew the IOMS lockup beside the word IOMS and said
its own name twice; it now has its own header on the navy band customers know from IOMS email, with
the shared partial left untouched because every tenant document depends on it. `www.iomsuite.com`
is 301-redirected to the canonical origin for GET and HEAD — never for a POST, because a redirected
webhook would silently discard a payment. And the official mark was audited against the designer's
master (byte-identical paths, no background) and given the navbar size it was missing.
ADR [[039-public-search-identity|039]] v2.81.0 addendum.

`2.80.0` (2026-09-27) — five backlog tasks, worked by dependency. The grace window and renewal
reminder were **decided** (7 days and H-7, one policy in two numbers). `stateSnapshot()` became the
one place a lifecycle fact is assembled, so the customer and the operator cannot read two answers
about one subscription — the tenant *list* had been showing only the account status column, so a
lapsed customer still read Aktif to support. Billing modes (paid / manual / complimentary) let the
product say who actually pays, without becoming a second entitlement system. Support became a queue
rather than somebody's inbox. And the payment abstraction was audited for a second provider, which
found three real leaks including one in the shared verified-payment path. Master Admin is now
wholly in Bahasa Indonesia. ADRs [[041-billing-mode-is-not-entitlement|041]],
[[042-support-queue-is-not-an-inbox|042]], and the [[033-subscription-lifecycle|033]] addendum ·
[[Project Board]].

`2.79.0` (2026-09-27) — Master Admin became an operations console, and the backlog became a board.
The platform dashboard had been reading the stored `status` column, where lifecycle position is
**derived** on every read, so a subscription whose period ended weeks ago still showed as active and
the one screen meant to surface operational trouble surfaced none of it. Expiring, grace and
read-only now come from the same `lifecycleState()` the customer's Billing page uses, beside payment
activity read provider-agnostically from the data, and operators get a notification feed of their own
business. ADR [[040-master-admin-is-an-operations-console|040]] · [[Project Board]].

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
