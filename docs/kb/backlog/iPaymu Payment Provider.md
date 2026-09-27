---
title: iPaymu Payment Provider
type: backlog
status: future, blocked on credentials and documentation
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

**So the work is a new class implementing one interface, plus a webhook route, plus configuration.**
It is not an architecture change, and Midtrans is not touched.

## Blocked on

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
