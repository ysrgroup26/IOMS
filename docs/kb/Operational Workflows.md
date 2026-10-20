---
title: Operational Workflows
type: reference
updated: 2026-10-19
tags: [kb/workflow, kb/business-rules]
---

# Operational Workflows

How work actually moves through IOMS. The state machines, the business rules that constrain them,
and the rules that look like omissions but are decisions.

Every lifecycle below is enforced by `HasWorkflow`'s `$transitions` map on the model — an invalid
move throws a descriptive error naming what *is* allowed, rather than failing silently or being
prevented only by which buttons the UI happens to render.

> [!note] Authoritative sources
> **The `$transitions` map on each model is authoritative for its lifecycle** — `MaterialRequest`,
> `PermitToWork`, `LeaveRequest`, `EmployeeCase`, `PurchaseRequisition`, `PurchaseOrder`. Read it
> before trusting any diagram, including these. Module-specific business rules live in
> `MODULES.md`; the reasoning behind each lifecycle lives in its ADR.

---

## The rule that explains several others

> [!important] "Pending Approval" is not a stored status
> It is how `submitted` is **labelled** while the associated `Approval` record's own status is
> `pending`. Storing both would be two states that are always created together and always move
> together — duplication, not a distinction. ADR [[006-material-request-workflow|006]].

The same reasoning has been applied twice more since: no separate `closed` status on Material
Request (ADR [[030-material-request-lifecycle-and-demand-consolidation|030]]), and no stored
disciplinary level on an employee (ADR [[031-employee-cases|031]]). **Do not add a status to carry
information that belongs in a field.**

---

## Material Request

The reference implementation, and the lifecycle most worth understanding.

```
draft ──> submitted ──> approved ──┬──> consolidating ──┬──> processing ──> completed
                │                  │                    │        │
                │                  └────────────────────┘        │  (hand-back only)
                ├──> rejected ──> draft                          └──> approved
                └──> cancelled  (from any non-final state, with a required reason)
```

| State | Means |
|---|---|
| `draft` | Being written; not yet asked for |
| `submitted` | Awaiting an approval decision |
| `approved` | Approved, nobody has picked it up yet |
| `consolidating` | **Deliberately retained** by Procurement so it can be bought with related demand |
| `processing` | Being fulfilled — from stock, or sourced onto a Purchase Requisition |
| `completed` | Fulfilled |
| `rejected` / `cancelled` | Terminal; cancellation records **why** |

### Consolidation — the state that exists to tell two situations apart

A request is often held back *on purpose* so related demand can be bought together; ordering one box
of gloves alone is wasteful. Before v2.69.0 that looked identical to a forgotten request, so
**neither could be managed — the healthy case was hiding the failure case**.

- Reachable only from `approved`.
- Carries a **mandatory reason**, plus who decided and when.
- Gated to `canConsolidateDemand()`. A requester cannot park their own request; holding demand is a
  buying decision, not an approval one.
- **Deliberately not called "on hold"** — `on_hold` already means "stopped, waiting on something" in
  this codebase's status vocabulary, which is the opposite claim.
- **Consolidated demand keeps ageing.** A hold that stopped the clock would recreate the original
  problem.

### Aging

`open_age_days`, `aging_level` and `is_outstanding` are **computed**, and null once a request is no
longer owed. Thresholds (14 days "attention", 30 days "overdue") are **presentation emphasis only** —
nothing transitions, expires or escalates because of them, and the exact day count always renders
beside the label.

`processing → approved` exists as a **hand-back path with exactly one caller**: when a Purchase
Requisition sourcing the demand is cancelled. Without it, a cancelled purchase would strand every
request it carried.

---

## The procurement chain

```
Material Request ──(many)──> Purchase Requisition ──> RFQ ──> Purchase Order ──> Goods Receipt
```

- **Many-to-many from MR to PR.** One purchase consolidates several requests. This replaced a single
  `source_material_request_id` column, which could not express consolidation at all.
- **Attaching demand moves it** to `processing`, so the requester can see somebody has it.
- **Cancelling a PR hands its demand back** to `approved`, unless another live PR still sources it.
- **Vendor selection is never automatic.** The system does not pick the cheapest quotation; the
  choice is an explicit, notes-capturing human decision.
- **Delivery status on a PO is reached from real received quantities**, never a manual button.

PR lifecycle: `draft → submitted → under_review → approved → converted_to_rfq → converted_to_po →
completed`, with `rejected`/`cancelled` branches.

---

## Permit To Work

```
draft ──> submitted ──> verified ──> approved ──> active ──> closed
             │             │
             ├─────────────┼──> rejected ──> draft
             └─────────────┴──> cancelled
```

`active` is distinct from `approved`: approval authorises the work, activation is the moment it
begins. A permit can only close from `active`, and `closed` is final. PTW has its own field
experience and a dedicated access permission — see [[Domain Glossary]] on *My Work vs PTW Access*.

**TWO gates, in a mandatory order** (v2.127.0, **ADR 067** — this supersedes v2.125.0's single
gate, which the owner specified away). `canCreatePtw()` still decides who may raise a permit.
Stage 1 is HSE **verification**, which writes `hse_verifier_id`, `verified_at` and
`confirmed_ppe_ids`; stage 2 is final **approval**, which writes `hse_approver_id` and
`approved_at`. Who holds each is the company's own configuration — `ptw_workflow_settings`, roles
and named people per stage, set in Settings → Permit to Work Workflow and read by the one
`PtwWorkflowService` that both the gate and the button ask.

