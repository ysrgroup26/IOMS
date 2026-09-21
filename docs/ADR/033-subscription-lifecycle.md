# 033 — The Subscription Lifecycle

## Status

Accepted (v2.70.0).

## Problem

IOMS could sell a subscription and then never do anything with it again.

`subscriptions.ends_at` was written once at provisioning and read by nothing that could act on it.
There was no scheduled job, no renewal invoice, no reminder, and no change in what a customer could
do when the date passed. `STATUS_EXPIRED` and `STATUS_GRACE_PERIOD` existed as constants that
**nothing in the codebase ever wrote** — so a subscription four months past its end date still
reported its status as `active`, and every surface that read `status` repeated that.

The Billing page told customers: *"An invoice is issued at the end of each billing period and your
subscription continues once it is paid."* No code anywhere issued one.

A full audit of the surrounding surfaces found four more controls in the same condition:

| Control | What it claimed | What it did |
|---|---|---|
| Platform Admin **tenant suspend** | Suspends the organization | Wrote a column nothing read |
| Platform Admin **plan change** | Moves the tenant to a plan | Updated `package_id`; did not re-price or re-entitle |
| **Mark invoice paid** (bank transfer) | Records the payment | Flipped the invoice; left the renewal date untouched |
| `PAYMENT_RECURRING_ENABLED=true` | Automatic card charging | Changed one paragraph of copy |

## Decision

### 1. The status model splits along the axis that actually differs

`subscriptions.status` now carries **only what a human or a verified payment DECIDED**:
`trial`, `active`, `suspended`, `cancelled`.

Where a subscription sits in **time** — `active`, `grace`, `lapsed` — is **derived on every read**
from the dates already on the row (`Subscription::lifecycleState()`).

This follows the rule the codebase already applies to `Employee::profile_status`,
`PurchaseOrderItem::delivered_quantity` and `currentDisciplinaryStanding()` — and it matters more
here than anywhere else in the product:

- A stored `expired` is correct on the day a job writes it and **wrong the moment the customer
  pays**.
- A stored time state needs a cron running to stay true. A derived one cannot drift and needs
  nothing running at all.
- Therefore a missed cron delays an **invoice**, not a customer's access. Billing automation
  failing must never become a lockout, and with a derived state it structurally cannot.

`expired` and `grace_period` are removed from the vocabulary rather than deprecated. Any row
carrying one is normalised to `active`, which loses nothing: the row keeps its `ends_at`, and the
derived state reads the same answer the stored one was trying to express.

### 2. Expiry is read-only. It is never a lockout, and it never deletes anything

| State | Reads | Writes |
|---|---|---|
| **Active** — inside the paid period | ✅ | ✅ |
| **Grace** — past the period, inside `saas.grace_days` (14) | ✅ | ✅, warned loudly |
| **Lapsed** — past grace | ✅ | ❌ |
| **Suspended / Cancelled** — a deliberate operator act | ❌ | ❌ |

**Reads are never withdrawn by the passage of time, at any configuration setting.**

This is the decision the rest of the design hangs off, and it is not primarily a commercial one.
IOMS is a system of record for safety compliance. The records it holds — permits to work, incident
reports, PPE issuance, training and certification expiry, audit trails — are the evidence an
organization produces for a regulator, an insurer, or an investigation after somebody is hurt.
Withholding those because an invoice is late turns a billing dispute into a safety and legal
problem, and the people harmed by it are the ones who never saw the invoice.

It is also the more effective commercial design. A customer who can still **read** but cannot
**record** feels the lapse on the first shift — every permit, inspection and toolbox meeting has to
go somewhere else — which is a far louder signal than a login screen they can simply stop visiting.

Enforced by `EnforceSubscriptionWriteAccess`, which refuses unsafe HTTP methods and allows an
explicit list through: paying, logging in and out, password reset, and marking one's own
notification read. It is an allow-list so a write route added later is refused by default rather
than silently becoming reachable — the same reasoning `RestrictDemoTenant` already uses.

### 3. Data preservation is structural, not a promise

