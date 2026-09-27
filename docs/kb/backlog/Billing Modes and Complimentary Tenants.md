---
title: Billing Modes and Complimentary Tenants
type: backlog
status: migration already works; the labelling does not exist
updated: 2026-09-27
tags: [kb/backlog, status/planned]
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

## What is genuinely missing — `#status/planned`

The product cannot **say** how a tenant is billed. There is no field distinguishing:

| Mode | Meaning | How it looks today |
|---|---|---|
| **COMPLIMENTARY / INTERNAL** | Free by decision — demo, internal, a pilot | An ordinary active subscription. Indistinguishable from a paying one except by looking for invoices |
| **MANUAL** | Real money, settled by bank transfer and recorded by an operator | Invoices marked paid manually |
| **PAID** | Settled through a gateway | Invoices with payment transactions |

Consequences worth fixing:
- The operations console counts a complimentary tenant as revenue-bearing workload.
- The nightly lifecycle job will invoice a complimentary tenant and, after grace, take it read-only.
  (Only the **demo** tenant is excluded today.)
- Nobody can answer "how many organizations actually pay us?" without inspecting invoices.

## Shape of the work, when it is picked up

- One column on `subscriptions` (for example `billing_mode`), defaulting to the paid behaviour so
  nothing changes for existing rows.
- The lifecycle command skips invoicing and lapsing for complimentary subscriptions — the same
  treatment `isDemo()` already gets, and the same place in the code.
- Master Admin can set it, and the operations console separates paying from complimentary.
- **Do not** let billing mode become a second entitlement system: what a tenant may *use* stays the
  plan's grants. Billing mode says only how it is *paid for*.

## Related

[[iPaymu Payment Provider]] · ADR [[033-subscription-lifecycle\|033]] · ADR
[[038-account-organization-subscription\|038]]
