---
title: 046 — Admin Space is a context, and administrative authority lives in it
type: adr
status: accepted
decided: 2026-09-29
version: 2.84.1
tags: [adr, status/verified, navigation, authorization, administration, payments]
---

# ADR 046 — Admin Space is a context, not a page

## Status

Accepted, implemented and verified in **v2.84.1**. It completes ADR
[[045-five-spaces-and-the-global-dashboard|045]], which established the five spaces but left three
things unfinished.

## The rule this adds to the architecture

```
ADMIN SPACE             how the company / account is CONFIGURED and ADMINISTERED
OPERATIONAL WORKSPACES  how the company actually OPERATES
```

Both directions matter, and the second is the one that is easy to forget:

- An administrator does **not** get administrative menus inside HSE, People, Warehouse Logistics or
  Management, merely because they are an administrator.
- Operational functionality is **not** duplicated inside Admin Space, merely because an
  administrator may need the underlying data.

## Decision 1 — Admin Space keeps you in Admin Space

**The defect.** Entering Admin Space changed the page but not the context. The header went on
offering "Dashboard" and "Calendar", the workspace selector went on showing a workspace as active,
and `/dashboard` redirects to a workspace on any plan without the Global Company Dashboard — so
clicking the most prominent control on the screen dropped an administrator into HSE. Admin Space read
as a page inside an operational workspace rather than a place of its own.

**The correction is context, not another permission.**

| | Operational workspace active | Admin Space active |
|---|---|---|
| Workspace selector | lit, `aria-current="true"` | quiet, labelled "Workspaces" |
| Admin Space entry | quiet | lit, `aria-current="page"` |
| Dashboard / Calendar links | present | **absent** |

Exactly one context is lit at a time, using the shell's existing active treatment — a lifted surface
and a hairline border — rather than a new visual language. The Admin Space control **stays in place**
while active instead of being replaced by a read-only chip: the thing you clicked should be the thing
that tells you where you are.

**You leave only by choosing a workspace.** The selector remains available inside Admin Space and is
the deliberate way out. Nothing else crosses the boundary, and a remembered workspace cannot
override it — the space is derived from the route, and Admin Space's routes resolve to the admin
space unconditionally.

## Decision 2 — Administrative authority leaves the operational workspaces

Three leaks, at three different layers, all for the same capability:

| Layer | Leak | Correction |
|---|---|---|
| Navigation | HSE's sidebar carried "Field & PTW Access" → Settings > Users | Removed |
| Page | HSE's Overview carried the same link | Removed |
| **Routing** | `config/departments.php` listed `settings` under `hse` (v2.46.0) | **Removed** |

The routing entry is the one that mattered, because the others were only hiding a door that was
genuinely open. v2.46.0 added it so a department-scoped HSE user could reach Settings > Users to grant
PTW access — a real problem, solved by asking the routing layer to let *administration* through an
*operational* department's door.

**Nothing was taken from anybody who should have it.** An account that administers the tenant reaches
Settings through Admin Space, which is global-tier and never withheld by a plan. An account confined
to the HSE department is an operational user, and user administration was never their responsibility
— it only looked like it because the link was in their sidebar.

**The permission is untouched.** `settings.users.ptw-access` keeps its `role:super_admin,hse` route
gate and its `canManageHse()` assertion, so an HSE lead who also administers the tenant grants PTW
access exactly as before. The test that pinned v2.46.0's hole now pins the boundary, and still
asserts that the permission survived the move.

## Decision 3 — One workspace, one name: Warehouse Logistics

The product carried two names for one thing. "Logistics / PPIC" was the original department;
v2.84.0 renamed it "Logistics / Warehouse" when the standalone Warehouse shell retired into it.
Neither is what it is sold as, and a customer reading "Warehouse" in one place and "Logistics / PPIC"
in another reasonably concludes there are two workspaces.

The display name is **Warehouse Logistics** everywhere a customer reads it. The key stays `logistics`
— every grant row, prefix map, route and entitlement check is keyed on it, and renaming it would be a
migration of the authorization model dressed up as a copy edit.

A data migration carries the rename, because the label in the `workspaces` table **overrides** the
one in code (`applyCatalog()`). It matches both prior defaults, so an install that skipped v2.84.0 is
renamed too, and it never overwrites a label a customer chose for themselves.

## Decision 4 — The operations console reports when the database is behind the code

**This is the Master Admin Support 500, and the root cause was not in the support code.**