Nothing in the lifecycle deletes a tenant, a company, a user or a record. This was already true by
construction — `companies.tenant_id` is `restrictOnDelete()`, there is no tenant-delete route, and
nothing in the expiry path writes anything — and v2.70.0 keeps it that way and now **asserts it by
test** rather than assuming it (`SubscriptionLifecycleTest::test_no_lifecycle_transition_removes_tenant_data`).

A subscription can lapse, be downgraded, be suspended, be cancelled and be revived, and the
organization's operational history is byte-identical throughout.

### 4. One living subscription row; period history lives on invoices

The original migration described `subscriptions` as a period-per-row history table. It never
behaved that way: provisioning creates exactly one row, and every read
(`Tenant::subscription()`, `EntitlementService`, the billing page) assumes a single current
subscription. Renewal therefore **extends the existing row**, which is the behaviour the rest of the
codebase already depended on.

"What did this customer pay for, and when" is answerable in full from `invoices`, each of which
carries its own `period_start` / `period_end` and what it bought — which is where an auditor would
look for it anyway.

### 5. The invoice is the contract a payment settles

A verified payment arrives knowing only an order id. Everything else has to be readable from the
invoice itself, so `invoices` gained `purpose` (`onboarding` / `renewal` / `plan_change`),
`target_package_id` and `target_billing_cycle`.

The webhook never infers intent from which foreign keys happen to be null — exactly the kind of
implicit contract that breaks quietly when a fourth case is added.

**Nothing but a signature-verified webhook extends a period.** The in-app checkout page renders
server state and opens a Snap overlay; every Snap callback navigates to the billing page and
nothing else, and there is no endpoint that would accept a payment result from a browser. The one
other path that can settle an invoice is a Platform Admin recording a bank transfer under their own
audited identity — which now runs the identical lifecycle code, so the two cannot diverge.

### 6. Time is added, never reset

Every extension is `max(current period end, now) + one cycle`.

A customer who renews a fortnight early keeps that fortnight. One who renews a month late does not
silently pay for the month they could not use. Writing `now + cycle` is the single most common
renewal bug and it quietly steals from whichever party it rounds against.

### 7. Upgrades apply immediately and prorated; downgrades wait for the boundary

- **Upgrade** → a prorated invoice for the remainder of the current period, applied when paid.
  Charging a full cycle for an upgrade on day 25 of 30 would be indefensible; making them wait for
  capacity they need now would be worse. Proration is never negative — an "upgrade" that computes
  to a credit is billed at zero, because IOMS issues no refunds from a plan change and an invoice
  promising one would be a commitment nothing here can honour.
- **Downgrade or cycle change** → recorded in `pending_package_id` / `pending_billing_cycle` and
  applied at the period boundary. The customer paid for the period they are in, and a smaller plan
  applied today could drop a tenant below the seats or operating units it is **actively using** —
  a data-integrity problem dressed up as a billing one. A downgrade below current usage is refused
  outright, with the specific number to reduce.

A plan change re-agrees the price and re-syncs the tenant's grants. Renewing on the **same** plan
does neither, which is what keeps a customer on the price they bought at when the catalogue moves
(v2.60.0).

### 8. A payment buys time. It does not lift a suspension

`suspended` and `cancelled` are decisions a platform operator made — for abuse, a legal hold, or an
escalation that went past billing. Money arriving must not overturn one, or the operator's control
is only as strong as the customer's willingness to spend. A blocked subscription cannot issue itself
a renewal invoice at all, and if one is settled by another route the period still extends while the
status stays put, so reinstating later costs the customer nothing.

`trial` promotes to `active` on payment, because a paid trial **is** a purchase — the one status
change a payment legitimately makes.

### 9. `tenants.status` is the ACCOUNT switch, and is now enforced

It answers a different question from the subscription: `tenants.status` is whether the **account**
is open, `subscriptions.status` is the **commercial arrangement**. Either closing is enough to close
the door, and `EntitlementService::tenantIsUsable()` now reads both.

`expired` is removed from the tenant vocabulary. Nothing ever wrote it, and expiry is not an account
decision — it is where the subscription sits in time, which a second stored copy could only ever
disagree with.

## Consequences

**Good**

- A subscription product that completes its own lifecycle, with no manual step in the common path.
- Access is correct whether the scheduler ran last night, last month, or has never run.
- Five controls that claimed to work now do, and the one that could not be made to work
  (`recurring_enabled`) is removed rather than left as a trap.
