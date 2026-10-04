# Duitku — sandbox runbook, callback security, reconciliation and production cutover

**No credential appears in this file, in the repository, or in any committed configuration.**
Every value below is read from the environment and is empty by default.

Reasoning for the shape of the integration: `docs/ARCHITECTURE.md` §*Adding a payment provider is a
class, not a branch*, §*A payment session is reused, not reopened*, §*What a payment row has to
answer afterwards*, §*Amount integrity is checked against both the contract and the session*.

---

## 1. What is where

| Concern | File |
|---|---|
| Provider contract | `app/Contracts/PaymentGatewayInterface.php` |
| Duitku adapter | `app/Services/Payments/DuitkuGateway.php` |
| Binding (and the refusal to half-activate) | `app/Providers/PaymentServiceProvider.php` |
| Callback endpoint | `PaymentWebhookController::duitku()` → `POST /webhooks/payment/duitku` |
| Shared settlement path | `PaymentWebhookController::apply()` |
| Live-session rule | `PaymentTransaction::liveFor()` |
| Configuration | `config/payment.php` |
| Tests | `DuitkuPaymentFlowTest`, `AdminContextAndBillingFlowTest` |

**Nothing outside the adapter names Duitku.** `PaymentProviderAbstractionTest` pins that: adding a
provider is one adapter class, one route and configuration — no change to subscription, invoice,
entitlement or reporting code.

---

## 2. Sandbox configuration

```
PAYMENT_GATEWAY=duitku
DUITKU_MERCHANT_CODE=<sandbox project code>
DUITKU_API_KEY=<sandbox project API key>
DUITKU_IS_PRODUCTION=false
```

`DUITKU_IS_PRODUCTION=false` selects `https://sandbox.duitku.com`; `true` selects
`https://passport.duitku.com`. **There is no third place the endpoint is written** — it is derived
from this one boolean.

> [!important] Naming the provider without keys does NOT half-activate it
> `PaymentServiceProvider` binds the adapter only when the gateway is `duitku` **and** both
> credentials are present. Anything less keeps `NullPaymentGateway`, which throws on every call
> rather than pretending a payment succeeded. A deployment that is half-configured takes no money
> and says so.

### The callback URL to register in the Duitku dashboard

```
https://<your-host>/webhooks/payment/duitku
```

It must be reachable from the public internet and must **not** sit behind authentication, a login
wall, or a bot challenge. It is verified by **signature**, not by session — see §4.

---

## 3. The transaction flow

```
IOMS invoice
  → PaymentTransaction::liveFor()      reuse a live session, or:
  → DuitkuGateway::createPayment()     merchantOrderId = INV<invoiceId>-<YmdHis>-<rand>
  → POST /webapi/api/merchant/v2/inquiry
  → paymentUrl                          customer pays at Duitku
  → POST /webhooks/payment/duitku       Duitku calls US
  → verify signature                    before ANY state is read
  → record the event                    unique (gateway, event_id)
  → check the amount                    max(invoice, session)
  → mark invoice paid + provision/extend, atomically
  → audit log
```

> [!warning] The return URL is UX only
> A browser arriving at a success page proves only that the customer has a browser. **Nothing in
> IOMS settles an invoice except a signature-verified callback.** There is no code path that returns
> "paid" because a checkout was opened or a redirect was followed.

---

## 4. Callback security

### Signature

Duitku's older MD5 scheme is **obsolete** and is not implemented. Both signatures are HMAC-SHA256
over the API key:

| | String signed |
|---|---|
| Inquiry (request) | `merchantCode + merchantOrderId + paymentAmount` |
| Callback | `merchantCode + amount + merchantOrderId` |
| Status check | `merchantCode + merchantOrderId` |

Compared with `hash_equals`, not `===`: a timing-safe comparison is the standard for anything an
attacker can submit repeatedly, and the cost of getting it wrong is a forged settlement. The amount
is signed as the **string Duitku sent**, because re-formatting a number changes it.

### What the endpoint refuses

| Condition | Result |
|---|---|
| Duitku is not the configured gateway | `503`, nothing read |
| Missing / empty / wrong signature | `403`, nothing read |
| Amount differs from `max(invoice, session)` **in either direction** | `200`, **not settled**, recorded on the transaction, written to the activity log, and raised on the operator feed |
| Unknown `merchantOrderId` | `200`, logged, nothing changed |
| `resultCode` IOMS does not recognise | treated as **pending** — changes nothing |
| Already-processed event | `200`, re-applied to nothing |

`200` for a refusal is deliberate: the event was understood and a decision was taken. Returning
non-2xx asks Duitku to retry, which turns a permanent condition into a retry storm.

### Idempotency

Three independent layers, so no single one has to be perfect:

1. **`payment_webhook_events`** is unique on `(gateway, event_id)`, where the event id is
   `merchantOrderId : resultCode : reference` — the tuple that makes one state transition unique.
   The **database**, not application logic, decides whether a delivery is the first.
2. **The settlement block** re-reads the invoice inside a transaction and returns if it is already
   paid. Settling and acting on it are one atomic step: an invoice can never be paid-but-not-applied,
   nor applied twice.
3. **`TenantProvisioningService::activate()`** is itself idempotent under a row lock, so even a
   duplicate that reached it provisions one tenant.

Asserted by delivering one identical callback three times and proving one tenant, one subscription,
one settlement.

