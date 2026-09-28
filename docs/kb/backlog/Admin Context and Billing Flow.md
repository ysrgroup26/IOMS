---
title: Admin Context and Billing Flow
type: backlog
status: COMPLETED (v2.84.1) — Duitku needs credentials on the deployment
updated: 2026-09-29
tags: [kb/backlog, status/verified]
---

# Admin Context and Billing Flow

**Source:** owner inspection of the deployed v2.84.0 UI, 2026-09-29 · **Board:** [[Project Board]] ·
**Decision:** ADR [[046-admin-space-is-a-context|046]]

## The rule this adds

```
ADMIN SPACE             how the company / account is CONFIGURED and ADMINISTERED
OPERATIONAL WORKSPACES  how the company actually OPERATES
```

It runs **both** ways. An administrator does not get administrative menus inside a workspace; an
operational workspace's functions are not duplicated inside Admin Space.

## What was corrected

| Problem | Correction |
|---|---|
| Admin Space changed the page but not the context — clicking Dashboard dropped you into HSE | Exactly one context lit at a time; the operational header links disappear inside Admin Space; you leave only by choosing a workspace |
| User administration reachable from inside HSE — sidebar, Overview, **and the routing layer** | All three closed. `settings` left the `hse` entry in `config/departments.php` |
| Two names for one workspace | **Warehouse Logistics**, everywhere, carried by a data migration |
| Master Admin Support returned 500 | Root cause was a **pending migration**, not a support bug. The console now reports schema drift; the queue states it instead of failing |
| "Renew → contact IOMS Billing" | **Duitku implemented** at the provider abstraction that was already there |
| Retired surfaces still visible | Project/Procurement/Assets/Maintenance content removed from the dashboard, the overviews, the landing page, the showcase and the sandbox |

## The Support 500, precisely

Reproduced by renaming `support_tickets` and `support_ticket_messages` away: **every** Master Admin
page returned 200 and **only** `/platform/support` returned 500 — it is the only surface that touches
those tables. The exception was `SQLSTATE[42S02] Base table or view not found`, correct and invisible,
because `APP_DEBUG=false` is right in production.

**The remedy on a deployment is `php artisan migrate --force`.** The product change is that the
console now says so.

## Payment: what existed, what was missing

**Already there:** the subscription lifecycle, renewal invoices, `PaymentGatewayInterface` (nine
methods), config-driven binding, `MidtransGateway`, `NullPaymentGateway` that throws rather than
faking, `payment_transactions`, `payment_webhook_events`, and a signature-verified webhook that is the
only thing allowed to settle an invoice.

**Missing:** a provider. The "contact IOMS Billing" message was the honest null-gateway path.

**Added:** `DuitkuGateway`, a config block, `webhooks/payment/duitku`, its CSRF exemption, and
`gatewayName()` on the contract so the shared settlement path stops stamping Midtrans onto every
invoice.

> [!important] Configuration still required on the deployment
> ```
> PAYMENT_GATEWAY=duitku
> DUITKU_MERCHANT_CODE=…
> DUITKU_API_KEY=…
> DUITKU_IS_PRODUCTION=false   # true for live
> ```
> and the callback URL `https://<host>/webhooks/payment/duitku` registered in the Duitku dashboard.
> Without credentials the deployment keeps the null gateway, which refuses rather than pretending.

## What is deliberately preserved

- **Tenant isolation, RBAC, entitlement and the subscription lifecycle.** No gate was weakened; the
  administrative boundary was *tightened* at the routing layer.
- **The PTW Access permission.** Same route, same role gate — asserted.
- **Every retired module's code, routes and data.** Not customer-facing; not gone.
- **Pricing.** Unchanged.

## Related

ADR [[046-admin-space-is-a-context|046]] · ADR [[045-five-spaces-and-the-global-dashboard|045]] ·
[[Final Workspace Architecture]] · [[Pricing Plans and Entitlements]] · [[Verification Status]]
