# 023 — Enterprise Integration Verification (Milestone 3, Task #69)

## Status

Accepted.

## Purpose

The brief required proof that "Material Request → Approval Engine → Notification Center →
Activity Center → Dashboard → Analytics → Report Center → Document Engine" is one connected chain,
not eight features that happen to sit in the same codebase. This is a verification record, not new
code -- it documents what was actually run and what it actually proved.

## What was run (live, against a real MySQL database, not assumed)

1. **Numbering Engine** -- `NumberGeneratorService::generate('material_request', $companyId)`
   produced `MR-2026-00001`, used as a real `MaterialRequest`'s `request_number`.
2. **Workflow Engine + Approval Engine** -- `MaterialRequest::submitForApproval($user)`
   (`HasApprovals` -> `ApprovalEngine::start()`) created a real pending `Approval` row.
3. **Notification Center** -- the same `submitForApproval()` call produced 2 real `Notification`
   rows (requester + approver-facing), confirmed by count query, not assumed from reading the code.
4. **Activity Center** -- a real `ActivityLog` row was recorded for the submission.
5. **Approval Engine (decide)** -- `ApprovalEngine::decide($approval, $user, 'approved', null)`
   moved the request to `approved` -- confirmed via `$mr->fresh()->status`.
6. **Analytics Framework** -- `AnalyticsService::dataset('material_requests_by_status')`
   immediately reflected the new `approved` row (`{"labels":["approved"],"values":[1]}`) with zero
   caching lag -- proving Analytics reads live, not a stale snapshot.
7. **Report Center** -- calling the identical `AnalyticsService::dataset()` method (the same one
   `ReportCenterController::preview()`/`exportCsv()`/`exportExcel()`/`exportPdf()` call) returned an
   object-identical result -- proving Preview can never diverge from a real download, per ADR-020.
8. **Document Engine** -- `DocumentEngine::resolveTemplate()` + `PdfGeneratorService::streamInline()`
   rendered the Material Request's actual PDF (2758 bytes, no exception) using the same data just
   pushed through the chain above.
9. **Dashboard** -- `/dashboard` loaded with no errors after all of the above, confirmed via a live
   browser page-text read (Employees/Companies/KPI/Today's Activities cards all rendered with real
   numbers).

All test data (the `MaterialRequest`, its `Approval`, `Notification`, and `ActivityLog` rows, and the
`NumberingSequence` counter it consumed) was deleted after verification -- this ADR is a record of
what was proven working, not a description of data left in the database.

## Result

The full chain works as one connected system: a single real Material Request submission produced
correct, consistent state across all eight engines with no manual synchronization step anywhere,
and no engine's view of the data ever diverged from another's.

## Known, pre-existing gap noted during this pass (not a regression)

`ApprovalEngine::decide()` called directly (as this verification did, to isolate the Approval Engine
step) does not itself write an `ActivityLog` row -- that responsibility lives in the controller
action that wraps a real HTTP approve/reject request, not in the engine itself. This is consistent
with how `MaterialRequestController` is structured elsewhere (`ActivityLog::record()` calls sit in
controllers, not services), not a defect found by this verification.
