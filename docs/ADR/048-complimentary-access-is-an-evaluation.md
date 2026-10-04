---
title: 048 — Complimentary access is an evaluation, not a free plan
type: adr
status: accepted
decided: 2026-10-04
version: 2.97.0
tags: [adr, status/verified, subscription, billing, complimentary]
---

# ADR 048 — A free period is short, repeatable, and ends by itself

## Status

Accepted, implemented and verified in **v2.97.0**. Revises the duration and
re-grant decisions in ADR [[047-complimentary-access-provisioning|047]]; everything else in 047
stands unchanged.

## The problem

ADR 047 built complimentary access as a **provisioning** decision: a verified registration
becomes a live tenant by an operator's choice instead of by a payment. That part was right and
is untouched.

The shape around it described a different product — a free *plan* rather than an *evaluation* —
and three consequences followed.

### It was measured in months

Durations were a closed list of `[1, 3, 6, 12]` **months**. Twelve months of free access is not
an evaluation; it is a year of unpaid service that nobody revisits. The ceiling existed, but it
was set where a pricing tier would be.

### It could only ever happen once

The grant provisions a `TenantRegistration`, and `isGrantable()` requires `tenant_id === null`.
After the first grant the registration has a tenant, so **the same customer could never be given
another evaluation**. A pilot that ended well and deserved an extension had no route back; the
only options were editing the subscription by hand or inventing a second tenant, which would
have split one customer in two.

### It never ended

This was the worst of the three. `billing_mode` stayed `complimentary` forever, and
`RunSubscriptionLifecycle` **skips complimentary subscriptions entirely** — correctly, because a
free account should not be invoiced or chased. So an expired evaluation was never invoiced, never
asked for anything, and simply sat read-only and permanently non-billable until a human noticed.

The free period had an end date and no ending.

## The decision

**Complimentary access is an evaluation window: days, repeatable, self-terminating.**

### Days, between a floor and a ceiling

`saas.complimentary_min_days` = **3**, `saas.complimentary_max_days` = **30**.

Three is the floor because anything shorter cannot be evaluated: a tenant needs to provision, add
people, and run a day of real work. Thirty is the ceiling because a free arrangement meant to
outlast a month is a commercial decision somebody should re-make deliberately, with a fresh reason
recorded against their name.

A **range** rather than a list, because days are a continuum an operator reasons about directly
("until the end of their trial fortnight"). The bounds live in one place and are read by both the
request and the form, so the UI cannot offer a duration the request would reject.

### Re-granting is the same decision, applied to a subscription

`SubscriptionLifecycleService::grantComplimentary()` grants to a tenant that already exists. It
creates nothing: no second tenant, no second subscription, no parallel store of free access. One
row changes state, which is precisely what stops a re-grant forking a customer in two.

**Previous complimentary history does not block it.** Whether somebody has had an evaluation
before is a judgement for the operator making the decision, and the activity log holds the history
for them to read. It is not a rule to enforce on their behalf. What *is* enforced is that every
grant is deliberate, bounded in days, carries a reason, and is attributable.

It is **manual**. Nothing calls it on a schedule. A free period that renewed itself would be a
permanent discount nobody chose.

### Expiry flips the billing mode, and nothing else

```
Complimentary active → expired → paid-required → normal billing
```

`expireComplimentary()` sets `billing_mode` back to `paid` when the period has ended. That is the
whole transition, and what it does *not* do is the point:

| | |
|---|---|
| Does not extend the period | nothing auto-renews |
| Does not change the dates | where a subscription sits in time stays derived, and was already correct |
| Does not touch access | billing mode grants and withholds nothing (ADR [[041-billing-mode-is-not-entitlement\|041]]) |
| Does not delete anything | tenant, users, invoices, audit trail and the subscription row are untouched |
| Does not clear the reason | why the account was free is still the record, and an account that has become payable still needs it answered |

Having become billable, the subscription falls through to the ordinary invoicing in the same
nightly run: a renewal invoice at **catalogue** price, because `agreed_price_*` was never set on a
free grant and null already means "follow the catalogue".

The conversion is checked **before** the complimentary skip in the lifecycle command. The skip is
what made a grant permanent; the order of those two checks is the entire fix.

## Consequences

- **No migration.** Every column already existed. The lifecycle was missing, not the schema.
- **Existing grants are not rewritten.** A tenant carrying a months-based grant from v2.93.0 keeps
  its end date and converts naturally when that date passes. Retroactively shortening somebody's
  free period would be changing a commercial promise after the fact.
- **ADR 047's refusals still stand** for the registration path: an unverified registration, one
  that already paid, and one that already has a tenant are all still refused there. The last of
  those is now answered by the *tenant* route instead of being a dead end.
- **Entitlements are unchanged throughout.** A re-grant re-entitles through `applyPlanChange()`,
  the same path a paying plan change uses, so a complimentary tenant is bounded by its plan before,
  during and after the free period (ADR [[045-five-spaces-and-the-global-dashboard|045]]).

## Alternatives rejected

**Keep months, add a re-grant.** The duration was the part that made it a plan rather than an
evaluation. Fixing re-granting alone would have made long free periods easier to hand out.

**Auto-renew while a flag is set.** That is a permanent discount with extra steps, and it would
make `billing_mode` decide how long access lasts — the second source of truth ADR 041 exists to
prevent.

**Block a re-grant after N previous grants.** A rule the system cannot judge well. The operator
can see the history and is accountable for the decision; a counter would only be arbitrary.

**A `complimentary_grants` history table.** Nothing about a grant varies in a way the activity log
does not already record, and it is the audit system the rest of the platform uses.

## Related

ADR [[047-complimentary-access-provisioning|047]] ·
ADR [[041-billing-mode-is-not-entitlement|041]] ·
ADR [[033-subscription-lifecycle|033]] ·
`tests/Feature/ComplimentaryLifecycleTest.php` · [[Project Board]]
