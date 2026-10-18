# ADR 061 — An offer is bound to what the server allows

- **Status:** Accepted
- **Date:** 2026-10-18
- **Version:** 2.122.0
- **Relates to:** ADR 045 (five spaces), ADR 046 (Admin Space is a context), ADR 038 (account / organization / subscription), ADR 103-era navigation gates (v2.98.0, v2.103.0)

## Context

The owner reported that a **My Work account still cannot be created**, after several rounds of
corrective work. The first job was to find out what "cannot be created" actually meant, by driving
the application rather than reading it.

A fresh MySQL 8 database was provisioned with two tenants — one Starter, one Business, each holding
only the workspaces its plan grants — and the account was created the way a customer creates one:
Settings → Users & Access → Add User, in a browser.

**Creation succeeded.** The POST returned 302, the account appeared in the list, and it signed in
with its own credentials and landed on `/my-work`. So the report was not literally true, and three
earlier "fixed" claims about creation were not the explanation either.

What was true is everything around creation:

1. **Every account this form has ever created was stored with `company_id` NULL.** The dialog never
   sent an operating unit and `storeUser()` never set one, so the account belonged to a tenant but
   to no unit. On a single-unit tenant much of the product falls back to "the only company", which
   is why this survived; anything reading `$user->company_id` directly simply got null.

2. **Six of the eight links the account's own sidebar offered answered 403.** A My Work account fell
   through to the Global Company rail — Dashboard, My Work, Calendar, Work Center, Reports,
   Analytics, Report Center, Document Library — and `RestrictMyWorkUser` refuses all but the first
   two. The "My Tasks" tile on My Work itself pointed at Work Center (403), the attention banner
   pointed at Work Center (403), the global search box queried `search` (403 on every keystroke),
   and My PTW offered a "New PTW" button to an account with no PTW Access (403).

3. **A seat-quota refusal was `abort(422)`**, which Inertia renders as a bare error modal with
   nothing attached to the field that caused it. An administrator out of My Work seats saw a wall of
   text and no indication that the account class was the thing to change.

From the person holding the account, (2) **is** "the account cannot be used". The defect was never
in creation; it was that nothing the account was offered worked.

Crawling every link each account is actually offered, on both tenants, then found the same shape
elsewhere, for **Full** accounts and for tenant administrators:

- A Business administrator was offered Milestone, Purchase Requisition and Purchase Order; a Starter
  administrator was offered Employee Cases, and the employee profile's "Lihat riwayat kasus" link.
  All four answer *"Fitur ini belum tersedia untuk paket perusahaan Anda"* — the plan, not the role.
- An HRD Full User was offered the dashboard's Operating Units card, which links into Settings:
  gated on department confinement, never on role.

## Decision

**Anything that offers a link asks the same question the gate that would refuse it asks, from the
same source.** Not a copy of the answer — the source.

Concretely:

- `RestrictMyWorkUser::ALLOWED_PREFIXES` is **public**, exposed through
  `HandleInertiaRequests` as `auth.user.allowed_route_prefixes` (for that class only), and
  `workspaces.js` filters the rail through it. A My Work account gets its own short rail — My Work,
  My Permits, Tasks — and each entry is filtered through that same list, so adding a prefix to the
  middleware is what makes a link appear, and nothing else is.
- `DashboardController::myWork()` tags every tile with its route name and filters the list through
  the same constant, so a tile added later cannot reintroduce a dead card. The My Tasks tile points
  at Tasks for this class and at Work Center for every other.
- `EntitlementService::tenantCanUseRoutePrefix()` reproduces, **once**, the rule
  `EnforceTenantEntitlement` applies: a prefix listed in `config('departments')` is allowed when the
  tenant holds any workspace that lists it; a prefix nobody lists is not plan-gated. Work Center's
  quick actions and action alerts, and the employee profile's case card, ask it.
- A quota refusal is a **validation error on the field that caused it** (`user_type`), carrying the
  used and permitted figures, not an HTTP abort.
- `storeUser()` accepts and defaults `company_id` (the administrator's own unit, validated with
  `InCurrentTenant`), and marks the account's email verified: the administrator sets the password,
  so nobody ever clicks a verification link for such an account, and it otherwise sat in Admin
  Space's "unverified email" count forever.

## Consequences

- **No authorization changes.** Every gate named here was already the boundary and still is; what
  changed is that the interface stops advertising what the boundary refuses. This is the same
  reasoning v2.98.0 and v2.103.0 applied to two navigation gates, generalised: those fixed the
  *answers*, this binds the *question* to one source.
- **A new route is invisible to a My Work account until someone adds its prefix.** That is the
  allow-list's own failure mode and is deliberate — a missing link is visible and fixable, a
  silently granted capability is neither.
- **A plan-gated quick action disappears rather than failing.** A tenant whose plan gains the
  workspace sees it return with no code change.
- `tests/Feature/MyWorkAccountCreationTest.php` pins creation, sign-in, the unit, the verified mark,
  the quota message's field, and that every My Work tile points inside the allow-list.
  `tests/Feature/PlanScopedActionOffersTest.php` pins the quick actions and alerts against **all
  four plans**.

## Evidence

Browser-verified against MySQL 8, crawling every link each account is offered and recording the real
HTTP answer:

| Account | Before | After |
|---|---|---|
| My Work (Starter) | 8 links offered, 6 × 403 | 3 links offered, 0 × 403 |
| My Work (Business) | 8 links offered, 6 × 403 | 3 links offered, 0 × 403 |
| My Work + PTW Access | — | 6 links, 0 × 403 (permit form opens) |
| Full HRD (Business) | 1 × 403 of 84 pages | 0 × 403 of 83 |
| Administrator (Starter) | 1 × 403 of 78 | 0 × 403 of 77 |
| Administrator (Business) | 3 × 403 of 108 | 0 × 403 of 105 |

(The remaining non-200 entries in every run are file downloads, where the browser aborts the
navigation rather than the server refusing it.)

## What this does NOT claim

It does not claim production is fixed. Per ADR 057, that question is answered by comparing the
signature `app:deploy` prints with the one Master Admin → Running Release prints, and by nothing
else.
