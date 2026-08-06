# 012 — Milestone Numbering, and Why It Stops There (Milestone 3, Task #51)

## Status

Accepted.

## Problem

Task #51 asked to bring `Milestone` and `PpeReplacementRequest` up to the same standard as
`MaterialRequest`/`LeaveRequest` (numbering + workflow + approval). Numbering was a clean, safe fit
for both. Workflow/Approval was not, for both — for different, specific reasons worth recording so a
future reader doesn't assume this was simply skipped.

## Decision

**`Milestone` gets numbering (`MS-{YEAR}-{00001}`, via the already-prepared `NumberGeneratorService`
default), but NOT `HasWorkflow`.** Its `status` field is edited through one free-form edit dialog
(`MilestoneController::update()`) that lets an admin set title/description/target_date/status all in
one submit, correcting the status to any of the 4 values (`pending`, `in_progress`, `completed`,
`delayed`) as needed — not a directional Draft→Submitted→Approved lifecycle with distinct
per-state action buttons the way `MaterialRequest`/`LeaveRequest` are. Forcing `HasWorkflow`'s
transition guard onto this would mean either (a) allowing every status to reach every other status,
making the guard pure theater, or (b) genuinely restricting the existing, intentional free-form
correction UX. Neither is an improvement — this is a plain CRUD status field, correctly, and stays one.

**`PpeReplacementRequest` gets NEITHER `HasWorkflow` NOR `HasApprovals`.** Unlike the audit's assumption,
this model is not a partially-built workflow — it's a genuinely one-shot record: created once
(`PpeController::storeReplacementRequest()`), then only ever viewed (`showReplacementRequest`) or
exported to PDF. There is no approve/reject route, no status-changing UI, anywhere in the app today.
Adding a status guard with nothing to drive it would be exactly the "half-wired feature" this
milestone's own rule set warns against (`Jangan membuat dummy... Jangan membuat shortcut`). Its two
`STATUS_DRAFT`/`STATUS_SUBMITTED` constants are left as-is (display-only today).

## Consequences

- A genuine PPE Replacement approval workflow (mirroring `MaterialRequest`'s pattern — submit, HSE/
  Super Admin approve, processed by Warehouse) is real, well-scoped future work, not done here. It
  would need: a real transitions map, `HasWorkflow`/`HasApprovals` on the model, approve/reject routes
  and UI, and a decision on whether `EmployeePpe::STATUS_REPLACEMENT_REQUESTED` items should revert on
  rejection.
- `Milestone` now has a stable, unique `milestone_number` any other part of the app (Report Center,
  Global Search, Document Engine) can reference, without needing a workflow lifecycle to exist first.
