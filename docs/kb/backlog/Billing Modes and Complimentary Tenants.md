---
title: Billing Modes and Complimentary Tenants
type: backlog
status: COMPLETED (v2.80.0)
updated: 2026-09-27
tags: [kb/backlog, status/verified]
---

# Billing Modes and Complimentary Tenants

**Source:** `docs/FUTURE IDEAS/PROMPT 2.md` §2–§5, §11 · **Board:** [[Project Board]]

## What was asked

An existing tenant — created before a payment gateway existed, running complimentary or manually
billed — must be able to become a **paying** tenant later **without being recreated**, keeping its
id, users, operating units and all operational data. And the architecture should distinguish
COMPLIMENTARY / MANUAL / PAID.

## The critical requirement is already satisfied — `#status/verified`

This was audited against the code, and the worry behind it does not apply to IOMS:

- A tenant is created **once**, and payment never creates a second one. The only path that creates a
  tenant is `TenantProvisioningService::activate()`, which is idempotent and, when an account
  already exists, **attaches** it rather than duplicating (ADR
  [[038-account-organization-subscription\|038]]).
- A verified payment runs `applyPaidInvoice()` → `extendPeriod()` on the **existing** subscription
  row. There is no code path anywhere that recreates a tenant, company or user on payment.
- `SubscriptionReadOnlyEnforcementTest` asserts exactly this end to end: renew → signed webhook →
  the same subscription extended, tenant/company/subscription/user counts **identical**, records from
  before the lapse intact.
- An operator-created tenant (Platform → Tenants → Add Tenant) already receives a real subscription
  with agreed prices, so renewing or upgrading it bills normally through the ordinary flow —
  exercised in `ReviewerJourneyTest` (v2.78.1).

**No migration tooling is needed, and no destructive migration exists to avoid.**

## Built in v2.80.0 — `#status/verified`

`subscriptions.billing_mode` — `paid` / `manual` / `complimentary`, defaulting to `paid` so every
pre-existing row behaves exactly as it did. Set from Master Admin, named in the activity log, and
carried in the shared lifecycle snapshot so the customer and the operator cannot read different
answers about how one subscription is billed.

The three arrangements this note said were indistinguishable:

| Mode | Meaning | How it looks today |
|---|---|---|
| **COMPLIMENTARY / INTERNAL** | Free by decision — demo, internal, a pilot | An ordinary active subscription. Indistinguishable from a paying one except by looking for invoices |
| **MANUAL** | Real money, settled by bank transfer and recorded by an operator | Invoices marked paid manually |
| **PAID** | Settled through a gateway | Invoices with payment transactions |

The three consequences this note listed are fixed:
- The operations console now separates paying from complimentary (*Komposisi Penagihan*), and a
  complimentary subscription never enters the *Perlu Perhatian* work queue — there is nothing to
  chase.
- The nightly lifecycle job **skips invoicing** a complimentary subscription, in the same place and
  for the same reason the demo tenant is skipped.
- "How many organizations actually pay us?" is answered on the dashboard.

## The one place the plan changed, and why it matters

This note said the lifecycle command should skip *invoicing and lapsing* for complimentary
subscriptions. It skips invoicing. It deliberately does **not** skip lapsing.

Skipping lapsing would mean billing mode could keep a tenant writable past its period end — a
**second source of truth** for "may this customer write today", which is exactly what the derived
lifecycle exists to prevent. A stored flag that overrides the dates is the same mistake as a stored
`status` column that disagrees with them.

A free account meant to run indefinitely is expressed with the mechanism that already exists and is
honest about itself: **`type = lifetime`**, which has no period end to run out. `BillingModeTest`
asserts both halves — complimentary does not extend access; lifetime does.

Full reasoning: ADR [[041-billing-mode-is-not-entitlement\|041]]. Verified by `BillingModeTest`
(12 tests) and in a browser against MySQL.

## Related

[[iPaymu Payment Provider]] · ADR [[033-subscription-lifecycle\|033]] · ADR
[[038-account-organization-subscription\|038]]
