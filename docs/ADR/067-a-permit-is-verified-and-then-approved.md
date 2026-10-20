# ADR 067 — A permit is verified, and then approved

- **Status:** Accepted
- **Date:** 2026-10-20
- **Release:** v2.127.0
- **Supersedes:** the single approval gate ADR 065 describes for Permit to Work ("PTW has one gate")
- **Related:** [[065-a-stamp-is-an-action-record-and-its-title-belongs-to-the-company]],
  [[064-a-notification-is-a-destination-not-a-sentence]],
  [[061-an-offer-is-bound-to-what-the-server-allows]]

---

## The decision

A Permit to Work passes **two** gates, in order, held by **two separately configured parties**:

```
Draft → Submitted → Verified → Approved → Active → Closed
         ▲            ▲           ▲
         │            │           └── STAGE 2 · FINAL APPROVAL  (approve_roles / approve_user_ids)
         │            └────────────── STAGE 1 · HSE VERIFICATION (verify_roles  / verify_user_ids)
         └─────────────────────────── the requester
```

The order is enforced by the **state machine**, not by a controller check: `PermitToWork::$transitions`
no longer contains `submitted → approved`. A caller cannot skip verification by calling the
transition route directly, by replaying a stale form, or by holding both authorities — the
destination simply does not exist from `submitted`.

`ptw_workflow_settings` is a table with a unique `company_id` and four nullable JSON columns
(`verify_roles`, `verify_user_ids`, `approve_roles`, `approve_user_ids`). `PtwWorkflowService` is the
one place that reads it, and both the gate and the button ask it the same question (ADR 061, fourth
application).

## Why a table, and why both roles and users per stage

`CompanySetting` is keyed by tenant, and a tenant may operate several companies. Three yards under
one account genuinely route verification to three different HSE supervisors; a tenant-keyed setting
cannot say so. So the configuration is company-owned, through `BelongsToCompany`, and
`updatePtwWorkflow()` resolves the posted `company_id` through `Company::query()` — i.e. through
`TenantScope` — so one tenant cannot write another's routing.

Roles alone are not enough (a yard with four HSE officers wants one of them to be the verifier), and
users alone are not enough (a company that has not yet named individuals still needs the permit to
reach somebody). Both are stored, and `canAct()` is a union: the user's role is listed, **or** the
user's id is listed. A stage left empty falls back to that stage's default
(`defaults()`: verify → Super Admin + HSE, approve → Super Admin + `config('workflow.approvers')`),
so configuring one stage can never silently disable the other.

## Why the second notification cannot be early

`recipientsFor()` derives its audience from the permit's **current status**, not from the action being
taken: `pendingStage()` maps `submitted → verify` and `verified → approve`. There is no code path
that can address the approver while the permit is still `submitted`, because there is no code that
takes a stage as an argument from the caller. The approver is notified by the verification having
been recorded, which is the sequencing the requirement asks for, expressed as data rather than as
discipline.

## Why the decision is committed before the notification is attempted

The transition runs inside `DB::transaction` with `lockForUpdate()->firstOrFail()`, and the fresh row
is pushed back onto the in-memory model with `setRawAttributes($fresh->getAttributes(), true)`. Two
reviewers pressing *Approve* at the same moment serialise; the second sees `verified → approved`
already spent and the transition is refused rather than double-recorded.

`notifyNextStage()` is called **after** the transaction commits, wrapped in try/catch with `report()`.
`HasWorkflow::notifyStatusChange()` needed the same treatment and did not have it: it notified
*inside* the caller's transaction, so a throwing notifier rolled the approval decision back. A test
that made the notifier throw produced a 500 and lost a recorded verification — which had been
silently true for **every** workflow model in the product, not just PTW. The rule now: a decision is
committed first; telling somebody about it is best-effort and never undoes it.

## Why verify authority does not imply approve authority

They are separate columns, asked by separate methods (`canVerify`, `canApprove`), and the controller
authorises per target:

```php
$allowed = match ($target) {
    PermitToWork::STATUS_VERIFIED => $workflow->canVerify($user, $permitToWork),
    PermitToWork::STATUS_APPROVED => $workflow->canApprove($user, $permitToWork),
    ...
};
abort_unless($allowed, 403);
```

The separation is real enough that it broke three existing tests: an `hse` account legitimately cannot
approve under the defaults, so `PermitToWorkApprovalStampTest` and two cases in `PtwFieldRevisionTest`
needed a `letHseHoldBothGates()` fixture. That is the requirement working, not a regression.

`Activate` is deliberately **wider** than `canManageHse()`. It was not, and a company that routed
approval to a manager ended up with an approved permit nobody on screen could activate — found in the
browser, not in a test. Activation is now open to either gate-holder as well as HSE.

## The stamps

Two action stamps, drawn by the one server-side `StampRenderer` (ADR 065): **VERIFIED green** and
**APPROVED blue**, each naming the actor, the job title the company held for them *at the moment they
acted* (`activity_logs.actor_title`), and the time. A permit awaiting either stage renders **no**
stamp for it — the renderer still cannot produce a seal without a server-side authorization record,
so "awaiting" cannot look like "granted". The state stamps are unchanged: ACTIVE green, CLOSED red,
slanted, distressed, dateless.

`verificationFor()` reads the `verified` activity row rather than the verifier's current profile, for
the same reason ADR 065 captured the title at action time: a reorganisation must not rewrite a filed
permit. It falls back to the live position, then to the role label, only when no historical title was
captured (permits verified before this release have none — nothing was backfilled).

## The PDF

The signature block gained an **HSE VERIFIED BY** column, "HSE Approver" became **Approved By**, and
the always-blank *Area Authority* column was removed: four columns crushed the seals to the point
where neither was legible. Two new status presets name the waiting room honestly — *Awaiting HSE
Verification* and *Verified: Awaiting Final Approval*.

## What was rejected

- **A boolean `is_verified` flag.** A flag records that it happened, not who did it or when. The
  requirement is an audit trail; the status, the actor FK (`hse_verifier_id`), the timestamps and the
  activity row are all four needed.
- **Hiding the Approve button and leaving one gate on the server.** The directive said so explicitly,
  and it would have been wrong anyway: the state machine is the cheapest place to make the order
  impossible to violate.
- **Backfilling existing permits.** An approved permit from before this release was approved under a
  single gate. Writing a `verified_at` for it would be inventing a verification that no one performed.
  They stay as they are; only the two gates' columns are null.

## How it was verified

Driven end to end in a browser against MySQL `ioms_ui` with synthetic data, not read:

- Settings → **Permit to Work Workflow** renders the Operating Unit select and both stage panels with
  role chips and the tenant's own people; saving stage 1 = `["hse"]`, stage 2 = `["manager"]` landed in
  `ptw_workflow_settings` for that company id and for no other.
- A submitted permit showed *Awaiting HSE Verification*, offered **Verify** to the HSE account and
  **nothing** to the manager; after verification the manager was notified (not before) and offered
  **Approve**; the permit became Active only after that.
- The re-rendered PDF carries the green VERIFIED seal (`Hse Audit-business / SPV HSE / 20 Oct 2026`)
  and the blue APPROVED seal (`Manager Audit-business / MANAGER`) — the latter previously printed a
  hardcoded `HSE` kind label, visible on the rendered sheet and fixed.
- `tests/Feature/PtwTwoStageWorkflowTest.php` — 29 tests covering all twelve required scenarios plus
  configuration, tenant isolation, notification-failure and activation cases.

---

See also: `docs/MODULES.md` § Permit to Work · `docs/ARCHITECTURE.md` § Workflow Engine
