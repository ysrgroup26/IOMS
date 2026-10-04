---
title: 047 — Complimentary access is a provisioning decision, not a payment
type: adr
status: accepted
decided: 2026-10-04
version: 2.93.0
tags: [adr, status/verified, subscription, billing, provisioning, platform]
---

# ADR 047 — Granting free access provisions the registration; it never fakes a payment

## Status

Accepted, implemented and verified in **v2.93.0**.

## The problem

ADR [[041-billing-mode-is-not-entitlement|041]] gave IOMS the vocabulary for a free
account: `subscriptions.billing_mode = complimentary`, which the nightly lifecycle job
skips invoicing and the operations console leaves out of its collections queue.

It gave IOMS no way to **create** one.

`billing_mode` is a column on a subscription, and a subscription only exists once a
tenant exists. Every path to a tenant ran through money:

| Path | What it required |
|---|---|
| `TenantProvisioningService::activate()` | `status = paid`. It refuses anything else, by design. |
| `PlatformController::storeTenant()` | An operator re-typing the company name, slug, admin name, email and password by hand. |

So a verified organization that was never going to pay — a pilot, an internal
evaluation, a reference customer — could only be onboarded by the second path. That is
not merely inconvenient:

- **It duplicates an identity the system already holds.** The registration carries the
  legal name, the address, the tax id, the industry and a confirmed contact. Re-typing
  a subset of it into a different form produces a tenant whose company identity is
  thinner than the one the customer actually submitted, and whose first generated PDF
  has a worse letterhead than a paying customer's.
- **It orphans the registration.** The real `TenantRegistration` row stays
  `verified` forever, sitting in the Master Admin queue beside the tenant it silently
  became, with `tenant_id` null. Nothing connects the two.
- **It invites the dangerous shortcut.** The obvious alternative — mark the invoice
  paid so the normal path runs — is the one thing that must never happen. It is
  indistinguishable afterwards from money that actually arrived, and it corrupts
  revenue reporting permanently.

## The decision

**A complimentary grant provisions the registration itself, through the same
provisioning body a payment uses, with different commercial terms.**

`TenantProvisioningService::provision()` now takes a `$terms` array. Two callers
assemble it:

| Caller | Terms |
|---|---|
| `activate()` (a verified payment) | plan and cycle from the order, catalogue price snapshotted, `billing_mode = paid` |
| `activateComplimentary()` (an operator) | plan and duration from the operator, **no agreed price**, `billing_mode = complimentary`, reason and operator recorded |

Everything else — the tenant, the first company, the workspace and module grants, the
Super Admin attachment, the company identity copy into `company_settings` — is one
piece of code serving both. That is the point. A complimentary tenant must be
indistinguishable from a paying one in every respect **except how it is paid for**, and
the only way to guarantee that is for one routine to build both. A separate "free
provisioning" path would be a second place for the grant mapping and the identity copy
to drift.

### What it must not do, and how each is prevented

**It must not fake a payment.** Nothing in the grant path raises an invoice, marks one
paid, or writes a `PaymentTransaction`. An invoice the prospect was going to settle is
**voided** — not deleted, because the document was really raised and the void is the
honest record of what became of it; and not paid, because nobody paid it. The void is
guarded on the invoice being unpaid, so a genuine historical payment is never rewritten.

**It must not become a second entitlement system.** The subscription is an ordinary
`subscription` with a real `ends_at`. It is not `trial` and it is **not `lifetime`**.
When the period ends it lapses to read-only exactly as a paying one does. This is ADR
041 §"What it must never change", and it is the single most important property here:
if free access outlived its dates, `billing_mode` would be a second answer to *"may
this customer write today"*, and the lifecycle design exists to keep that answer
derived and single.

**It must not duplicate the organization.** The grant is refused outright for a
registration that already has a `tenant_id`. Making an existing organization free is a
billing-mode change on its subscription — a thing Master Admin can already do — not a
provision. Pressing the button twice therefore errors rather than building a second
tenant, and the check happens inside the locked transaction, so it is safe under
concurrency rather than merely unlikely to collide.

**The browser must not decide anything commercial.** The request carries exactly three
fields: `package_id`, `months`, `reason`. There is deliberately no `billing_mode`, no
`status`, no `ends_at`, no `tenant_id` and no amount, because a request that could name
its own billing mode could mark itself paid, and one that could name its own end date
could grant itself a decade. The plan is re-read from the database and must be
`is_active`; the duration must be one of `config('saas.complimentary_durations')`.

### Duration is a closed list

`[1, 3, 6, 12]` months, from config. A closed allow-list rather than a minimum and a
maximum: a mistyped duration should be a rejected request, not eighty-three years of
free service nobody notices.

Twelve is the ceiling on purpose. An arrangement meant to outlive a year is a different
commercial decision and should be re-made deliberately at the end of the period rather
than granted once and forgotten. Extending is the ordinary subscription edit, which is
already audited. A genuinely perpetual free account is still `type = lifetime`.

### The reason is required, and required to be real

Minimum ten characters, recorded on `subscriptions.notes` and named in the activity log
alongside the operator's address, the plan, both dates and the registration reference.
The reason is the only part of the record that explains *why* a customer is not being
charged, and it is what an audit a year later actually reads. A blank or one-word
reason would make the log technically complete and practically useless.

## Consequences

- **No migration.** Every column this needed already existed. That is a result of ADR
  041 having separated billing mode from entitlement properly in the first place, and
  it is the reason this release touches no production data at all.
- **Conversion to paid needs no conversion code.** A complimentary tenant becomes a
  paying one through the existing subscription edit: `billing_mode` moves to `paid`,
  `applyPlanChange()` re-agrees the price and re-syncs the grants. Nothing is
  recreated — same tenant id, same users, same company, same operational records.
  Asserted end to end by `ComplimentaryAccessTest`.
- **`agreed_price_*` stays null on a grant.** Null already means "follow the
  catalogue", which is exactly right if they later convert: nothing was agreed, so
  nothing is remembered as agreed.
- **The paying path was refactored.** `provision()` changed signature. The risk is that
  the paid path quietly changed behaviour in the process, so there is a regression test
  asserting a paid registration still provisions billable, priced from the catalogue,
  with the period its ordered cycle buys.

## Alternatives rejected

**Mark the invoice paid and reuse `activate()` unchanged.** The shortest diff and the
worst outcome. It writes a payment that did not happen into the one system of record
that is supposed to answer "who has paid us".

**A `complimentary` registration status.** It would put a commercial arrangement into
the onboarding pipeline's state machine, where every other value describes how far
along the *customer* is. A grant is something the operator does, and its record belongs
on the subscription it creates.

**A free `Package`.** Plans say what a tier *grants*; `packages` says what it *costs*
(ADR 043). A zero-price plan would conflate the two and would need duplicating for every
tier anyone might want to pilot.

**Letting the operator pick arbitrary dates.** Rejected with the duration allow-list
above: the failure mode is silent and long-lived.

## Related

ADR [[041-billing-mode-is-not-entitlement|041]] ·
ADR [[033-subscription-lifecycle|033]] ·
ADR [[040-master-admin-is-an-operations-console|040]] ·
ADR [[038-account-organization-subscription|038]] ·
`tests/Feature/ComplimentaryAccessTest.php` · [[Project Board]]
