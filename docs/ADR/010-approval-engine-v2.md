# 010 — Universal Approval Engine v2 (Milestone 3)

## Status

Accepted.

## Problem

The existing Approval Engine (ADR-001) is genuinely reusable (`HasApprovals` + `Approval` +
`ApprovalController` are already module-agnostic) but only supports a single decision: one Approval
row, one approver, done. Milestone 3 requires multi-level (sequential steps), parallel (several
approvers at one step, any-or-all), conditional (different chains depending on the record, e.g.
amount thresholds), and escalation (auto-widen who can decide if nobody has after N hours) — without
regressing the two existing real consumers, `MaterialRequest` and `LeaveRequest`.

## Decision

**Additive, not a rewrite.** `Approval` gained four nullable/defaulted columns
(`approval_flow_id`, `step_number`, `is_escalated`, `escalated_at`) rather than a new table replacing
it — every existing column, relation, and route name is unchanged.

**A module with no configured `ApprovalFlow` behaves EXACTLY as before.** This is the load-bearing
compatibility guarantee: `App\Services\ApprovalEngine::start()`/`decide()` check whether
`ApprovalFlowResolver` finds a matching flow; if not, they fall back to the pre-Milestone-3 path byte
for byte (one Approval row, `config('workflow.approvers')` authorizes, one decision finalizes the
approvable). `MaterialRequest` and `LeaveRequest` have no flows configured today, so nothing about
their behavior changed — verified via `tinker` (single decision still finalizes immediately) and a
browser walkthrough (no new errors in `laravel.log`).

**Configuration lives in two new tables, not JSON on the approvable itself.** `approval_flows` (one
row per chain, optionally company-specific, optionally conditional via a `conditions` JSON array) and
`approval_flow_steps` (one row PER APPROVER at a step — a parallel step is simply several rows sharing
one `step_number`, not a separate parallel-group table). This keeps the schema simple: "how many
distinct people need to act at this step" is just "how many rows share this step_number," and `mode`
(`single`/`parallel_any`/`parallel_all`) on those rows says how many of them must approve before the
chain advances.

**Rejection anywhere immediately rejects the whole chain** — there's no partial-rejection ambiguity to
resolve (`parallel_all` doesn't need "what if some reject and some approve" logic; one rejection short-
circuits straight to `finalize(REJECTED)`).

**Escalation widens authorization on the SAME pending Approval row, rather than creating a duplicate
row.** The first design attempt created a second Approval row for the escalation target, which left a
dangling pending row once either approver decided (a real bug caught during this session's own
verification, before it shipped). The fix: escalating a step means inserting one more
`ApprovalFlowStep` row for `escalate_to_role` at that step_number — `ApprovalEngine::authorize()`
already checks every step-definition row at a given step_number, so the escalation target is
immediately able to decide the existing row, and there's exactly one Approval row per approver-slot
at all times.

**Conditional flow selection is a flat list of `{field, operator, value}` rules**, evaluated with
`data_get()` against the approvable model, first fully-matching flow (in `priority` order,
company-specific before tenant-wide) wins; a flow with no conditions is a catch-all default. Kept
deliberately simple (no boolean AND/OR trees) — every real example in this milestone's own spec
("amount over X needs another level") is a flat AND of simple comparisons.

**Module key convention shared with the Numbering Engine.** `HasApprovals::approvalModuleKey()`
defaults to `Str::snake(class_basename($this))` — `MaterialRequest` → `material_request` — the exact
same key `NumberGeneratorService` already uses. One flow-configuration key names both which numbering
format and which approval chain apply to a module.

## Verified via `tinker` (not just read)

- Legacy single-step: unchanged (one decision finalizes).
- Two-level sequential: step 1 decision doesn't finalize the approvable; step 2 is auto-created;
  wrong-role users are correctly denied `authorize()`; final decision finalizes.
- Parallel-any: either of two configured approvers finalizes it; the untouched sibling row is left
  pending but harmless (approvable is already finalized).
- Rejection: immediately rejects regardless of step.
- Escalation: a backdated pending approval past its `escalate_after_hours` window gets a widened
  `ApprovalFlowStep`, and the escalation-target role can then decide the same row.

## Consequences

- No admin UI to configure `ApprovalFlow`/`ApprovalFlowStep` rows yet — that's Task #57 (Company
  Settings completion). Until then, flows can only be created via seeder/tinker, which is fine: the
  default (no flow) behavior is correct and safe on its own.
- `Approval::approve()`/`reject()` instance methods were REMOVED (not deprecated) — they only updated
  the row's own fields and bypassed chain-advancement/finalization entirely, which would leave a
  multi-step approvable stuck forever if called directly. `ApprovalEngine::decide()` is now the only
  path that changes an Approval's status; grep confirmed no other call site used the old methods.
- Parallel steps leave "loser" sibling rows in `pending` status forever once the step is satisfied by
  another approver — intentional (an audit trail of who was asked, not just who acted), but worth
  knowing before writing a query that assumes every Approval row eventually reaches a terminal state.