The order is enforced by the **state machine**: `submitted => approved` is not in
`PermitToWork::$transitions`, so it cannot be reached by calling the transition route directly or
by holding both authorities. Verify authority does not imply approve authority — separate columns,
separate methods, authorised per target — and a stage left empty falls back to that stage's default
rather than disabling it. **Activate** is deliberately open to either gate-holder as well as HSE,
because a company routing approval to a manager otherwise had an approved permit nobody could
activate.

**What the creator gets, and does not get.** `requested_by` is always the authenticated user and has
no key in `StorePermitToWorkRequest`, so nothing client-side can set it. The PIC field defaults to
the creator's own employee record where their account has one (ADR 062) and stays editable — a
default, never an assignment, and it confers no approval authority. Requester, creator and PIC are
three slots and stay distinguishable when all three differ.

**Each transition notifies, and the notification OPENS the permit** (v2.125.0, **ADR 064**). Before
that release every workflow notification in the product carried a null url, so the chain fired and
had nowhere to go. The requester is not told about their own action — `notificationRecipient()`
skips actor == recipient, deliberately.

**The audience is derived from the permit's current status**, never from the action being taken:
`pendingStage()` maps `submitted → verify` and `verified → approve`, so there is no code path that
can tell the approver about a permit still awaiting verification. And **the decision is committed
before anybody is told about it** — the transition runs under `lockForUpdate()` inside a
transaction, and the notification is attempted afterwards, best-effort, with `report()` on failure.
`HasWorkflow::notifyStatusChange()` needed the same fix: it notified inside the caller's
transaction, so a throwing notifier rolled the decision back. That was true for every workflow
model in the product, not just PTW (ADR 067).

**And it arrives without a refresh** (v2.129.0, **ADR 069**). Every notification this chain produces
is delivered live to an open tab through a cursor-based delta endpoint, and to a backgrounded or
closed app through self-hosted web push -- so a verifier learns a permit is waiting without
navigating, and an approver's open page follows the permit when somebody else moves it. PTW words
its own notifications per status (`statusNotificationCopy()`): a permit becoming ACTIVE reads "the
work may start", not "status changed." The delivery is deferred to after the commit, so nothing is
announced for a decision that rolled back, and a failed push cannot undo one that did not.

## Leave

`draft → submitted → approved → cancelled`, with `rejected → draft`. Uses the same Approval Engine.

## Employee Case

```
open ──> under_review ──┬──> action_issued ──> closed
                        ├──> closed
                        └──> dismissed
```

- **`action_issued` is reachable only from `under_review`** — a sanction cannot be issued on a case
  nobody reviewed, and the guard enforces that rather than the UI.
- **`dismissed` is not `closed`.** "Reviewed, nothing to answer" and "ran its course" are different
  outcomes, and from the employee's side the difference is the point.
- Issuing an action and moving the status happen in **one transaction** — the status is a
  *consequence* of the action existing, so the two can never disagree.
- Reopening a concluded case is override-only.

---

## Subscription — the one lifecycle that is not a `$transitions` map

Included here because it *is* a lifecycle, and because it is the only one in IOMS whose state is
partly **derived rather than stored** — so looking for a `$transitions` map on `Subscription` and
finding none is not an omission.

```
                      ┌──────────── a verified payment ────────────┐
                      │                                            │
   active ──(period ends)──> grace ──(grace ends)──> lapsed ───────┘
     │         full access     │      full access      read-only
     │                         │                       (never a lockout)
     └──── suspended / cancelled ────  an operator's decision; blocked
```

| Axis | Where it lives | Values |
|---|---|---|
| **What was decided** | `subscriptions.status`, stored | `trial` · `active` · `suspended` · `cancelled` |
| **Where it is in time** | `lifecycleState()`, derived every read | `active` · `grace` · `lapsed` (+ the two blocked states, which win) |

- **`grace` and `lapsed` are never written anywhere.** They are read off `ends_at` plus
  `saas.grace_days`. `expired` and `grace_period` used to be stored values that nothing wrote.
- **Lapsing withdraws writing, never reading.** Enforced by `EnforceSubscriptionWriteAccess`.
  Nothing in the lifecycle deletes tenant data at any point.
- **A payment buys time, not reinstatement.** Suspension and cancellation are an operator's
  decision and a payment does not overturn one; `trial` → `active` is the single exception.
- **Plan changes**: an upgrade invoices prorated and applies when paid; a downgrade or cycle change
  is scheduled for the period boundary, because the customer paid for the period they are in.

Full reasoning: ADR [[033-subscription-lifecycle|033]]. Commercial detail:
[[Pricing Plans and Entitlements]].

---

## Approval authority

Who may decide is configured in `config/workflow.php` (`approvers` / `processors` / `overriders`)
rather than hardcoded in controllers, so segregation of duties is reviewable in one file.

For multi-step, parallel, conditional or escalating chains, `ApprovalEngine` +
`ApprovalFlowResolver` handle it — and **a module with no configured flow behaves exactly as it did
before that engine existed**. That compatibility guarantee is load-bearing: ADR
[[010-approval-engine-v2|010]].

---

See also: [[Modules and Capabilities]] · [[Architecture Map]] · [[Data Ownership and Boundaries]]
