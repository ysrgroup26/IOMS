---
title: Current State
type: snapshot
product-version: 2.124.0
product-stage: Beta
measured: 2026-10-18
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
| Public company identity | **IOMS** only (v2.131.0, ADR 071). Operator entity published only when `IOMS_LEGAL_ENTITY` is set; legal merchant **PT Tahada Vistara Bersama** issues invoices (`ioms.merchant.legal_name`) |
| Version | **2.133.0**, stage **Beta**, edition **Enterprise Edition** |
| Build | `2026.10.25.02`, release date `2026-10-25` |
| Stack | Laravel 12 · Inertia.js · React 18 · Tailwind · MySQL · Sanctum |

The naming rules are not cosmetic — see [[Product Identity and Principles]].

## Scale

| Measure | Count |
|---|---|
| Eloquent models | 118 |
| Controllers | 100 |
| Inertia pages | 181 |
| Migrations | 195 |
| Feature test files | 90 |
| Tests / assertions | **1,027 / 5,375**, all passing on in-memory SQLite |
| ADRs | 48 files (numbering has known gaps — see [[Decision Register]]) |
| Controllers | 114 |
| Inertia pages | 184 |
| Migrations | 202 |
| Feature test files | 123 |
| Tests / assertions | **1445 / 7511**, all passing on SQLite and on MySQL 8.0 (see [[Verification Status]]) |
| ADRs | 62 files (numbering has known gaps — see [[Decision Register]]) |
| Workspaces in the navigation registry | 12 |

## Workspaces

The customer-facing product sells **four operational workspaces**: HSE, People / HRD, Logistics /
Warehouse, and Management. Admin Space is tenant administration, not an operational workspace; the
Global Company Dashboard is separate and Business-only. Navigation may retain internal/legacy
registry entries, but they are not marketed as workspaces. What each user reaches remains filtered
by role, enabled modules and tenant grants; navigation never widens access. See [[Data Ownership and
Boundaries]] and [[Final Workspace Architecture]].

| Workspace | Key | Built? |
|---|---|---|
| Workspace | Public role |
|---|---|
| Health, Safety & Environment | Operational workspace |
| People / HRD | Operational workspace |
| Logistics / Warehouse | Operational workspace |
| Management | Operational workspace |
| Admin Space | Tenant administration; not sold as an operational workspace |
| Global Company Dashboard | Business-only, separate from the four workspaces |

## Roles

Six, from `App\Models\User`. The `role` column plus `isX()`/`canX()` methods is still the **live
authorization path**; `spatie/laravel-permission` is installed and tenant-scoped but no controller
has been migrated to it. That is deliberate and recorded — see [[Data Ownership and Boundaries]].

`super_admin` · `hse` · `hrd` · `manager` · `warehouse` · `platform_admin`

`platform_admin` is not a tenant role at all: it is the IOMS operator. It is the **role** that makes
someone a platform operator, not a null `tenant_id` — since v2.74.0 an ordinary account can exist
before it has an organization, so `isPlatformAdmin()` reads the role. See ADR
[[038-account-organization-subscription|038]].

`user_type` is a separate billing and capability class: Full User or My Work User. It does not
replace the role. A My Work User authenticates through the same login and lands in My Work; the
server-side route allow-list still controls what that account may reach. See [[Domain Glossary]].

## What shipped most recently

Release automation (ADR 076, after `2.133.0`): `.github/workflows/deploy.yml` deploys every CI-green
`main` commit to Tencent through a forced-command SSH key and `ioms-ops`; additive migrations no longer
wait for a person. Rehearsed end to end on a replica (and two deploy defects fixed). **Not active on
Tencent until** the owner runs `enable-ci-deploy.sh` once and stores two repository secrets. Whether
v2.133.0 is live on Tencent is **unverified** (no evidence reached GitHub). Runbook:
`docs/runbooks/RELEASE-LOOP.md`.

`2.133.0` (2026-10-25): **Safety Equipment Register** at `/hse/equipment` with a record page per unit,
derived standings (ADR 074), generated IDs, functional QR labels, and Equipment Types that can set
their tracked lifecycles; supplies stay separate. **Release loop** (ADR 075): GitHub Actions CI
(verified green), `ioms-release-watch` (rehearsed with stubs, not installed on Tencent), and a
scheduled remediation session working `deploy-failure` / `production-health` issues. Runbook:
`docs/runbooks/RELEASE-LOOP.md`.

`2.132.0` (2026-10-25): Tencent Lighthouse migration kit, **rehearsed end to end in an Ubuntu 24.04
container, not yet run on the instance**. This environment is denied `43.173.21.31`. `migrate.sh`
orchestrates the move with per-table parity proof. Cloudflare stays the DNS/proxy layer (Origin CA,
Full (strict), lockdown after cutover). Hermes is kept behind `ioms-ops` (allow-list, approval by a
person, append-only audit). `ioms:duitku-check` validates sandbox credentials without creating anything.
The rehearsal fixed eight defects. Cutover awaits the owner's approval. See ADR 072.

