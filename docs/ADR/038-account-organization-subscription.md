# ADR 038 — Account, Organization and Subscription are three things

**Status:** Accepted and implemented (v2.74.0; public entry points finalised in v2.74.2).
**Date:** 2026-09-20
**Amends:** ADR 008 (Tenancy Foundation) — specifically, what `tenant_id IS NULL` means.
**Supersedes:** the v2.51.0 pay-first onboarding form, **removed** in v2.74.2. Its payment, webhook
and provisioning half is untouched and still runs every order.

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
GET STARTED = ACCOUNT REGISTRATION
Landing → Get Started → Create account → "Continue setup" or "Maybe later"
        ├─ Maybe later     → Account area. Nothing else is created.
        └─ Continue setup  → Subscription setup → Payment
                           → (verified webhook) → Subscription Active

CONTINUE SETUP / CHOOSE A PLAN = ACCOUNT + COMPANY + PLAN
Login   → subscribed account   → the full product
        → unsubscribed account → Account area → "Choose a plan"
                               → the SAME subscription setup page
```

**The distinction, in one line each:**

| | Asks for | Creates |
|---|---|---|
| **Get Started** | full name, email, password, consent (or Google) | a person |
| **Continue setup / Choose a plan** | *account stated*, company, plan, cycle | an order |

## Get Started creates an account, and nothing else

`/get-started` collects **four things** — full name, email, password, consent — plus *Continue with
Google* where the deployment has credentials for it. No company fields, no plan cards, no billing
toggle, no prices, no payment. It redirects to `/register`, so there is one account form at one
canonical URL rather than two copies drifting apart.

This is the half v2.74.0 left undone. It separated the three concepts in the data model and in the
middleware, and then left the public page still asking a stranger for their company's legal name,
full postal address and billing cycle before they had an account at all — which is precisely the
thing the release existed to stop doing.

A visitor who arrives from a plan card carries `?plan=` and `?cycle=`. That is remembered in the
**session**, not pushed through the account form: it is not an account field, and a form that asks
for four things must not quietly grow a fifth. `SubscribeController::setup()` reads it back, so
"Choose Starter" on Pricing still lands on Starter three screens later.

`GetStartedIsAccountRegistrationTest` pins this. It asserts absence at **two** levels — the props
the server sends, and the component source — because either alone is escapable: a props assertion
passes on fields hard-coded into the markup, and a source assertion passes if some other component
starts being rendered.

### The pay-first endpoint is gone, not merely unlinked

`POST /get-started` (`register.store`) created a `TenantRegistration` carrying a hashed password
and no `users` row. Nothing renders that form any more, and a public endpoint that creates accounts
and orders with no page able to reach it is a surface nobody looks at. It is deleted, and a test
asserts the route name no longer resolves.

Provisioning still supports a null `user_id`, because an order raised before this release must
still be able to complete. So do `verify`, `resend`, `status`, `invoice`, `pay` and `checkout` —
they are the ORDER half of the flow, shared by both eras, and every order still ends on them.

## One setup form, and one way to reach it

**There is exactly one subscription setup UI** — Your account, Your company, Your plan, Continue to
payment. `SubscribeController` renders it, from two entry points that are the same screen:
*Continue setup* straight after registration, and *Choose a plan* from the Account area later.

An earlier cut of this flow built a parallel four-step wizard under `Pages/Subscribe/` instead —
its own plan cards, its own company fields, its own order summary. That was the wrong instinct, and
the corrected design is worth stating explicitly because the shortcut is so easy to reach for.

Two forms selling the same product drift. A field gets added to one, a price
format corrected in the other, a required rule relaxed in a third place, and
which version a customer sees depends on which door they came through. There is
no mechanism that keeps them honest, and no test that fails when they diverge —
only a bug report months later from the half nobody exercises.

**`account` is not optional.** "Your account" **states** the name and email rather than collecting
them, and there are no password fields — that credential already exists on the `users` row. The
anonymous variant of that section was **deleted** along with the endpoint it posted to, rather than
left unreachable: dead form fields are the ones that come back.

`SubscribeFlowTest::test_subscription_setup_is_one_form_that_states_the_account` pins it, so a
future "quick parallel page" fails the suite rather than shipping.

The component still lives at `Pages/Public/GetStarted.jsx` and **is no longer the Get Started
page**. It keeps the file name because that is what everyone involved still calls this screen, and
because renaming it would churn a component string, a route and a test to fix a word.

The account's name and email are read-only on the page **and** ignored by the
server — the same property stated twice on purpose: the page sends them because
the order record carries them, and `SubscribeController::store()` does not
validate or read them at all. A tampered field cannot raise an order in somebody
else's name.

## Registration ends on a fork, not in a branch

Sign-up redirects to `register.welcome` — one screen offering **Continue setup**
or **Maybe later**, with both as real buttons.

Neither redirect alone is right. Straight to plan selection is the credit-card-at-
the-door mistake this whole release exists to stop making. Straight to the Account
area — which is what v2.74.0 did — is the opposite failure: an account that
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

- `/get-started` is account registration. If it ever shows a price again, something has gone
  wrong — `GetStartedIsAccountRegistrationTest` should have failed first.
- `Pages/Subscribe/` does not exist. If it reappears, something has gone wrong — see *One setup
  form, and one way to reach it* above.
- The pay-first flow cannot be started. Orders raised before v2.74.2 still complete normally.
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
