# Runbook — P4: the `skipped` approval status (before production)

**Status:** approved by the owner for testing and staging **only** (2026-11-02). Production needs a
separate approval. Migration: `2027_01_10_100400_an_approval_not_needed_is_skipped.php`. The deploy policy
classifies it as **review** (not additive), so it cannot deploy without a person.

## What changes

`approvals.status` widens from `ENUM('pending','approved','rejected')` to
`ENUM('pending','approved','rejected','skipped')`.

From v2.141.0, when a stage is decided, the engine marks the other rows at that stage `skipped`. A stage is
decided by the first approval on an "any one approver" stage, or by any rejection. A `skipped` row was never
decided; it records that the stage was decided without it.

## Impact on data and running systems

| Question | Answer (verified) |
|---|---|
| Does any existing row change value? | **No.** The migration only adds an enum member, at the end, so existing values keep their stored index. Checked on MySQL 8.0.46: values identical before and after. |
| Is the table copied or locked? | **No.** MySQL 8.0 performs this as `ALGORITHM=INSTANT` (metadata only): adding a member at the end of an ENUM with ≤255 members does not change the storage size. Verified with an explicit `ALGORITHM=INSTANT` on a scratch table. Production runs MySQL 8.0 on Ubuntu 24.04, the same major version. |
| Is approval history rewritten? | **No.** Rows from before this release are not touched. Old leftover `pending` rows on an already decided stage stay `pending`. The engine refuses to decide them (`ApprovalEngine::isStageAlreadyDecided()`, applied only to rows without a pinned version), and Work Center and My Work no longer list them. Pinned by `ApprovalFlowVersionTest::test_a_stale_pending_row_from_before_the_fix_is_never_decided_and_never_rewritten`. |
| Running approvals during deploy | A request already pending keeps working. Its next decision on an "any one" stage closes the other rows of that stage as `skipped`, which is the intended fix. |

## Every reader of `approvals.status` (audited 2026-11-02)

| Reader | Behaviour with `skipped` |
|---|---|
| Pending queries (`ApprovalEngine::start`, `MyWorkService`, `WorkCenterService`, Logistics dashboard, Settings version summary, `ApprovalController`) | Filter `status = 'pending'`, so `skipped` is excluded. |
| `ApprovalController::authorizeDecision` | Only `pending` can be decided: `skipped` answers 422. |
| `HasApprovals::latestApproval()` (Material Request and Leave pages) | **Changed:** excludes `skipped`, so a record never shows a `skipped` row as its latest approval. Test: `test_the_latest_approval_shown_on_a_record_is_never_a_skipped_row`. |
| `HasApprovals::approverDisplayName()` (documents) | Reads rows with `approved_by`; `skipped` rows have none. |
| Separation of duties (`SeparationOfDuties::excludedFor`), PTW finalisation | Read `approved` rows only. |
| PTW Approval Flow card (`PtwWorkflowService::flowProgress`) | **Changed:** lists only `approved` and `rejected` decisions; a `skipped` row is never shown as "Rejected". |
| Badges (`ApprovalActions`, `semantics.js`) | `skipped` is neutral ("secondary"). |
| Reports and exports | None read `approvals` (checked `app/Exports`, `app/Services/Documents`, report controllers). |

## Rollback and recovery

1. **Preferred: roll back the application only, and keep the column.** Code from before v2.141.0 reads
   `pending`, `approved` and `rejected` only. A `skipped` row is invisible to it as pending and is never
   decided by it. The wider column is harmless to older code.
2. **The migration's `down()` refuses** while any `skipped` row exists. MySQL itself also refuses to narrow
   the column with such rows (strict mode, error 1265, verified). Narrowing would have to rewrite history,
   which the owner's rule forbids. The rule is pinned by
   `test_rolling_the_skipped_status_back_refuses_rather_than_rewriting_history`.
3. **Down with no `skipped` rows** (immediately after deploy) narrows the column back. Up → down → up was
   rehearsed on MySQL 8.0.
4. **Backup:** `ioms-ops deploy` takes a pre-deploy backup as usual (ADR 076). Restoring it is the last
   resort and needs a person.

## Read-only checks before production

```sql
-- 1. Server version (expect 8.0.x)
SELECT VERSION();

-- 2. Current status values (expect only pending/approved/rejected)
SELECT status, COUNT(*) FROM approvals GROUP BY status;

-- 3. Old leftover pending rows on a stage already decided (stay as they are;
--    after deploy they can no longer be decided and are not listed as waiting)
SELECT p.id, p.approvable_type, p.approvable_id, p.step_number
FROM approvals p
JOIN approvals d ON d.approvable_type = p.approvable_type AND d.approvable_id = p.approvable_id
  AND d.step_number = p.step_number AND d.approval_flow_id = p.approval_flow_id
  AND d.id <> p.id AND d.status IN ('approved','rejected')
WHERE p.status = 'pending' AND p.approval_flow_id IS NOT NULL;
```

Expected at the time of writing: check 3 is empty or very small. Configured flows with parallel stages
were rare, and escalation never ran in production.
