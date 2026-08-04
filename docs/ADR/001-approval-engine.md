# 001 — Universal Approval Engine

## Status

Accepted (v1.6.9). First consumer: Material Request.

## Problem

Material Request (and the discussed future PPE Replacement Request, Permit To Work, Purchase
Request, Asset Request, Inspection) all need the same shape of workflow: a record starts as a
draft, gets submitted, and someone with authority approves or rejects it. Building this
independently per module means N nearly-identical `status` enums, N nearly-identical
approve/reject controller actions, and N nearly-identical "who can decide this" checks --
duplicated logic that drifts apart over time as each module is touched independently.

## Decision

A single, polymorphic `approvals` table (`approvable_type` + `approvable_id`) and a generic
`ApprovalController` with two routes (`approvals.approve`, `approvals.reject`) that operate on the
`Approval` record itself, not on any specific module's own routes. Any model that wants this
workflow adds the `HasApprovals` trait (`approvals()`, `latestApproval()`,
`submitForApproval($user)`) and exposes `STATUS_APPROVED`/`STATUS_REJECTED` constants matching the
fixed vocabulary this engine introduces (`draft -> submitted -> approved/rejected`). A shared
`ApprovalActions` React component is the reusable frontend half -- any Show page renders it the
same way `MaterialRequests/Show.jsx` does.

Approve/reject decisions also record an `ActivityLog` entry, reusing the timeline mechanism
described in ADR 004 rather than building approval-specific history tracking.

## Alternatives Considered

**A `status` column per module with independent approve/reject logic in each controller** --
rejected. This is what would have happened by default if each future module (PPE Replacement, PTW,
Purchase Request...) were built without this engine; it's the exact duplication this ADR exists to
avoid.

**The full configurable multi-step Workflow Engine discussed in an earlier session** (per-company
editable chains like `Manager -> Logistics -> Purchasing -> Warehouse`, role-based steps, Approve/
Reject/Return/Comment/Attachment per step) -- deliberately not built now. That is a substantially
larger feature, and this session's spec asked for the simpler, fixed-vocabulary version
(`Draft -> Submitted -> Approved -> Rejected -> Completed`). The `approvals` table is shaped so a
future multi-step engine could still be layered on top later (e.g. an optional `step` column, or a
separate `approval_steps` table referencing this one) without this version's data needing to
change shape -- but that layering is explicitly future work, not implied to exist yet.

**Authorization**: currently a fixed `isSuperAdmin()` check in `ApprovalController`, not a
per-module/per-role approver assignment (e.g. "Manager approves Material Requests specifically").
Real per-role approval routing is the natural next step once the configurable Workflow Engine
above actually exists -- this fixed check is intentionally not where that complexity lives yet.

## Consequences

- Adding approval support to a new module going forward is: add the trait, add the two extra
  status constants, extend that module's own status enum, call `submitForApproval()` on submit,
  and drop `<ApprovalActions>` into its Show page. No new backend routes or controller code.
- The single `authorizeDecision()` check in `ApprovalController` is a single point to extend once
  real role-based approval routing is needed, rather than N separate places to update.
- `$approval->approvable::STATUS_APPROVED` (accessing a static constant through a polymorphic
  instance) means every future approvable model **must** define `STATUS_APPROVED`/
  `STATUS_REJECTED` constants with those exact names, or approval decisions will throw. This is an
  implicit contract, not enforced by an interface in this version -- a natural follow-up would be a
  small `Approvable` interface making that contract explicit and catchable earlier.
