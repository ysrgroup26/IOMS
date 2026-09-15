---
title: Pricing Plans and Entitlements
type: reference
updated: 2026-09-15
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
can activate or extend a subscription** — that happens server-side on a signature-verified webhook,
and `PublicReadinessTest` and `SubscriptionLifecycleTest` both pin that boundary.

The one other path that can settle an invoice is a Platform Admin recording a bank transfer, under
their own audited identity. It runs the identical lifecycle code, so the gateway path and the manual
path cannot diverge.

Invoices exist as real documents because Indonesian B2B customers file them. Each one carries a
`purpose` (`onboarding` / `renewal` / `plan_change`) and, for a plan change, the target package and
cycle — so a verified payment can tell what it bought without inferring it from which foreign keys
happen to be null.

**IOMS bills invoice-per-cycle. There is no automatic card charging.** A `recurring_enabled` flag
used to promise it; nothing implemented it, so it was removed in v2.70.0 rather than left as a trap.

> [!note] Live payments still need external configuration
> The integration is built; going live requires provider credentials and configuration outside this
> repository. See [[Known Issues and Limitations]] and `ROADMAP.md`.

---

## The subscription lifecycle (v2.70.0)

Full reasoning in ADR [[033-subscription-lifecycle|033]]. The parts that change how you read the
code:

### Stored status vs derived standing

`subscriptions.status` carries only what was **decided** — `trial`, `active`, `suspended`,
`cancelled`. Where the subscription sits in **time** is derived on every read by
`Subscription::lifecycleState()` from the dates already on the row.

`expired` and `grace_period` are gone from the vocabulary. They were stored values that **nothing
ever wrote**, so a subscription four months past its end date reported itself as `active`.

> [!important] A stopped scheduler cannot lock anyone out
> Because standing is derived, access is correct whether `subscriptions:lifecycle` ran last night or
> has never run. A missed cron delays an invoice and a reminder. It cannot withdraw access, and it
> cannot grant it.

### What each state permits

| Standing | Reads | Writes |
|---|---|---|
| `active` — inside the paid period | ✅ | ✅ |
| `grace` — past it, within `saas.grace_days` (14) | ✅ | ✅, warned |
| `lapsed` — past grace | ✅ | ❌ |
| `suspended` / `cancelled` — a deliberate operator act | ❌ | ❌ |

> [!important] Expiry is read-only, never a lockout, and never destructive
> IOMS holds the permits, incident reports and training expiry an organization produces for a
> regulator or after somebody is hurt. Withholding those over a late invoice would turn a billing
> dispute into a safety and legal problem. Enforced by `EnforceSubscriptionWriteAccess`, which
> allow-lists paying, session and credential routes, and marking one's own notification read.

### Renewal, and the arithmetic that matters

`subscriptions:lifecycle` (daily, 02:00) issues the next invoice inside `saas.renewal_lead_days`,
emails it, reminds once a day through grace, and applies plan changes waiting on a period boundary.

**Every extension is `max(current period end, now) + one cycle`.** Renewing early keeps the days
already paid for; renewing late does not sell a month nobody could use. `now + cycle` is the classic
bug here and it quietly steals from whichever party it rounds against.

### Plan changes

- **Upgrade** — prorated invoice for the rest of the period, applied when paid. Never negative.
- **Downgrade or cycle change** — recorded in `pending_package_id` and applied at the boundary. A
  downgrade below current seats or operating units is **refused**, naming the number to reduce.
- A plan change re-agrees the price and re-syncs grants; renewing on the same plan does neither,
  which is what preserves a grandfathered price (v2.60.0).

> [!warning] Grant sync can remove — but only from a deliberate plan change
> `SubscriptionLifecycleService::syncGrantsToPackage()` is a true `sync()`. `tenants:sync-grants`
> stays additive on purpose: a safety net must never be able to take capability away by accident.

### A payment buys time, not reinstatement

A suspended or cancelled subscription cannot issue itself a renewal invoice, and settling one by
another route extends the period while leaving the status alone. Lifting a suspension is the
platform operator's decision; money must not overturn it. `trial` → `active` is the one status
change a payment legitimately makes.

### `tenants.status` is the ACCOUNT switch, and is now enforced

Separate question from the subscription: is the **account** open (abuse, legal hold, deliberate
shutdown), versus what the **commercial arrangement** says. Either closing is enough.
`EntitlementService::tenantIsUsable()` reads both. Before v2.70.0 the Platform Admin suspend control
wrote a column nothing read, so suspending a customer did nothing at all.

---

See also: [[Product Strategy and Positioning]] · [[Data Ownership and Boundaries]] · [[Modules and Capabilities]]