### Secrets

The API key is never logged, never returned, and never stored. The **signature is stripped** before
the payload is written to `provider_metadata` — it is derived from the key, so keeping it would put
a secret-derived value beside the data it authenticates, and it has no use after verification.

---

## 5. Reconciliation

Every verified callback — **including failures** — writes:

`provider_reference` · `publisher_order_id` · `payment_method` · `result_code` · `failure_reason` ·
`paid_at` · `callback_received_at` · `provider_metadata`

So the usual support questions are a lookup rather than a hunt through JSON:

| Ask | Where |
|---|---|
| "Did the callback arrive?" | `callback_received_at` — null means it never did |
| "Why did it fail?" | `result_code` + `failure_reason` |
| "Duitku's dashboard shows reference X" | `provider_reference` (indexed) |
| "Which payments settled this week?" | `paid_at` (indexed) |

Master Admin → **Payments** walks the other direction: from a reference the customer quoted back to
the subscription it settled and the tenant that owns it.

`getPaymentStatus()` asks Duitku directly for a transaction's state. It is a **read** and is
deliberately not wired to anything that settles an invoice, so there remains exactly one path by
which IOMS can come to believe it was paid.

---

## 6. Sandbox test checklist

- [ ] `PAYMENT_GATEWAY=duitku` with both sandbox credentials; confirm the adapter binds
- [ ] Omit a credential; confirm it falls back to `NullPaymentGateway` and takes no money
- [ ] Open checkout; confirm **one** `payment_transactions` row, status `pending`, invoice unpaid
- [ ] Reload the payment page twice; confirm still **one** row, same `gateway_reference`
- [ ] Pay in the sandbox; confirm the callback arrives, invoice `paid`, subscription active/extended
- [ ] Confirm `paid_at`, `payment_method`, `provider_reference`, `result_code` are populated
- [ ] Replay the same callback; confirm nothing settles twice and no second tenant appears
- [ ] Send a callback with a wrong signature; confirm `403` and no state change
- [ ] Send a callback with a lowered amount; confirm not settled and `failure_reason` recorded
- [ ] Send a callback with a RAISED amount; confirm it is also refused and escalated
- [ ] Let a session lapse past `PAYMENT_CHECKOUT_EXPIRY_HOURS`; confirm a new session is minted
- [ ] Confirm complimentary tenants are untouched by all of the above

---

## 7. Production cutover

**Not done, and deliberately not prepared beyond this checklist.** Switching is configuration, not
code.

### Before

- [ ] Production merchant code and API key obtained, held **only** in the production environment
- [ ] Confirm they are absent from the repository, from local `.env`, and from any staging host
- [ ] `DUITKU_IS_PRODUCTION=true` on production **only**
- [ ] Production callback URL registered in the Duitku production dashboard
- [ ] Confirm the callback path is reachable from the public internet: **not** behind Cloudflare bot
      protection, a WAF rule, a login wall, HTTP auth, or an IP allow-list that excludes Duitku
- [ ] If the host enforces an allow-list, add Duitku's callback source addresses (ask Duitku; do not
      guess them)
- [ ] Confirm the production host serves HTTPS with a valid certificate — a provider will not post to
      a bad one
- [ ] `PAYMENT_CURRENCY=IDR`

### Switch

- [ ] Set the production variables; clear config cache (`php artisan config:clear`, then re-cache)
- [ ] One **real, small** transaction end to end, with a real payment instrument
- [ ] Confirm: invoice `paid`, `paid_at` set, subscription period correct, tenant provisioned/extended
- [ ] Confirm the amount Duitku reports equals the invoice exactly
- [ ] Refund or write off that test transaction through the Duitku dashboard

> [!note] Refunds are not implemented in the adapter
> Duitku's disbursement API is a separate product with its own credentials and merchant-side
> activation. `refund()` **throws** rather than returning false, because false would say "the refund
> did not go through" when the truth is "IOMS cannot do this here". Refund from the dashboard.

### After

- [ ] Watch `callback_received_at` on the first live payments — a null one means the callback is not
      reaching the host, which is the single most likely production-only failure
- [ ] Reconcile the first day's `paid_at` rows against the Duitku dashboard
- [ ] Keep sandbox credentials on staging; never point staging at production

---

## 8. Troubleshooting

| Symptom | Most likely cause |
|---|---|
| Customer paid, IOMS still unpaid | The callback never arrived. Check `callback_received_at`; then check WAF/Cloudflare/IP rules on the callback path |
| Every callback `403` | Wrong `DUITKU_API_KEY` for the environment, or sandbox keys against production |
| "Checkout could not be created" | Duitku refused the inquiry — check the logged `statusCode`/`statusMessage`. A wrong merchant code fails here first |
| Duplicate pending payments on one invoice | Should not occur since v2.94.0. If it does, `liveFor()` is finding nothing — check `checkout_expiry_hours` and that the rows carry a `redirect_url` |
| Callback `503` | `PAYMENT_GATEWAY` is not `duitku` on that host |
| Settled for the wrong amount | Cannot happen silently — the amount is signed and re-checked in both directions. Look for a `failure_reason` naming the mismatch, an activity-log entry, and an operator notification |
| Customer paid but is "held for manual review" | The amount did not match exactly. Compare `transaction.amount`, `invoice.amount` and what the provider reports; correct the price or settle by hand, then provision |
