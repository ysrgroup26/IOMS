---
title: iPaymu Payment Provider
type: backlog
status: BLOCKED — the seam is finished, the adapter waits on credentials
updated: 2026-09-27
tags: [kb/backlog, status/planned, status/blocked]
---

# iPaymu Payment Provider

**Source:** `docs/FUTURE IDEAS/PROMPT 2.md` §6–§10, §15, §17 · **Board:** [[Project Board]]

## What was asked

iPaymu as a second provider behind the existing abstraction, without removing or rewriting Midtrans:
sandbox first, signature verification, idempotency, the full set of failure states, and Master Admin
remaining provider-agnostic.

## What already exists — the abstraction is ready

Audited: the seam the backlog asks for is already in place and does not need redesigning.

| Piece | Where |
|---|---|
| `PaymentGatewayInterface` — checkout, payment, status, webhook signature, webhook handling, refund | `app/Contracts/` |
| `MidtransGateway`, `NullPaymentGateway` (throws rather than faking success) | `app/Services/Payments/` |
| Normalised results | `PaymentCheckoutResult`, `PaymentWebhookResult` |
| Provider-agnostic ledger | `payment_transactions` (one row per attempt, carries `gateway`), `payment_webhook_events` (unique per `gateway` + `event_id`) |
| Provider selection | `config/payment.php`, `PAYMENT_GATEWAY` |
| Subscription domain | Never provider-aware: a verified payment reaches `applyPaidInvoice()` and nothing else |
| Operations console | Reads the provider from the data (v2.79.0), so iPaymu appears without a code change |
| Payment ledger screen | Lists every attempt with its provider (v2.80.0); a second gateway appears with no change |

**So the work is a new class implementing one interface, plus a webhook route, plus configuration.**
It is not an architecture change, and Midtrans is not touched.

## v2.80.0 — the audit this note promised, and the three leaks it found

The abstraction was described as ready. Auditing it properly found it was ready for *taking* a
payment and not for the three things the rest of the application needed from a provider anyway — so
those had been reached for concretely instead:

| Leak | Where | Why it mattered |
|---|---|---|
| `$gateway instanceof MidtransGateway ? $gateway->clientConfig() : []` | Billing checkout **and** public registration checkout | iPaymu would have had to be added to an instanceof chain in two places |
| `MidtransGateway::invoiceIdFromOrderId($reference)` | `PaymentWebhookController::apply()` — the **shared** verified-payment path | The worst of the three: iPaymu could have been configured, bound and signature-verified, and then silently failed to find its own invoices |
| `config('payment.gateway') === MidtransGateway::GATEWAY && filled(midtrans keys)` | Both checkout controllers | iPaymu would have been configured and still reported as "no payments available" |

All three are closed. `PaymentGatewayInterface` now also declares `isConfigured()`,
`clientConfig()` and `invoiceIdFromReference()`; both adapters implement them, and
`PaymentProviderAbstractionTest` asserts no domain file names a provider at all (comments excluded —
this codebase documents its own history in them).

**What this means for the adapter:** writing `IpaymuGateway` is now one file plus a route plus
configuration, with nothing to change in subscription, invoice, entitlement or reporting code.

## Still blocked on — unchanged

1. **Sandbox credentials** (VA / API key) — none in this environment.
2. **Current official iPaymu API documentation.** The signature scheme, the callback payload and the
   status vocabulary must come from their current documentation, not from memory. Inventing them
   would produce code that looks finished and fails on first contact.

> [!warning] Do not implement from assumption
> Every existing payment rule — verified-server-side only, idempotency, amount matching, no
> client-side activation — depends on getting the provider's actual contract right.

## When it is unblocked

1. `IpaymuGateway` implementing `PaymentGatewayInterface`; all provider-specific behaviour stays
   inside it.
2. A callback route beside the Midtrans one, CSRF-exempt, authenticated by **signature**.
3. Tests mirroring the Midtrans set: settlement, failure, pending, cancel, invalid signature,
   duplicate callback, unknown transaction, amount mismatch.
4. Midtrans regression must stay green — `SubscriptionLifecycleTest` is the guard.
5. Document the sandbox-versus-production boundary honestly: sandbox verification is not production
   verification (the same caveat [[Verification Status]] already records for Midtrans).

## Related

[[Billing Modes and Complimentary Tenants]] (the other half of PROMPT 2) · ADR
[[033-subscription-lifecycle\|033]] §5 (the invoice is the contract a payment settles)
