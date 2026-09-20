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
Landing → Sign Up → Account created → "Continue setup" or "Maybe later"
        ├─ Maybe later     → Account area. Nothing else is created.
        └─ Continue setup  → Subscription setup → Payment
                           → (verified webhook) → Subscription Active

Later:  Login → Account area → "Choose a plan" → the SAME setup page
```

## One setup form, two entry points

**There is exactly one subscription setup UI: the page `/get-started`
renders** — Your account, Your company, Your plan, Continue to payment. `/subscribe`
renders that same page.

The first implementation of this release did not do that. It built a parallel
four-step wizard under `Pages/Subscribe/` — its own plan cards, its own company
fields, its own order summary — for the signed-in case. That was the wrong
instinct, and the corrected design is worth stating explicitly because the
shortcut is so easy to reach for.

Two forms selling the same product drift. A field gets added to one, a price
format corrected in the other, a required rule relaxed in a third place, and
which version a customer sees depends on which door they came through. There is
no mechanism that keeps them honest, and no test that fails when they diverge —
only a bug report months later from the half nobody exercises.

**The difference between the doors is one prop.** `account` is non-null when a
signed-in account opens the page, and the page responds by doing exactly two
things differently:

| | Public (`/get-started`) | Account (`/subscribe`) |
|---|---|---|
| Your account | collects name, email, password | **states** name and email; no password |
| Submits to | `register.store` | `subscribe.store` |

Everything after that — company fields, industry list, plan cards, billing
toggle, terms, price, order page, checkout — is the same code running twice.
`SubscribeFlowTest::test_the_subscribe_page_is_the_same_form_as_get_started`
pins it by asserting the component name, so a future "quick parallel page" fails
the suite rather than shipping.

The account's name and email are **read-only on the page and ignored by the
server**, which is the same property stated twice on purpose: the page pre-fills
them so the form shape is unchanged, and `SubscribeController::store()` does not
validate or read them at all. A tampered field cannot raise an order in somebody
else's name.

## Registration ends on a fork, not in a branch

Sign-up redirects to `register.welcome` — one screen offering **Continue setup**
or **Maybe later**, with both as real buttons.

Neither redirect alone is right. Straight to plan selection is the credit-card-at-
the-door mistake this whole release exists to stop making. Straight to the Account
area — which is what the first cut did — is the opposite failure: an account that
has just been created has no organization, no data and no subscription, so the page
it lands on is almost entirely empty. Nothing is broken, but it reads as though
something is, and the new account has no idea what to do next.

One screen answers the only question they have, and "Maybe later" has to be a
proper button rather than small print, because a product that lets you create an
account without buying has to mean it.

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
`register.status` → `register.checkout` → `register.pay` → `PaymentWebhookController` →
`TenantProvisioningService::activate()` path. Nothing about payment, idempotency, webhook signature
verification or the subscription state machine changed.

`register.status` is the same order page the public flow ends on, for the same reason the form is
the same form: it already states what is being bought, for which organization and for exactly how
much, and its button already posts to checkout. A second one would be a second thing to maintain.

There must be exactly one way a tenant comes into existence, and re-implementing it here would mean
two — the second one weaker.

**`user_id` on the order is the whole of the backend change.** Set at creation, it tells provisioning
to *attach* the existing account (promoting it to Super Admin of the tenant it paid for) rather than
create a second user. Null — the legacy public flow — still creates one. Both paths end with exactly
one Super Admin on exactly one tenant.

The flow never re-asks for identity: `contact_name` and `contact_email` are read from the session,
never from the request, so a form field cannot raise an order in somebody else's name.

## Consequences

- `/get-started` (pay-first, no account) is untouched and still works. Two doors, one form, one
  destination.
- `Pages/Subscribe/` does not exist. If it reappears, something has gone wrong — see *One setup
  form, two entry points* above.
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