`2.131.0` (2026-10-25) — Duitku onboarding (D25218): registration no longer answers HTTP 500 when SMTP
fails (verification and password-reset mail are guarded, and the person is told the email didn't
go); public pages name IOMS only, with the legal merchant kept on invoices; open orders follow the
current catalogue everywhere (the Rp189.000 vs Rp299.000 report), never moving a paid invoice or one
with a live session; the Duitku payment-method list is signed with HMAC-SHA256 and the return URL
honours the caller; mail documented on Zoho SMTP with `ioms:mail-check`; Tencent Lighthouse migration
**prepared, not executed** (`deploy/tencent/`, `docs/runbooks/TENCENT-MIGRATION.md`). Open: verified
business address and phone, sandbox transaction from staging, DNS/DKIM checks, Hermes decision. See
ADR 071.

`2.117.0` (2026-10-13) — Corrective My Work navigation now follows department membership, the Full
User landing preference, or the My Work account class, while route authorization remains unchanged.
The existing Add User selector, class display, quota meters, company-scoped PPE overrides and PTW
examples are retained. Browser verified saving Safety Shoes at six months, adding a PPE type, the
account-class selector, account-class/quota displays, administrator My Work navigation and PTW
examples. SQLite suite, release build and lint results are recorded in [[Verification Status]]. The
live public banner reports v2.116.0, not origin/main's v2.108.0; the public page exposes no commit SHA.
The PPE deactivation click timed out before its outcome could be verified in the browser; server-side
deactivation and safe-delete/history behavior are covered by tests.
`2.116.0` (2026-10-13) — the **HSE Overview visual refinement**, to the approved reference: a dark
industrial header band, five operational KPI cards in a fixed order whose detail line partitions the
value above it, then trend / calendar / notifications, then Accident Classification / Incident
Frequency Rates / Man-Hour, then Quick Access. **TRIR and LTIFR are published for the first time**,
with the numerator, the multiplier, the classifications behind the numerator and the exact man-hours
denominator printed beside them — and refused outright, with the reason, when no hours are logged,
because `0.00` on a site that logged nothing reads as a perfect record. Two of the three reasons the
product had refused these since v1.11.6 had quietly stopped being true. **One real defect fell out of
it:** "Recordable Injuries YTD" counted every injury-category incident, first aid included, under a
label meaning the opposite. The calendar can now schedule through the existing `calendar.store`
route, and the notifications panel reads the per-user feed the top bar already renders. **1308 tests
/ 6678 assertions pass on SQLite and on MySQL 8.0**, and the page was driven in a browser at 1512,
1024 and 390 px. See [[Release History]], [[056-a-rate-is-published-with-its-arithmetic|ADR 056]] and
[[Verification Status]].

`2.115.0` (2026-10-13) — **employee deletion is permanent**, by the owner's decision, and one class
answers for every one of the twenty-four references to an employee rather than leaving it to whichever
`onDelete` rule was written in whichever migration. Records that only describe the person go with
them; records of their own keep themselves and lose the pointer; the two documents that PRINT the
name (a Permit to Work's accountable party, a pre-v1.5.1 Daily Report's filing officer) keep it as a
tombstone written only at erasure. Departments, positions and operating units are never touched. The
confirmation dialogs stopped promising preserved history, because that promise was true of a soft
delete and false of this; the single-record dialog now counts the dependent records from the
database. The audit entry carries a snapshot of who was deleted, since `activity_logs` outlives its
subject. v2.114.0's restore-in-place is superseded and its machinery removed, which left the importer
simpler: one live registry, no restored counter. **1293 tests / 6611 assertions pass on SQLite and on
MySQL 8.0**, and the whole sequence — import 75, delete all, import again, mixed file, permanent
single delete, re-import the freed number — was driven through the real UI (**28/28**). See
[[Release History]], [[055-deleting-an-employee-is-permanent|ADR 055]] and [[Verification Status]].

`2.114.0` (2026-10-12) — the reported import failure, fixed at its cause. Deleting a mis-imported
roster and importing the corrected file refused **every row as a duplicate** while the Employees page
showed nobody: the duplicate check read soft-deleted rows, the list did not, and both were right on
their own terms. A **live** employee number is still refused; a **deleted** one is now RESTORED IN
PLACE, keeping its primary key so its KPI records, man-hours, PPE issues and approvals stay attached
rather than being stranded beside a duplicate. Restorations are counted separately in the preview,
the summary and the audit trail. Separately, an absent or unrecognised Workforce Type was being
stamped `pkwtt` — a legal classification under Indonesian employment law that nothing in the file
said — and is now left unknown and reported. **1273 tests / 6455 assertions pass on SQLite and on
MySQL 8.0**, and the reported sequence was reproduced and then driven through the browser (**22/22**).
See [[Release History]] and [[Verification Status]].