- A lapse is survivable and reversible, and the customer is told exactly what they kept.

**Accepted costs**

- IOMS bills invoice-per-cycle. There is no automatic card charging, and adopting Midtrans
  Subscription/Recurring would be a real integration with its own webhook handling, not a flag.
- `subscriptions:lifecycle` must be scheduled (`php artisan schedule:run` every minute) for invoices
  and reminders to be issued. Access does not depend on it — see §1 — but billing does.
- A downgrade that a tenant's current usage will not fit is refused rather than queued. The customer
  reduces usage first, which is the only outcome that does not strand data.

## v2.77.0 — what the customer sees, and proof that read-only holds

### The lifecycle, as communicated
The state machine is unchanged. What changed is that every state now says **when**, and offers the
**one action that helps**. It is said in two places that agree: the shell banner
(`AuthenticatedLayout` `SubscriptionBanner`, fed by the shared `subscriptionState` prop) and the
Billing page notice (`Settings/Billing.jsx`).

| State | Banner | Billing page | Action |
|---|---|---|---|
| Active, outside the renewal window | none | "Active", renews on date | Renew now (in the card) |
| **Expiring** (active, ≤ `renewal_lead_days` left) | *Renewal due soon*: ends today / tomorrow / in N days, plus the date | *Renewal due soon* (amber); renewing early keeps the remaining time | Renew subscription |
| **Grace** | *Renewal overdue*: period end date and the exact date recording pauses (`grace_ends_at`) | same, plus rows *Period ended* and *Read-only from* | Renew subscription |
| **Lapsed** | *Read-only*: data intact; renew to restore recording | same, plus *Read-only since* | Renew subscription |
| Suspended / cancelled | explains, data not deleted | contact billing | View billing (no renewal: §8) |

When a renewal invoice is already outstanding, the notice offers **Pay renewal invoice**, or states
the bank-transfer instruction when online payment is not configured, instead of raising a second
invoice. There is one renewal button per screen.

Things that were wrong before v2.77.0:
- The grace banner said access continued "for a while", although the date was already sent to the
  browser.
- The last-day copy read "berakhir dalam 0 hari".
- Every state's action was "Go to Billing".
- After the period ended, the Billing row still read "Renews <a past date>".

### Renewal and reactivation
Renewal and reactivation use the existing path, unchanged:
1. `subscription.renew` issues a renewal invoice on the **same** subscription.
2. Payment is confirmed server-side, by the signed webhook or an audited manual settlement.
3. `applyPaidInvoice()` → `extendPeriod()` extends the **same** row, as `max(end, now) + cycle`.

No tenant, company, account or subscription is ever created by renewing.
`SubscriptionReadOnlyEnforcementTest` asserts that end to end through the real Midtrans webhook.

### Read-only, verified rather than assumed
- **The sweep.** `SubscriptionReadOnlyEnforcementTest::test_no_state_changing_route_escapes_read_only`
  calls **every** POST/PUT/PATCH/DELETE route in the product (over 200) as a lapsed tenant's Super
  Admin. It requires a 403 or 404 from each, and requires the row count of every table to be
  unchanged.
  - A 404 is accepted: route-model binding runs before the guard, so a made-up id is refused before
    any write.
  - Real-record create, edit and delete are tested separately.
- **No bypasses found.** There are no state-changing GET routes, no separate API routes, and the
  guard is in the global web stack.
- **Fixed:** the guard's docblock had promised since v2.70.0 that "a user's own profile" stays
  writable, but no prefix implemented it. A lapsed tenant user could not change their own password.
  `account.` and `verification.` are now allowed; they write only to the user's own row.

## Notes

- Grace, renewal lead time and invoice due days are configuration (`config/saas.php`), because they
  are business decisions rather than technical ones. 14 days is the default for each: a renewal
  invoice in Indonesia routinely crosses a finance department, a bank transfer and a public holiday.
- Related: [[008-tenancy-foundation]] (the isolation boundary billing is raised against),
  [[030-material-request-lifecycle-and-demand-consolidation]] and [[031-employee-cases]] (the same
  derive-don't-store rule, applied elsewhere).
