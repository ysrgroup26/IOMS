---
title: Pricing Plans and Entitlements
type: reference
updated: 2026-09-14
tags: [kb/commercial]
---

# Pricing Plans and Entitlements

What the four plans contain, and what actually stops a tenant using something they have not bought.

**Authoritative sources:** the `packages` table (established by the v2.60.0 migration, not a
seeder), `PricingService`, and `ARCHITECTURE.md` §SaaS Entitlement chain.

---

## The four tiers

Prices are IDR, from the `packages` table on 2026-09-14.

| Tier | Monthly | Yearly | Users | Operating Units | Adds |
|---|---:|---:|---|---|---|
| **Starter** | 299,000 | 2,990,000 | 10 | 1 | HSE, complete |
| **Professional** | 799,000 | 7,990,000 | 50 | 2 | + People and Workforce |
| **Business** | 1,499,000 | 14,990,000 | 150 | 4 | + Project Management, Logistics / PPIC, Procurement |
| **Enterprise** | 2,499,000 | 24,990,000 | unlimited | unlimited | Every operational department |

- **Annual is monthly × 10** on every tier. No percentage is hardcoded anywhere — `PricingService`
  derives the saving from the plan's own two prices, so the displayed figure cannot drift from the
  charged one.
- **`NULL` means unlimited** on `max_users` and `max_companies` — the long-standing convention on
  those columns.
- **There is no free trial.** `trial_days` is normalised to null on every tier; an earlier catalogue
  carried 14 days on Professional and the public Plans page was advertising it.
- **Enterprise is not sold on its module list.** It adds only a handful of route prefixes over
  Business; what it sells is unlimited capacity plus per-unit authorization and legal-entity
  structures. See [[Product Strategy and Positioning]].

> [!warning] Price changes belong in a migration, not a seeder
> `db:seed` does not re-run on an existing deployment. v2.51.0 shipped a whole release where
> production still showed the previous prices because only the seeder had been changed. Recorded in
> `CONVENTIONS.md`.

---

## How entitlement is actually enforced

Three independent limits, each with a different mechanism:

### 1. Workspace and module grants

A tenant holds grant rows for the workspaces and modules its plan includes.
`EnforceTenantEntitlement` middleware checks them per request, before department scoping.

> [!important] Zero grants means **allowed**, not denied
> A tenant with no grant rows at all is treated as fully allowed. This is deliberate and is what
> makes enabling enforcement safe: a tenant that predates the feature and was never explicitly
> provisioned is **structurally impossible to lock out**. A tenant with at least one grant row goes
> through the real allow-list.

`php artisan tenants:sync-grants {tenant?} {--dry-run}` tops a partially-granted tenant up to its
package's baseline. It uses `syncWithoutDetaching()` and **can never remove a grant**, so it cannot
be used to downgrade anyone. Run `--dry-run` first after any deploy that changes the enforcement
flag.

Overridable per install with `SAAS_ENFORCE_WORKSPACE_ENTITLEMENT=false`.

### 2. Operating-unit capacity

`packages.max_companies`, enforced by `EntitlementService::canCreateOperatingUnit()` inside a
**row-locked transaction**. Before v2.54.0 the number was displayed but never enforced.

The usage count deliberately **bypasses global scopes** — a quota is a property of the organization,
never of the person asking.

### 3. Seat capacity

`packages.max_users`, enforced on user creation. Covered by `UserSeatQuotaTest`.

---

## The Entitlement Dependency Rule

> A module must **never** gate on another *paid* module purely because it shares core data with it.

`Employee` is core data shared by both HSE and HR. PPE (an HSE module) correctly depends only on
`Employee`, never on HR being enabled — otherwise a Starter tenant, which has no HR seat at all,
could not use a module it had paid for.

The one violation found in this codebase — `canManageManHour()` requiring `isHrd()` for genuinely
shared HSE + HR data — was fixed in v2.1.0.

---

## Denial behaviour

A blocked tenant sees the styled error page with an explanation in Indonesian (e.g.
*"Fitur ini belum tersedia untuk paket perusahaan Anda."*), matching the language hierarchy's
assignment of explanatory prose. Those authored messages survive the error presenter's filtering by
design — pinned by `ErrorPagePresentationTest`.

## Payments

Checkout is IOMS's own page; the provider supplies the payment interface. **Nothing the browser does
can activate a subscription** — activation is server-side on a signature-verified webhook, and
`PublicReadinessTest` pins that boundary.

Invoices exist as real documents because Indonesian B2B customers file them.

> [!note] Live payments still need external configuration
> The integration is built; going live requires provider credentials and configuration outside this
> repository. See [[Known Issues and Limitations]] and `ROADMAP.md`.

---

See also: [[Product Strategy and Positioning]] · [[Data Ownership and Boundaries]] · [[Modules and Capabilities]]
