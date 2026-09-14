# 030 — Material Request Lifecycle, Aging, and Demand Consolidation

## Status

Accepted (v2.69.0). Extends ADR 006, which established the original lifecycle.

## Problem

A Material Request could remain outstanding for months, and nobody could tell a healthy long wait
from a failure. Three separate problems sat underneath that one symptom:

1. **`processing` was a black hole.** ADR 006's lifecycle ends `approved -> processing ->
   completed`. Everything that actually happens to a request after approval — a Purchase
   Requisition, an RFQ, a Purchase Order, a delivery — happens in Procurement's records, and
   **nothing wrote back to the request**. `PurchaseRequisitionController::store()` did not touch the
   source request at all. A requester watching theirs sit at "Approved" for four months had no way
   to know whether anyone had picked it up.

2. **Deliberate waiting was indistinguishable from forgetting.** In real HSE procurement a request
   is often held back on purpose so it can be bought together with related demand — ordering one box
   of gloves alone is wasteful. That is correct behaviour, and it was the single most common reason a
   request legitimately sat still. Because it looked identical to a forgotten request, *neither*
   could be managed: the healthy case made the failure case invisible.

3. **Consolidation could not be recorded at all.**
   `purchase_requisitions.source_material_request_id` was a single nullable foreign key, so one
   purchase could source exactly one request. The relationship that consolidation *is* — several
   requests becoming one purchase — had nowhere to live.

## Decision

### 1. `consolidating` is a real state, not a label

A new status between `approved` and `processing`, reachable only from `approved`, carrying a
**mandatory reason**, the user who decided, and when.

It is deliberately **not** called "on hold". `on_hold` already exists in this codebase's shared
status vocabulary (`StatusBadge`) meaning "stopped, waiting on something" — which is the opposite
claim. Consolidation is a decision to wait *on purpose*, and the mandatory reason is what keeps that
distinction alive three weeks later, when the person who made the decision is not the person asking
about it.

Authorization is `User::canConsolidateDemand()` — named for the decision rather than reusing
`canManageProcurement()` at the call site, even though the roles are identical today, because "may
operate the Procurement module" and "may decide somebody else's approved request waits" are
different authorities that will diverge. A requester cannot park their own request, and holding
demand is not an approval decision either.

### 2. There is no `closed` status

A separate terminal `closed` state alongside `cancelled` was considered and **rejected**. Both mean
"ended without fulfilment". A second terminal state whose only distinction is the story behind it is
a duplicate concept with a different name.

The real gap was that `cancelled` recorded **no reason**, so "we bought it another way", "no longer
needed" and "nobody ever actioned this" were indistinguishable afterwards. `cancellation_reason` is
now required. This is the same reasoning ADR 006 used to refuse a stored `pending_approval`: do not
add a status to carry information that belongs in a field.

### 3. `material_request_purchase_requisition` — one purchase, many requests

`source_material_request_id` is migrated into a pivot and **dropped in the same migration**, so
there is never a deployment — or a code path — where a purchase's demand could be read from two
places that might disagree.

The alternative considered and rejected was a first-class "consolidation group" entity holding
requests until a purchase is raised. That would have been a **second source of truth for something
the Purchase Requisition already is**: the PR *is* the consolidation. It just needed to be able to
say so. The `consolidating` status covers the period before the PR exists; the pivot covers it
afterwards.

### 4. Attaching demand moves it; cancelling the purchase hands it back

Attaching a request to a PR transitions it to `processing`, through the ordinary `transitionTo()` so
the guard, the `ActivityLog` entry and the requester's notification are the shared ones. That is the
write-back whose absence caused problem (1).

Cancelling a PR releases its requests to `approved` unless another live PR still sources them. This
required adding `processing -> approved` to the transition map — a hand-back path with exactly one
caller, never offered as a user action. Without it, a cancelled purchase would strand every request
it carried in `processing` with nobody working on them, which is the precise failure this ADR exists
to remove.

### 5. Aging is derived, and it is emphasis rather than a rule

`open_age_days` and `aging_level` are computed from `request_date` and the current status; they are
null the moment a request is no longer outstanding, because a completed request has an age that
means nothing and would sort alongside live work.

`AGING_ATTENTION_DAYS = 14` and `AGING_OVERDUE_DAYS = 30` are **presentation thresholds only**.
Nothing transitions, expires or escalates because of them, and the exact day count is always
rendered next to the label — the number is the fact, the colour is the emphasis. They read the
operational cycle (a request that has not moved inside two weeks has missed a normal procurement
cycle; one past a month has missed the monthly one) rather than being round numbers, and a per-tenant
setting is the natural future home, alongside numbering formats.

**Consolidated demand keeps ageing.** It is a deliberate wait, not a finished one, and a hold that
stopped the clock would recreate the original problem in a new place.

## Consequences

- A requester can see, on their own record, how long it has waited, whether the wait is deliberate
  and why, and which purchase and order are now carrying it.
- "Outstanding, oldest first" is expressible for the first time — it is every non-terminal state at
  once, which no single status filter could say.
- One purchase can answer many requests, and that is visible from both ends.
- `PurchaseRequisition::sourceMaterialRequest()` is gone. Any future reader wanting "the request
  behind this PR" must handle the many case, which is correct.
- The N+1 this created was caught by the tests rather than in production: `transitionTo()` notifies
  the requester, so the relation is eager-loaded before the loop that transitions attached requests.

## What this deliberately does not do

- **No partial fulfilment on the Material Request.** Delivery reconciliation already exists on
  `PurchaseOrderItem` (computed from Goods Receipts) and duplicating it here would create a second,
  thinner version of a working mechanism.
- **No automatic escalation or reminder from aging.** The thresholds are for reading, not for acting.
  A notification that fires on a number nobody agreed to would be noise, and the aging view is the
  thing that was actually missing.
- **No consolidation across Operating Units.** Both sides are company-owned and the pivot is only
  reachable by joining through records the viewer can already see.
