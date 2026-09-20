# ADR 038 — Account, Organization and Subscription are three things

**Status:** Accepted and implemented (v2.74.0).
**Date:** 2026-09-20
**Amends:** ADR 008 (Tenancy Foundation) — specifically, what `tenant_id IS NULL` means.
**Related:** the v2.51.0 self-service onboarding flow, which remains in place unchanged.

---

## The decision

```
Account        a person's login identity          users
Organization   the customer's business context    tenants + companies
Subscription   the commercial entitlement         subscriptions
```

**An IOMS account can exist on its own.** Registering creates a person — no tenant, no company, no
operating unit, no subscription, no payment. The account lands in its own Account area and chooses a
plan if and when it wants to.

```
Landing → Sign Up → Account created → Account area
        → (whenever they decide) → Choose Plan → Organization Details
        → Order Summary → Payment → (verified webhook) → Subscription Active
```

## What it was before

There was no such thing as an IOMS account on its own. `/get-started` collected identity, company
legal name, full address, plan and password in **one form**, stored it as a `TenantRegistration`, and
the `users` row was not created until `TenantProvisioningService` ran — which only runs after a
payment the server verified. **You could not sign in before you had paid, because there was nothing
to sign in to.**

That is a coherent design for a pay-first product. It is the wrong one for a product that wants
people to create an account, look around, and decide.

## The keystone: what `tenant_id IS NULL` means

`User::isPlatformAdmin()` was `is_null($this->tenant_id)`.

An unsubscribed account has no tenant *by design*. Under the old definition **every new signup would
have been a Platform Super Admin with cross-tenant reach** — the single most dangerous thing this
change could have done, and not an obvious one, because nothing in the registration code mentions
platform admin at all.

The fix is one line, and everything else in this release depends on it:

```php
// was: return is_null($this->tenant_id);
return $this->role === self::ROLE_PLATFORM_ADMIN;
```

**Verified behaviour-preserving before it was made.** Every null-tenant user in the database already
carried `role = 'platform_admin'`, and no tenant user carried it — the two definitions agreed
exactly. The owning migration also backfills the role for any null-tenant user that lacks it, so the
property holds on every deployment rather than only on the inspected one.

`hasNoOrganization()` is the new question: *no tenant **and** not the platform operator*.

## The guard, and why it is in the global stack

`RequireOrganization` blocks everything by default and allows a short list of route-name prefixes.
It runs in the global `web` stack, not on a route group.

That is deliberate, and it is a judgement about *this* repository rather than a general principle.
A route-group guard protects exactly the routes somebody remembered to put in the group. This
codebase has a documented production outage from getting group membership wrong (v1.9.x, Material
Request nested inside `role:super_admin`) and **three** separate incidents of a new route prefix
being missed in `config/departments.php` — the third of which happened during this very release.
Membership lists are not reliably maintained here. A fail-closed allow-list protects a route written
tomorrow without anybody remembering anything.

It is also placed **before** `EnforceTenantEntitlement`, which explicitly lets a null-tenant user
straight through (it was written when null tenant meant "platform operator"). Without that ordering
an unsubscribed account would sail past every commercial check.

## Authentication

Two paths, one account model.

**Email + password** → verification email → confirmed. The account is signed in immediately on
registration; the unverified state is enforced by middleware, not by withholding the session.

**Continue with Google**, via `laravel/socialite`. Chosen over a hand-rolled flow because the
identity must be established by Google server-side: a real authorization-code redirect, `state`
checked against the session, and a back-channel token exchange with the client secret before any
claim about who the user is is believed. Hand-rolling OAuth is a well-known way to get a subtle
detail wrong that nobody notices until it matters.

### Account linking

| Case | Resolution |
|---|---|
| `google_id` already known | That account. Google's `sub` is stable even if the address is renamed. |
| Email matches an existing account **and Google says it is verified** | Link, and mark the email verified. |
| Email matches but Google says **unverified** | **Refused.** |
| Neither | Create the identity — nothing else. |

The third row is the one that matters. An unverified Google address must never take over an IOMS
account whose password somebody else knows; that is a full account takeover through a self-asserted
address. `email_verified` from Google's token response is required, and its absence is read as
`false`.

A Google-authenticated account gets **no IOMS verification email** — Google has already proved
control of the address — and its `password` stays genuinely `null` rather than a placeholder hash.
A placeholder would look like a credential, count as one in an audit, and make "does this account
have a password?" unanswerable. `google_id` is deliberately **not** mass-assignable.

## What email verification gates

Exactly one thing: **the subscribe flow.** Buying against an address nobody has confirmed means the
invoice and the activation notice go nowhere.

It deliberately does *not* gate signing in (you would be locked out of the page telling you to
verify), nor existing operational tenant users — every user predating v2.74.0 is unverified, because
`TenantProvisioningService` never set the column, so gating the product on it would lock out every
existing customer overnight to solve a problem none of them have.

## The subscribe flow reuses the payment path unchanged

`SubscribeController` creates an **order** (`TenantRegistration`) and hands off to the existing
`register.checkout` → `register.pay` → `PaymentWebhookController` →
`TenantProvisioningService::activate()` path. Nothing about payment, idempotency, webhook signature
verification or the subscription state machine changed.

There must be exactly one way a tenant comes into existence, and re-implementing it here would mean
two — the second one weaker.

**`user_id` on the order is the whole of the backend change.** Set at creation, it tells provisioning
to *attach* the existing account (promoting it to Super Admin of the tenant it paid for) rather than
create a second user. Null — the legacy public flow — still creates one. Both paths end with exactly
one Super Admin on exactly one tenant.

The flow never re-asks for identity: `contact_name` and `contact_email` are read from the session,
never from the request, so a form field cannot raise an order in somebody else's name.

## Consequences

- `/get-started` (pay-first, no account) is untouched and still works. Two doors, one destination.
- The Account area is deliberately **not** inside `AuthenticatedLayout`: the workspace switcher and
  department rail are meaningless without a tenant, and rendering them around an empty product is
  exactly the "looks broken" failure the page exists to avoid.
- `users.password` and `tenant_registrations.password` became nullable and are **not** restored on
  rollback — by then there may be Google-only accounts whose password genuinely is null.

## Remaining external configuration

Google sign-in is **architecturally complete and untestable in this environment**. It needs, on each
deployment:

```
GOOGLE_CLIENT_ID=…
GOOGLE_CLIENT_SECRET=…
GOOGLE_REDIRECT_URI=https://<host>/auth/google/callback   # optional; defaults from APP_URL
```

and that exact redirect URI registered as an *Authorised redirect URI* on the Google Cloud OAuth
client. Without them `GoogleAuthController::configured()` returns false, the button is not rendered,
and both routes 404. **No Google credential is committed, and the OAuth flow has not been executed
end to end** — see `docs/kb/Verification Status`.

`laravel/socialite` is a new Composer dependency; a deployment must run `composer install`.

## What would make this ADR wrong

If IOMS later needs one person to hold accounts in several organizations, the `users.tenant_id`
column becomes the constraint — the split above would still be right, but membership would need to
become its own table rather than a column. That is a different ADR, and nothing here forecloses it.
