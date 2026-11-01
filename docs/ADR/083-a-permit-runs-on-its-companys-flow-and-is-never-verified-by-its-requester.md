# ADR 083 — A permit runs on its company's approval flow, and is never verified by its requester

- **Status:** Accepted (owner decisions A7, A8b and D1, 2026-11-01). Implemented on the development
  branch in v2.141.0; **not deployed**: staging review first.
- **Supersedes:** nothing. ADR 067 (verify, then approve) stays the route of every operating unit that
  does not opt in.

## Context

The owner set the PTW lifecycle as **Created → Approval 1..N → Final Verification → Active**, with the
Final Verifier taken from the company's own configuration, not from HSE by default (A8). They then decided
the separation of duties (A8b):

1. The requester is never the Final Verifier of their own PTW.
2. Whoever approved any stage of the same PTW is never its Final Verifier.
3. HSE may be an approver or the Final Verifier, as configured.
4. A Project Manager may be the Final Verifier, as configured.
5. The Final Verifier comes from the company's Approval Flow, not globally from HSE.
6. All of it is enforced on the server.

A configuration that leaves no eligible Final Verifier must be refused, with the reason. The owner also
approved React Flow for a visual editor (A7), on condition that it looks like IOMS, works in Light and
Midnight, and never changes a flow that is already under way.

## Decision

**1. A stage has a kind.** `approval_flow_steps.kind` is `approval` or `final_verification`. A PTW flow
(`module_key = permit_to_work`) must end with exactly one Final Verification stage, after at least one
approval stage. Other modules cannot use the kind yet.

**2. Separation of duties lives in the engine.** `ApprovalEngine::authorize()` refuses a Final
Verification decision by the requester, or by anybody who approved any stage of the same record. It also
refuses Final Verification by a Super Admin the flow does not name: on this stage the override is not a
configuration, and the owner's rule 5 forbids a global verifier. `SeparationOfDuties` holds these rules
and is asked:

- before every decision;
- for the notification when the stage opens (only eligible people are told);
- by My Work.

**3. A flow is validated before it is published** (`ApprovalFlowValidator`), for every way a flow can be
written. Structural checks:

- stages numbered 1..N with no gaps (the engine advances to N+1, so a gap finalised early);
- one mode and one kind per stage;
- exactly one approver per row.

PTW checks:

- the shape described in decision 1;
- every stage names at least one active person;
- **A8b at configuration time.** Remove from the verifiers everybody who will *certainly* have approved an
  earlier stage, meaning the sole person able to decide that stage. If nobody remains, the flow is refused.
  If exactly one person remains and they can request permits, the flow is also refused, because a permit
  they request could never be verified.

At **submission** the same rule is applied to the actual requester, before the permit is created or its
document charged.

**4. A permit opts in per operating unit.** `ptw_workflow_settings.approval_mode` is `legacy` by default,
so nothing changes on deploy. `flow` is accepted only when a valid PTW flow applies to that unit. A permit
keeps the route it was submitted on, in both directions. On a flow:

- the verify/approve/return gates refuse the permit;
- its stages are decided as Approval rows;
- the last decision runs `PermitToWork::finalizeApprovalFlow()`. This records the Final Verifier as
  verifier and the last approver as approver, and moves the permit **Verified → Approved** through its own
  state machine, so Approved without verification remains impossible;
- the permit is then activated exactly as before. Automatic activation at the start time is a later
  milestone.

**5. A request keeps its version** (ADR-level detail in `docs/ARCHITECTURE.md`, `ApprovalFlowVersion`).
Editing or removing a flow never changes a request under way; an escalation widens one request only; and
on a stage that one approver is enough to decide, the other rows become `skipped`, never left pending.

**6. The editor is structured, not freehand.** React Flow draws Created → stages → Active.

- Nodes cannot be dragged and edges cannot be drawn. A stage is inserted with the "+" between two stages,
  moved with Earlier/Later, and edited in an inspector.
- On a phone the same stages are a vertical list and the inspector is a dialog.
- The canvas never shrinks below 80% to fit; a long flow is panned.
- The attribution badge is hidden (`proOptions.hideAttribution`); the MIT licence allows this.

**7. D1:** Tasks are reachable by every department for `tasks.index/show/edit/update`. A Department User
or a My Work account sees and manages only tasks assigned to them or created by them (`Task::visibleTo`),
whatever their role. Creating and deleting stay closed to them.

## Consequences

- No running permit changes route when this is deployed. A company that never opts in sees only the
  Settings changes.
- The verifier list on the permit page says why somebody cannot verify, so "why can't I?" has an answer.
- The `skipped` status widens an ENUM. That migration is **not additive**: ADR 076's policy holds it for a
  person's approval at deploy, and it should be.
- My Work accounts used to see the whole task register. They now see their own tasks only, as D1 requires.
- Not yet built:
  - activation at the start time (M3);
  - Final Verification on modules other than PTW;
  - a draft state for flows (today every save publishes; the version history is the record).

## Tests

- `PtwApprovalFlowTest` (17): organisation structures; each A8b rule; refused configurations; refused
  submission; edits and removal under way; return and resubmission; legacy route untouched.
- `ApprovalFlowVersionTest` (11): P1–P4 and the scheduler escalation together.
- `CrossDepartmentTasksTest` (6).
- `ApprovalFlowReviewSeederTest`.
