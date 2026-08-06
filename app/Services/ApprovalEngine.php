<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Milestone 3 (Universal Approval Engine v2). The single place that
 * knows how to start and advance an approval -- single-step (legacy,
 * unconfigured), multi-level, parallel (any/all), and conditional flow
 * selection all go through here. `App\Concerns\HasApprovals` is a thin
 * adapter over this; `ApprovalController` calls `decide()` directly.
 *
 * LEGACY COMPATIBILITY IS THE LOAD-BEARING DESIGN CONSTRAINT: when
 * `ApprovalFlowResolver` finds no configured `ApprovalFlow` for a module
 * (true for every module today -- MaterialRequest, LeaveRequest -- until
 * someone configures one via Task #57's future Settings UI), this class
 * behaves EXACTLY like the pre-Milestone-3 single-step engine: one
 * Approval row, `config('workflow.approvers')` authorizes it, one
 * decision finalizes the approvable via its own `transitionTo()`. See
 * docs/ADR/010-approval-engine-v2.md.
 */
class ApprovalEngine
{
    public function __construct(
        private readonly ApprovalFlowResolver $resolver,
        private readonly NotificationService $notifier,
    ) {}

    /**
     * Starts an approval for $approvable. Idempotent: if a pending
     * approval already exists (any step), returns it/them instead of
     * creating a duplicate.
     *
     * @return Approval|Collection<int, Approval>
     */
    public function start(Model $approvable, User $requester)
    {
        $existing = $approvable->approvals()->where('status', Approval::STATUS_PENDING)->get();
        if ($existing->isNotEmpty()) {
            return $existing->count() === 1 ? $existing->first() : $existing;
        }

        $moduleKey = $this->moduleKeyFor($approvable);
        $flow = $this->resolver->resolve($moduleKey, $approvable);

        if (! $flow) {
            $approval = $approvable->approvals()->create([
                'status' => Approval::STATUS_PENDING,
                'requested_by' => $requester->id,
            ]);
            $this->notifyApprovers($approvable, config('workflow.approvers', []), null);

            return $approval;
        }

        return $this->createStepApprovals($approvable, $flow, 1, $requester);
    }

    /**
     * Records one approver's decision on one Approval row, then advances
     * the chain (legacy path: finalizes immediately; flow path: checks
     * whether the current step's mode is satisfied, then either creates
     * the next step's approvals or finalizes the approvable).
     */
    public function decide(Approval $approval, User $user, string $decision, ?string $comments = null): void
    {
        if ($approval->status !== Approval::STATUS_PENDING) {
            throw ValidationException::withMessages(['approval' => 'This approval has already been decided.']);
        }

        $approval->update([
            'status' => $decision,
            'approved_by' => $user->id,
            'decided_at' => now(),
            'comments' => $comments,
        ]);

        if (! $approval->approval_flow_id) {
            $this->finalize($approval->approvable, $decision, $user, $comments);

            return;
        }

        $this->advance($approval->fresh(), $user, $comments);
    }

    /**
     * Checks whether a given user is allowed to decide on this specific
     * Approval row -- flow-aware (matches the step's approver_role/
     * approver_user_id) with a fallback to the legacy
     * `config('workflow.approvers')` check when there's no flow.
     */
    public function authorize(Approval $approval, User $user): bool
    {
        if (! $approval->approval_flow_id) {
            $approvers = config('workflow.approvers', []);

            return $user->isSuperAdmin() || in_array($user->role, $approvers, true);
        }

        return ApprovalFlowStep::where('approval_flow_id', $approval->approval_flow_id)
            ->where('step_number', $approval->step_number)
            ->get()
            ->contains(fn (ApprovalFlowStep $step) => $step->matchesApprover($user));
    }

    private function advance(Approval $decidedApproval, User $user, ?string $comments): void
    {
        $approvable = $decidedApproval->approvable;

        if ($decidedApproval->status === Approval::STATUS_REJECTED) {
            // One rejection anywhere in the chain rejects the whole thing --
            // no "parallel_all must all reject" ambiguity to resolve.
            $this->finalize($approvable, Approval::STATUS_REJECTED, $user, $comments);

            return;
        }

        $flow = $decidedApproval->flow;
        $stepNumber = $decidedApproval->step_number;

        $siblingApprovals = $approvable->approvals()
            ->where('approval_flow_id', $flow->id)
            ->where('step_number', $stepNumber)
            ->get();

        $stepDefinitions = $flow->steps()->where('step_number', $stepNumber)->get();
        $mode = $stepDefinitions->first()?->mode ?? ApprovalFlowStep::MODE_SINGLE;

        $stepSatisfied = match ($mode) {
            ApprovalFlowStep::MODE_PARALLEL_ALL => $siblingApprovals->every(fn (Approval $a) => $a->status === Approval::STATUS_APPROVED),
            default => $siblingApprovals->contains(fn (Approval $a) => $a->status === Approval::STATUS_APPROVED),
        };

        if (! $stepSatisfied) {
            return; // still waiting on other approvers at this step
        }

        $nextStepNumber = $stepNumber + 1;
        $hasNextStep = $flow->steps()->where('step_number', $nextStepNumber)->exists();

        if (! $hasNextStep) {
            $this->finalize($approvable, Approval::STATUS_APPROVED, $user, $comments);

            return;
        }

        $this->createStepApprovals($approvable, $flow, $nextStepNumber, $decidedApproval->requester);
    }

    /**
     * @return Collection<int, Approval>
     */
    private function createStepApprovals(Model $approvable, ApprovalFlow $flow, int $stepNumber, User $requester): Collection
    {
        $stepDefinitions = $flow->steps()->where('step_number', $stepNumber)->get();

        $approvals = $stepDefinitions->map(fn (ApprovalFlowStep $step) => $approvable->approvals()->create([
            'status' => Approval::STATUS_PENDING,
            'requested_by' => $requester->id,
            'approval_flow_id' => $flow->id,
            'step_number' => $stepNumber,
        ]));

        foreach ($stepDefinitions as $step) {
            if ($step->approver_user_id) {
                $user = User::find($step->approver_user_id);
                if ($user) {
                    $this->notifyApprover($user, $approvable);
                }
            } elseif ($step->approver_role) {
                $this->notifyApprovers($approvable, [$step->approver_role], $stepNumber);
            }
        }

        return $approvals;
    }

    /**
     * Milestone 3 (Notification Center). Notifies every user in any of
     * $roles that a new approval is waiting on them -- used for the
     * legacy path (config('workflow.approvers')) and flow-based
     * role-assigned steps alike.
     */
    private function notifyApprovers(Model $approvable, array $roles, ?int $stepNumber): void
    {
        $title = class_basename($approvable).' '.$this->displayNumberOf($approvable).' needs your approval'.
            ($stepNumber ? " (step {$stepNumber})" : '');

        foreach ($roles as $role) {
            $this->notifier->notifyRole($role, Notification::CATEGORY_APPROVAL, $title, null, null, $approvable);
        }
    }

    private function notifyApprover(User $user, Model $approvable): void
    {
        $this->notifier->notify(
            $user,
            Notification::CATEGORY_APPROVAL,
            class_basename($approvable).' '.$this->displayNumberOf($approvable).' needs your approval',
            null,
            null,
            $approvable
        );
    }

    private function displayNumberOf(Model $approvable): string
    {
        foreach ($approvable->getAttributes() as $key => $value) {
            if (str_ends_with($key, '_number') && $value) {
                return $value;
            }
        }

        return '#'.$approvable->getKey();
    }

    private function finalize(Model $approvable, string $decision, User $user, ?string $comments): void
    {
        $targetStatus = $decision === Approval::STATUS_APPROVED
            ? $approvable::STATUS_APPROVED
            : $approvable::STATUS_REJECTED;

        $approvable->transitionTo($targetStatus, $user, null, ['comments' => $comments]);
    }

    /**
     * Default module key convention: snake_case of the class basename
     * (MaterialRequest -> material_request), matching
     * NumberGeneratorService's module keys exactly, so a single flow
     * config identifies both which numbering format AND which approval
     * flow apply to a module. A model can override by defining its own
     * `approvalModuleKey(): string` method.
     */
    private function moduleKeyFor(Model $approvable): string
    {
        if (method_exists($approvable, 'approvalModuleKey')) {
            return $approvable->approvalModuleKey();
        }

        return Str::snake(class_basename($approvable));
    }

    /**
     * Escalation sweep (Task #47/#49 integration point, run by the
     * `approvals:escalate` scheduled command). Finds pending flow-based
     * Approval rows whose step defines `escalate_after_hours` and that
     * window has elapsed, adds the configured `escalate_to_role` as an
     * ADDITIONAL approver at the same step (so either the original or
     * the escalation target can still decide it), and marks the
     * original row escalated so it's only escalated once.
     *
     * @return int number of approvals escalated
     */
    public function checkEscalations(): int
    {
        $escalated = 0;

        Approval::query()
            ->where('status', Approval::STATUS_PENDING)
            ->whereNotNull('approval_flow_id')
            ->where('is_escalated', false)
            ->with('flow.steps')
            ->chunkById(100, function (Collection $pending) use (&$escalated) {
                foreach ($pending as $approval) {
                    $stepDefinition = $approval->flow?->steps
                        ->where('step_number', $approval->step_number)
                        ->first(fn (ApprovalFlowStep $s) => $s->escalate_after_hours !== null);

                    if (! $stepDefinition || ! $stepDefinition->escalate_to_role) {
                        continue;
                    }

                    $deadline = $approval->created_at->addHours($stepDefinition->escalate_after_hours);
                    if (now()->lessThan($deadline)) {
                        continue;
                    }

                    // Escalation widens WHO can decide the existing pending
                    // Approval row -- it does not create a second Approval
                    // row (that would leave a dangling duplicate once
                    // either approver decides). `authorize()` checks every
                    // ApprovalFlowStep at this step_number, so adding one
                    // more row here is immediately enough for the
                    // escalate_to_role to act on the SAME pending approval.
                    ApprovalFlowStep::create([
                        'approval_flow_id' => $approval->approval_flow_id,
                        'step_number' => $approval->step_number,
                        'mode' => $stepDefinition->mode,
                        'approver_role' => $stepDefinition->escalate_to_role,
                    ]);

                    $approval->update(['is_escalated' => true, 'escalated_at' => now()]);

                    $this->notifier->notifyRole(
                        $stepDefinition->escalate_to_role,
                        Notification::CATEGORY_WARNING,
                        class_basename($approval->approvable).' '.$this->displayNumberOf($approval->approvable).' was escalated to you -- no decision after '.$stepDefinition->escalate_after_hours.'h',
                        null,
                        null,
                        $approval->approvable
                    );

                    $escalated++;
                }
            });

        return $escalated;
    }
}
