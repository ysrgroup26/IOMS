# 011 — Notification Center (Milestone 3)

## Status

Accepted.

## Problem

The only "notification" in the app was a bell icon hardcoded to one alert type (PPE expiring/expired
counts, a live computed query — never a persisted record). Milestone 3 requires a real, per-user,
multi-category (approval/reminder/warning/success/information) notification system that fires from
actual workflow events, not seeded/dummy data.

## Decision

**A plain app-owned `notifications` table, not Laravel's built-in notification channel system.** This
app doesn't use `Notifiable`/queued mailables/broadcast channels anywhere; adopting the full
multi-channel abstraction for a single in-app channel would be more machinery than the problem needs.
`App\Services\NotificationService` is the only class permitted to create a `Notification` row.

**Two, cleanly separated firing points — no duplication.**
- `App\Concerns\HasWorkflow::transitionTo()` notifies the record's **requester** (whoever should know
  "your thing changed status") on every transition, for every workflow-driven model, not just
  approval-driven ones. This is genuinely generic: `notificationRecipient()` defaults to checking
  `requester()`/`reporter()`/`creator()` relations then `requested_by`/`reported_by`/`created_by`
  columns, covering `MaterialRequest`, `LeaveRequest`, and `Incident` (`reported_by`) all with zero
  per-model code.
- `App\Services\ApprovalEngine` notifies **approvers** (whoever needs to act next) when a step's
  approvals are created — legacy path notifies `config('workflow.approvers')` roles; flow path
  notifies each step's `approver_role`/`approver_user_id`.

  These two firing points never overlap: `ApprovalEngine::finalize()` calls the approvable's own
  `transitionTo()`, which is what actually notifies the requester of the final decision — `finalize()`
  itself does not also notify, avoiding a double notification for approval-driven transitions.

**PPE alerts stay a live computed count, not persisted `Notification` rows.** Turning them into rows
would mean either re-computing membership on every PPE status change (a lot of write amplification for
data that's already cheap to query live) or accepting stale rows. They remain a pinned quick-link at
the top of the notification dropdown instead, preserving the exact pre-Milestone-3 behavior/URL.

**Escalation widens authorization, doesn't duplicate an Approval row** — see ADR-010's own note (this
was caught as a real bug during Milestone 3's own build, before shipping): the escalation-target role
is notified via `NotificationService::notifyRole()` with `CATEGORY_WARNING` when
`ApprovalEngine::checkEscalations()` (run hourly via `approvals:escalate`, `routes/console.php`) widens
a step.

## Verified via `tinker` and browser

- Requester notified on approval finalization (with correct self-notification suppression when
  requester and decider are the same user — confirmed both cases).
- Approvers notified with the correct role-targeted `notifyRole()` on submission.
- Logged in as `hse@ioms.local` in the browser: bell badge showed the real unread count, dropdown
  rendered the real notification with its title/comment/relative time, "Mark all read" correctly
  zeroed the unread count (confirmed via a follow-up DB query, not just the UI).

## Consequences

- No notification preferences UI yet (e.g. "don't notify me about X") — Task #57 (Company Settings
  completion) territory, not built now.
- `notifyRole()` sends one `Notification` row per matching active user per event — fine at today's
  scale (a handful of users per role per tenant); worth revisiting (batching/digest) if a
  high-volume-approval customer's approver role has dozens of members.
