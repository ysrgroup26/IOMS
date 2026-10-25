# ADR 071 — A merchant is reviewed on what a stranger can reach

**Status:** Accepted (v2.131.0, 2026-10-25)
**Supersedes:** the public-identity half of v2.89.0 ("Tahada Group is the public company identity").
**Related:** ADR 038 (account / organization / subscription), ADR 041 (billing mode), ADR 046 (payment
provider abstraction), ADR 057 (prove what is running), `docs/PAYMENTS-DUITKU.md`,
`docs/DUITKU-INTEGRATION-OVERVIEW.md`, `docs/runbooks/EMAIL-ZOHO.md`,
`docs/runbooks/TENCENT-MIGRATION.md`.

## Context

Duitku's onboarding review for project `D25218` came back with four findings. Each is about something
a stranger with no account can reach:

1. The site presents **IOMS** as the product, while the footer, sign-in page and About dialog credit
   **Tahada Group** and the Terms name **PT Tahada Vistara Bersama**: a brand/ownership mismatch.
2. Registration answered **HTTP 500**.
3. No business address or telephone number is published.
4. They asked how IOMS actually uses Duitku.

A pricing complaint arrived in the same mandate: Master Admin shows Starter at Rp189.000, while an
invoice read Rp299.000.

## Decisions

### 1. Three identities, kept apart in configuration

| Identity | Key | Where it appears |
|---|---|---|
| Product brand | `ioms.company` (now `IOMS`) | every public surface, copyright line |
| Platform operator | `ioms.legal.entity_name` (now **null** by default) | Terms / Contact / JSON-LD `legalName`, **only when set** |
| Legal merchant | `ioms.merchant.legal_name` (default PT Tahada Vistara Bersama) | the invoice issuer block |

The "Designed & Developed by" line is removed from the sign-in page, the app shell and the About
dialog. The developer's personal name (`ioms.developer`) is no longer shared to every page's props.
Nothing legal was rewritten: the merchant still issues the invoice, historical invoices still render
with it, and an owner who wants the operator named on the Terms sets `IOMS_LEGAL_ENTITY`.

**Rejected:** a global find-and-replace of "Tahada". It would have stripped the payment recipient from
invoices, which is the one place the legal entity must appear.

**Owner decision still open:** the invoice must name the same entity Duitku's merchant account is
registered to. If that account is held by someone other than PT Tahada Vistara Bersama, set
`IOMS_MERCHANT_LEGAL_NAME`. This can't be inferred from the repository.

### 2. A mail failure is not a request failure

Root cause of the 500, reproduced before fixing: `POST /register` writes the `users` row, then
`event(new Registered)` sends the verification email **synchronously**. Every other Mailable in IOMS
was already wrapped in try/catch; the account verification and the password reset were not. With
SMTP broken (mailboxes moved from cPanel to Zoho), the transport threw, the person saw a 500, and
retrying said "already registered" because the row already existed.

Now `User::sendEmailVerificationNotification()` catches and logs the failure and returns whether the
transport accepted the message. The registering controller reads that and the welcome page says the
email didn't go. Resend says the same rather than "sent". The forgot-password form answers with a
plain message instead of a 500. No transport detail reaches the browser.

**Rejected:** wrapping registration in a DB transaction and rolling back on mail failure. That would
make an account depend on a third party's SMTP being up at that instant. **Rejected:** queuing the
mail. cPanel runs no worker, so a queued verification email would sit undelivered.

### 3. An open order is billed at today's catalogue, by one rule

Starter's authoritative price is **Rp189.000** (v2.82.0 migration `revise_pricing_and_user_entitlement`,
mirrored in `PackageSeeder`). The Rp299.000 came from an order raised under the v2.60.0 catalogue.
Only `checkout()` repriced an open order, so the status page printed the registration's snapshot, and
`pay()` / `startPayment()` sent the invoice's old amount to Duitku. `RegistrationController::
repriceOpenOrder()` is now asked by every entry point:

- a **paid** invoice is never touched (financial record);
- an invoice with a **live payment session** is not touched: the provider was asked for that amount,
  and the callback's amount check would refuse a settlement that disagreed;
- otherwise registration and invoice follow the catalogue, and the change is written to the activity log.

A **provisioned** customer on `subscriptions.agreed_price_*` keeps their agreed price on purpose
(v2.60.0). If the Rp299.000 invoice the owner saw is a renewal for such a customer, that's the design
working; moving them is `Subscription::repriceTo()`, an operator decision.

### 4. Duitku: two adapter defects found by reading the current documentation

- `getPaymentMethods()` signed with plain `SHA256(merchantcode + amount + datetime + apiKey)`.
  Duitku's changelog declared plain SHA-256 and MD5 obsolete in favour of
  `HMAC_SHA256(merchantcode + amount + datetime, apiKey)`. A rejected list comes back empty, and the
  customer sees "Sesi pembayaran belum tersedia", so checkout could never start.
- `createPayment()` ignored the caller's `finish_url` and always returned customers to
  `/subscription/billing`, an authenticated tenant page, so a new customer came back from Duitku to a
  sign-in wall.

Both are fixed and pinned by tests at the HTTP boundary. Neither has been exercised against
`sandbox.duitku.com`: the development environment's network policy blocks it. One real sandbox
transaction from staging is still required (`docs/PAYMENTS-DUITKU.md` §6).

### 5. Contact facts are configuration, and absent until verified

`IOMS_LEGAL_ADDRESS` and the new `IOMS_SUPPORT_PHONE` render on `/contact` (and the invoice) only when
set. No address or telephone number exists anywhere in the repository or configuration, and neither
is invented. **External blocker:** the owner supplies a verified business address and phone number.

`hello@` defaults to `support@`, because the Zoho organisation has no `hello@`, and a published
address that bounces is worse than a shared one.

## Consequences

- A stranger's path through `/`, `/pricing`, `/contact`, `/terms`, `/register` and checkout names
  IOMS only, cannot 500 on mail, and quotes one price.
- `php artisan ioms:mail-check` answers "can this server send mail?" without printing the password.
- The Tencent migration is a runbook plus scripts (`deploy/tencent/`) that inspect before changing,
  refuse to displace whatever holds ports 80/443, and change the firewall only when asked. It is
  **not executed**: no SSH, console, Cloudflare or cPanel access was available.