Reproduced exactly by renaming `support_tickets` and `support_ticket_messages` away: every Master
Admin page returned 200 and `/platform/support` alone returned 500, because it is the only surface
that touches those tables. The logged exception was
`SQLSTATE[42S02] Base table or view not found`, which is the correct answer — and completely
invisible, because `APP_DEBUG=false` is right in production.

So the fix is not in the support controller. It is that **an operations console could not report the
one fact that explains a whole class of breakage**: the code was deployed and the migration was not.
IOMS ships schema changes on nearly every release, so this is a recurring condition.

`SchemaStatusService` answers two questions — which migrations are pending, and whether a named
feature's tables exist. The platform overview shows the first; the support queue uses the second to
render an explicit "database is behind the code" state instead of failing.

**It reports; it never repairs.** A console that silently migrated a production database on page load
would be far worse than the blank 500 it replaced. And the support check is a **named check**
(`Schema::hasTable`), not a catch, so a genuine bug in the support code still surfaces as a bug.

A latent defect was fixed alongside it, but it is not claimed as the cause:
`SupportTicket::generateReference()` used `max(id) + 1`, which is not the next reference — after one
rolled-back insert every ticket carried a reference lower than its own id (confirmed on real data,
where TKT-000001 lived at id 3) — and could issue a duplicate against a UNIQUE index. It now reads
the highest reference issued, and creation retries on collision.

## Decision 5 — Duitku, implemented at the boundary that already existed

**What already existed**, and it was nearly everything: the subscription state machine and lifecycle
(ADR 033), renewal invoice issuance, `PaymentGatewayInterface` as a complete nine-method provider
abstraction, config-driven binding in `PaymentServiceProvider`, `MidtransGateway` as a reference
adapter, `NullPaymentGateway` that throws rather than faking, `payment_transactions`,
`payment_webhook_events` for idempotency, and a signature-verified webhook that is the only thing in
IOMS allowed to settle an invoice.

**What was missing** was a provider. "Renew → contact IOMS Billing" was not a broken renewal path; it
was the honest `NullPaymentGateway` message on a deployment with no gateway configured.

So this adds an adapter, a config block, a webhook route and a CSRF exemption — and changes nothing
about the lifecycle, the invoice model or any controller that takes a payment.

**The signature scheme is quoted from the official documentation, not inferred.** Duitku's older v2
scheme was `MD5(... + apiKey)`; the documentation now carries an explicit deprecation and specifies
HMAC-SHA256 for both the request and the callback. A signature guessed wrong does not fail loudly —
it fails as "every notification is rejected" while payments succeed.

```
request  : HMAC_SHA256(merchantCode + merchantOrderId + paymentAmount, apiKey)
callback : HMAC_SHA256(merchantCode + amount + merchantOrderId, apiKey)
status   : HMAC_SHA256(merchantCode + merchantOrderId, apiKey)
```

One provider-specific line remained in the shared settlement path — `markPaid(..., MidtransGateway::GATEWAY)`
— so a second adapter would have recorded its payments under the first one's name. The contract
gained `gatewayName()` and the path asks the configured provider.

**Nothing fakes a payment.** `getPaymentStatus()` is a read and is wired to nothing that settles; an
unrecognised `resultCode` maps to *pending*, which changes nothing; `refund()` throws rather than
returning false, because "IOMS cannot do this here" and "the refund failed" are different statements.

## What was deliberately not done

- **No new permission system.** The context work is navigation and route ownership on top of the
  existing authorization model.
- **No public website redesign.** Marketing surfaces stopped *naming* retired departments — a
  correction of claims, not a redesign.
- **No invented credentials.** The adapter is complete; a deployment needs `PAYMENT_GATEWAY=duitku`,
  `DUITKU_MERCHANT_CODE`, `DUITKU_API_KEY` and `DUITKU_IS_PRODUCTION`, plus the callback URL
  registered in the Duitku dashboard.

## Consequences

- A department-scoped HSE user can no longer open Settings. That is the intended boundary, and it is
  a behaviour change for any customer who was using that path.
- `PtwAccessSettingsReachabilityTest` now pins the boundary rather than the hole it was written for.
- Enterprise and Business customers see the workspace renamed to Warehouse Logistics on next request;
  a customer's own renaming is preserved.

## Related

ADR [[045-five-spaces-and-the-global-dashboard|045]] (the five spaces this completes) ·
ADR [[033-subscription-lifecycle|033]] (the lifecycle payments feed) ·
ADR [[040-master-admin-is-an-operations-console|040]] (the console that now reports schema drift) ·
[[Final Workspace Architecture]] · [[Verification Status]]