`2.94.0` (2026-10-04) — three real defects in the payment path, found by driving it: a Duitku payment
session was reopened rather than reused on every visit, the gateway reference collided within a
second, and the payment row could settle an invoice but not reconcile one. Amount integrity is now
checked against the session as well as the invoice. **800 tests / 4337 assertions pass.** Sandbox
configuration verified; **no live sandbox transaction was performed** (no credentials on this
machine). See [[Release History]] and `docs/PAYMENTS-DUITKU.md`.

`2.93.0` (2026-10-04) — Master Admin can grant complimentary access to a verified registration:
it provisions that registration through the same body a payment uses, with different commercial
terms. No invoice is raised or marked paid, an unpaid one is voided, and the subscription lapses
normally on its end date. Needed no migration. **766 tests / 4091 assertions pass**, and the flow was
exercised end to end against MySQL in a browser. See [[Release History]] and
[[047-complimentary-access-provisioning|ADR 047]].

`2.92.0` (2026-10-01) — the hero uses the four supplied paragraphs exactly; mobile copy is smaller
and the mobile background uses only the management photograph. The desktop presentation and all
other website sections remain unchanged. **742 tests / 3986 assertions pass.** See [[Release History]].

`2.91.0` (2026-10-01) — the hero is now one full-bleed office-to-field scene with the platform
running across the blend; the mobile dashboard stays hidden. **741 tests / 3978 assertions pass.**
See [[Release History]].

`2.90.0` (2026-10-01) — all browser, Apple and PWA icon variants now come from the owner-supplied
transparent source; the SVG is tightly cropped and the maskable drawing fits the manifest safe
circle without an embedded ground. The installed-app name is exactly `IOMS - Industrial Operations
Platform`. **741 tests / 3978 assertions pass.** See [[Release History]].

`2.89.0` (2026-10-01) — Tahada Group is the public company identity while the legal entity is PT
Tahada Vistara Bersama; the login offers a My Work entry through the existing authentication
system; My Work Users land in My Work by account class; the hero links management and field
operations around the PTW artifact; the domain section uses workshop, warehouse and management
photography in distinct editorial layouts; and the missing trust/security section now follows
pricing with claims tied to enforced product behavior. **741 tests / 3956 assertions pass.** Pricing,
plan scope, entitlement, PTW rules, authentication mechanism, billing and subscription behavior are
unchanged.

`2.88.0` (2026-10-01) — photography integration, responsive WebP pipeline and transparent favicon;
738 tests / 3931 assertions. See [[Release History]].

`2.84.1` (2026-09-29) — Admin Space became a real **context**: exactly one of the workspace
selector and Admin Space is lit at a time, the two operational header links disappear inside it,
and clicking Dashboard no longer drops an administrator into HSE. **Administrative authority left
the operational workspaces** at all three layers, including the routing entry that let Settings be
reached through HSE. Warehouse Logistics is one workspace with one name. The Master Admin Support
500 was reproduced and its actual root cause — a pending migration — is now something the
operations console reports instead of failing silently. And **Duitku is implemented** at the
payment abstraction that was already waiting for it, so a tenant can renew without emailing
billing. ADR [[046-admin-space-is-a-context|046]].

`2.84.0` (2026-09-28) — the final workspace architecture. IOMS is **four operational workspaces**
(HSE, People / HRD, Logistics / Warehouse, Management), **one administrative space** (Admin Space),
and a **Global Company Dashboard that only Business receives** — separate experiences over one set
of shared records. Management stopped asking a question no other workspace asks, which is why it
had been returning 403 to customers who had paid for it. The sidebar’s catch-all state became three
named spaces, so Admin Space no longer reads as one giant application dashboard. And seven
departments left the customer-facing product with their code, routes and data untouched.
ADR [[045-five-spaces-and-the-global-dashboard|045]].

`2.83.0` (2026-09-28) — the Management workspace and Admin Space. The Business tier’s fourth
name finally names something: `management` is a real department workspace that deliberately **owns
nothing**, aggregating every figure from the module that holds it, and stating plainly when a
module has never been used instead of showing a confident zero. Administration became its own
space rather than a drawer inside somebody’s operational day — one new route, every existing form
untouched, and still completely separate from Master Admin. And a **workspace focus** decides where
a person starts without deciding anything about what they may reach.
ADR [[044-management-workspace-and-admin-space|044]].

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
