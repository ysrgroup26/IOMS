# ADR 085 — The invoice is issued under the IOMS brand, and a payment return is never believed

- **Status:** Accepted (owner, 2026-11-03), on `claude/eloquent-newton-pbxxdg`; not on production.
- **Supersedes:** ADR 071's rule that the legal merchant stays on the invoice ("Diterbitkan Oleh").
- **Builds on:** ADR 071 (what a stranger can reach), ADR 041/047 (billing), `docs/PAYMENTS-DUITKU.md`.

## Context

Duitku re-reviewed iomsuite.com and asked for two things:

1. remove "PT Tahada Vistara Bersama" from the registered website;
2. enable checkout and integrate with the Duitku Sandbox.

The audit found:

- **Identity.** The only customer-facing place the name still appeared was the subscription invoice:
  `InvoiceDocumentService::issuerIdentity()` printed `ioms.merchant.legal_name` (default "PT Tahada
  Vistara Bersama") under "Diterbitkan Oleh". Public pages, emails and the checkout read only
  `ioms.legal.entity_name`, which is null unless the owner sets `IOMS_LEGAL_ENTITY`.
- **Checkout.** Checkout, method selection, inquiry, the signed callback, settlement, provisioning and
  emails were already implemented. The "Online payment is not enabled on this deployment yet" message is
  what the code shows when `PAYMENT_GATEWAY` and the Duitku credentials are not configured. Production
  has none, which is why the reviewer saw it. It is configuration, not missing code.
- **Three defects in the flow:**
  1. An onboarding invoice has no tenant, so Duitku received IOMS's own billing mailbox as the payer email
     and "IOMS" as the VA name. The payment instructions went to IOMS rather than to the customer.
  2. A session past its window stayed `pending` for ever: never `expired`, invisible as such.
  3. A customer returning from Duitku (success, pending or cancelled) saw no account of the attempt. A
     cancelled session also blocked choosing another method until it timed out.

## Decision

1. **The customer-facing issuer is the IOMS brand.** The invoice prints `app.name` ("IOMS") at the
   business address IOMS already publishes (`IOMS_LEGAL_ADDRESS`, unchanged). `ioms.merchant.legal_name`
   is kept as internal configuration and is read by nothing that renders a page, a PDF or an email.
   `InvoiceDocumentTest` pins that, even with both keys set. No legal record or stored invoice was
   changed; the invoice is rendered on request.
2. **The payer is the customer.** The adapter sends the caller's `customer_email`, `customer_name` and
   (optional) `customer_phone` to Duitku, falling back to the tenant only when none is given.
3. **A return is never believed.**
   - `register.status` with a `merchantOrderId` that belongs to a pending session of this invoice asks
     Duitku directly (`transactionStatus`, a signed read), at most once per attempt every 20 seconds.
   - Only "failed/expired/cancelled" is acted on: the session is closed so another method can be chosen.
   - "Paid" from that read shows "confirming" and settles nothing. Settlement remains the
     signature-verified callback alone.
   - A return naming another invoice's order is ignored without a provider call.
4. **Expired sessions say so.** `PaymentTransaction::expireStale()` moves `pending` → `expired` past
   `PAYMENT_CHECKOUT_EXPIRY_HOURS`. It never touches a paid or failed row, an invoice or a subscription.
5. **Sandbox only.** `DUITKU_IS_PRODUCTION=false` is the only switch, and `ioms:duitku-check` refuses to
   run against production. Credentials stay in `.env` on the server; none is in the repository or the
   browser.

## Consequences

- The invoice no longer names a legal entity. If a tax or legal adviser later requires one, the reasoning
  above is the place to revisit, and the owner decides.
- Checkout becomes live on a deployment only when the owner sets the four Duitku variables there.
  Staging first (runbook §7a), production only with the owner's approval.
- **Not verified here:** the signature formulas against the live sandbox. This environment cannot reach
  `sandbox.duitku.com` or `docs.duitku.com`. `ioms:duitku-check` on staging is the first real proof.

## Evidence

`DuitkuCheckoutScenariosTest` (13 tests) covers:

- registration and verification;
- Starter, Professional and Business prices;
- invoice and checkout;
- success with activation and email;
- pending, failed, expired and cancelled (confirmed with the provider);
- a cancel claim alone;
- a triple callback;
- an invalid signature;
- a success redirect with no callback;
- a foreign order id;
- the manual notice gone once configured.

All of it runs against a simulated Duitku at the HTTP boundary, sandbox URL only. A mutation check
reverted the payer fix and the test failed.
