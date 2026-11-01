<?php

namespace App\Services;

use App\Concerns\HasWorkflow;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalFlowVersion;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Approvals\ApprovalCandidates;
use App\Services\Approvals\SeparationOfDuties;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        private readonly SeparationOfDuties $duties,
        private readonly ApprovalCandidates $candidates,
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
                // v2.100.0 -- captured now, never recomputed. See
                // Approval's own doc comment.
                'requester_name' => $requester->displayName(),
            ]);
            $this->notifyApprovers($approvable, config('workflow.approvers', []), null);

            return $approval;
        }

        /*
         * v2.141.0 -- THE REQUEST KEEPS THE ROUTE IT STARTED ON. Pinned to
         * the flow's current published version, so a later edit or removal
         * of the flow changes only requests that start afterwards.
         */
        $version = ApprovalFlowVersion::currentFor($flow, $requester);

        return $this->createStepApprovals($approvable, $flow->id, $version, 1, $requester);
    }

    /**
     * Records one approver's decision on one Approval row, then advances
     * the chain (legacy path: finalizes immediately; flow path: checks
     * whether the current step's mode is satisfied, then either creates
     * the next step's approvals or finalizes the approvable).
     */
    public function decide(Approval $approval, User $user, string $decision, ?string $comments = null): void
    {
        // v2.141.0 -- one decision per row: two approvers pressing at the
        // same moment must not both pass the pending check.
        DB::transaction(function () use ($approval, $user, $decision, $comments) {
            $locked = Approval::query()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();
            $approval->setRawAttributes($locked->getAttributes(), true);
            $this->decideLocked($approval, $user, $decision, $comments);
        });
    }

    private function decideLocked(Approval $approval, User $user, string $decision, ?string $comments): void
    {
        if ($approval->status !== Approval::STATUS_PENDING) {
            throw ValidationException::withMessages(['approval' => 'This approval has already been decided.']);
        }

        /*
         * v2.100.0 -- THE DECISION IS WHERE THE SIGNATURE IS GIVEN, so it
         * is where the name is recorded. Resolving it later, through
         * `approved_by`, meant a printed permit's approval block changed
         * whenever that person was renamed and emptied when they were
         * removed -- on documents that are the compliance record.
         */
        $approval->update([
            'status' => $decision,
            'approved_by' => $user->id,
            'approver_name' => $user->displayName(),
            'decided_at' => now(),
            'comments' => $comments,
        ]);

        if (! $approval->approval_flow_id && ! $approval->approval_flow_version_id) {
            $this->finalize($approval->approvable, $decision, $user, $comments);

            return;
        }

        $this->closeDecidedStage($approval->fresh());
        $this->advance($approval->fresh(), $user, $comments);
    }

    /**
     * v2.141.0 (P4) -- once a stage is DECIDED, nobody else's row at it is
     * still waiting. A rejection decides the stage; so does the first
     * approval on an "any one approver" stage. On an "all approvers" stage
     * the other rows stay pending until each one decides.
     */
    private function closeDecidedStage(Approval $decided): void
    {
        $mode = $this->stepsFor($decided, (int) $decided->step_number)->first()?->mode ?? ApprovalFlowStep::MODE_SINGLE;
        $decidesStage = $decided->status === Approval::STATUS_REJECTED || $mode !== ApprovalFlowStep::MODE_PARALLEL_ALL;
        if (! $decidesStage) {
            return;
        }

        Approval::query()
            ->where('approvable_type', $decided->approvable_type)
            ->where('approvable_id', $decided->approvable_id)
            ->where('step_number', $decided->step_number)
            ->where('status', Approval::STATUS_PENDING)
            ->whereKeyNot($decided->getKey())
            ->when(
                $decided->approval_flow_version_id,
                fn ($query) => $query->where('approval_flow_version_id', $decided->approval_flow_version_id),
                fn ($query) => $query->where('approval_flow_id', $decided->approval_flow_id),
            )
            ->get()
            ->each(fn (Approval $sibling) => $sibling->update([
                'status' => Approval::STATUS_SKIPPED,
                'decided_at' => now(),
                'comments' => 'Not required: this stage was already decided.',
            ]));
    }

    /**
     * Checks whether a given user is allowed to decide on this specific
     * Approval row -- flow-aware (matches the step's approver_role/
     * approver_user_id) with a fallback to the legacy
     * `config('workflow.approvers')` check when there's no flow.
     */
    public function authorize(Approval $approval, User $user): bool
    {
        // v2.63.0 -- TENANCY FIRST, CAPABILITY SECOND.
        //
        // Both branches below answer "does this person hold the role that
        // decides this step". Neither asked whose record it is. `approvals`
        // has no company_id and the route binds it by id, so a Super Admin
        // of tenant A could approve or reject tenant B's material request,
        // purchase order or permit -- a cross-tenant WRITE, reachable by
        // guessing an integer.
        //
        // The check reuses the scopes rather than adding a rule: an
        // approval's `approvable` is a morphTo onto the module's own model,
        // every one of which now carries CompanyOwnedScope, so a foreign
        // subject simply does not resolve. `null` therefore means either
        // "belongs to another tenant" or "the underlying record is gone",
        // and neither is something to decide on.
        if (! $approval->approvable) {
            return false;
        }

        if (! $approval->approval_flow_id && ! $approval->approval_flow_version_id) {
            $approvers = config('workflow.approvers', []);

            return $user->isSuperAdmin() || in_array($user->role, $approvers, true);
        }

        $steps = $this->stepsFor($approval, (int) $approval->step_number);

        /*
         * v2.141.0 -- A8b, ON THE SERVER. A Final Verification stage is never
         * decided by the requester or by anybody who approved an earlier
         * stage of the same record -- whatever their role, whatever an
         * escalation says, and with no Super Admin override.
         */
        if (SeparationOfDuties::isFinalStage($steps) && $this->duties->conflicts($approval, $user)) {
            return false;
        }

        // An escalation widens who may decide THIS request, and nothing else.
        if ($approval->is_escalated && $approval->escalated_to_role && $user->role === $approval->escalated_to_role) {
            return true;
        }

        return $steps->contains(fn (ApprovalFlowStep $step) => $step->matchesApprover($user));
    }

    /** v2.141.0 -- whether this row belongs to a Final Verification stage. */
    public function isFinalVerification(Approval $approval): bool
    {
        return SeparationOfDuties::isFinalStage($this->stepsFor($approval, (int) $approval->step_number));
    }

    /**
     * The people who could verify this record at its Final Verification
     * stage, given who requested it and who has approved it so far.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function eligibleFinalVerifiers(Model $approvable, ApprovalFlowVersion $version, ?int $requesterId = null): \Illuminate\Support\Collection
    {
        $final = $version->stepModels()->filter(fn (ApprovalFlowStep $step) => $step->isFinalVerification());

        return $this->duties->eligible(
            $this->candidates->forRows($final, $version->tenant_id),
            $this->duties->excludedFor($approvable, $requesterId),
        );
    }

    /**
     * v2.141.0 -- the step definitions a request is decided against: those
     * of the version it was pinned to, or -- for a request started before
     * versions existed -- the flow's live steps, as it always was.
     *
     * @return Collection<int, ApprovalFlowStep>
     */
    private function stepsFor(Approval $approval, int $stepNumber): Collection
    {
        if ($approval->approval_flow_version_id) {
            $version = ApprovalFlowVersion::withoutGlobalScopes()->find($approval->approval_flow_version_id);

            return $version ? $version->stepModels($stepNumber) : new Collection;
        }

        return ApprovalFlowStep::where('approval_flow_id', $approval->approval_flow_id)
            ->where('step_number', $stepNumber)
            ->get();
    }

    /** Whether the route a request runs on has a step with this number. */
    private function hasStep(Approval $approval, int $stepNumber): bool
    {
        return $this->stepsFor($approval, $stepNumber)->isNotEmpty();
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

        // v2.141.0: read from the pinned version, not the live flow -- the
        // flow may have been edited, or removed, since this request began.
        $flowId = (int) $decidedApproval->approval_flow_id;
        $stepNumber = (int) $decidedApproval->step_number;

        $siblingApprovals = $approvable->approvals()
            ->when(
                $decidedApproval->approval_flow_version_id,
                fn ($query) => $query->where('approval_flow_version_id', $decidedApproval->approval_flow_version_id),
                fn ($query) => $query->where('approval_flow_id', $flowId),
            )
            ->where('step_number', $stepNumber)
            ->get();

        $stepDefinitions = $this->stepsFor($decidedApproval, $stepNumber);
        $mode = $stepDefinitions->first()?->mode ?? ApprovalFlowStep::MODE_SINGLE;

        $stepSatisfied = match ($mode) {
            ApprovalFlowStep::MODE_PARALLEL_ALL => $siblingApprovals->every(fn (Approval $a) => $a->status === Approval::STATUS_APPROVED),
            default => $siblingApprovals->contains(fn (Approval $a) => $a->status === Approval::STATUS_APPROVED),
        };

        if (! $stepSatisfied) {
            return; // still waiting on other approvers at this step
        }

        $nextStepNumber = $stepNumber + 1;
        $hasNextStep = $this->hasStep($decidedApproval, $nextStepNumber);

        if (! $hasNextStep) {
            $this->finalize($approvable, Approval::STATUS_APPROVED, $user, $comments);

            return;
        }

        $version = $decidedApproval->approval_flow_version_id
            ? ApprovalFlowVersion::withoutGlobalScopes()->find($decidedApproval->approval_flow_version_id)
            : null;

        $this->createStepApprovals($approvable, $flowId, $version, $nextStepNumber, $decidedApproval->requester);
    }

    /**
     * @return Collection<int, Approval>
     */
    private function createStepApprovals(Model $approvable, int $flowId, ?ApprovalFlowVersion $version, int $stepNumber, User $requester): Collection
    {
        $stepDefinitions = $version
            ? $version->stepModels($stepNumber)
            : ApprovalFlowStep::where('approval_flow_id', $flowId)->where('step_number', $stepNumber)->get();

        $approvals = $stepDefinitions->map(fn (ApprovalFlowStep $step) => $approvable->approvals()->create([
            'status' => Approval::STATUS_PENDING,
            'requested_by' => $requester->id,
            'requester_name' => $requester->displayName(),
            // Null once the flow itself was removed; the version still holds the route.
            'approval_flow_id' => $flowId ?: null,
            'approval_flow_version_id' => $version?->id,
            'step_number' => $stepNumber,
        ]));

        if (SeparationOfDuties::isFinalStage($stepDefinitions)) {
            // A8b: only the people who may verify are told.
            $eligible = $this->duties->eligible(
                $this->candidates->forRows($stepDefinitions, $version?->tenant_id ?? app(CurrentTenant::class)->id()),
                $this->duties->excludedFor($approvable, $requester->id),
            );
            $eligible->each(fn (User $verifier) => $this->notifyApprover($verifier, $approvable));

            return $approvals;
        }

        foreach ($stepDefinitions as $step) {
            if ($step->approver_user_id) {
                $user = User::find($step->approver_user_id);
                if ($user) {
                    $this->notifyApprover($user, $approvable);
                }
            } elseif ($step->approver_role) {
                $this->notifyApprovers($approvable, [$step->approver_role], $stepNumber);
            } elseif ($step->approver_position_id) {
                // Everybody in this tenant holding that position.
                User::query()
                    ->where('is_active', true)
                    ->whereHas('employee', fn ($query) => $query->where('position_id', $step->approver_position_id))
                    ->get()
                    ->each(fn (User $holder) => $this->notifyApprover($holder, $approvable));
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
        $title = $this->labelOf($approvable).' '.$this->displayNumberOf($approvable).' needs your approval'.
            ($stepNumber ? " (step {$stepNumber})" : '');

        foreach ($roles as $role) {
            $this->notifier->notifyRole($role, Notification::CATEGORY_APPROVAL, $title, null, $this->urlOf($approvable), $approvable, $this->metaOf($approvable));
        }
    }

    private function notifyApprover(User $user, Model $approvable): void
    {
        $this->notifier->notify(
            $user,
            Notification::CATEGORY_APPROVAL,
            $this->labelOf($approvable).' '.$this->displayNumberOf($approvable).' needs your approval',
            null,
            $this->urlOf($approvable),
            $approvable,
            $this->metaOf($approvable)
        );
    }

    /**
     * v2.125.0 -- the product's name for the record, not its class name.
     *
     * "PermitToWork PTW-2026-0001 needs your approval" was being delivered
     * verbatim. `HasWorkflow::documentLabel()` is the one place that
     * decides this, and every approvable in this codebase uses that trait;
     * the fallback covers one that someday does not, without ever letting
     * a raw class name through.
     *
     * @see HasWorkflow::documentLabel()
     */
    /**
     * v2.141.0 -- how an approvable record is NAMED and REACHED, for any
     * surface that lists it (My Work), on the same terms its notifications
     * use (ADR 064): its document label, its number, its URL and route.
     *
     * @return array{label: string, number: string, url: ?string, route: ?string}
     */
    public function describe(Model $approvable): array
    {
        return [
            'label' => $this->labelOf($approvable),
            'number' => $this->displayNumberOf($approvable),
            'url' => $this->urlOf($approvable),
            'route' => method_exists($approvable, 'notificationRouteName') ? $approvable->notificationRouteName() : null,
        ];
    }

    private function labelOf(Model $approvable): string
    {
        return method_exists($approvable, 'documentLabel')
            ? $approvable->documentLabel()
            : Str::headline(class_basename($approvable));
    }

    /**
     * WHERE AN APPROVAL REQUEST SHOULD OPEN.
     *
     * v2.125.0 -- this was `null`, so "needs your approval" was a sentence
     * and nothing else: the approver tapped it, it went grey, and they then
     * went looking for the record by hand. The destination comes from the
     * model itself (`notificationUrl()`), which is the same method the
     * status-change notification uses, so an approver and a requester
     * cannot be sent to two different places for one record.
     *
     * Null when the model names no route -- a notification that opens
     * nothing is better than one that opens a refusal.
     */
    private function urlOf(Model $approvable): ?string
    {
        return method_exists($approvable, 'notificationUrl')
            ? $approvable->notificationUrl()
            : null;
    }

    /**
     * The destination's route NAME, stored on the row beside the url so a
     * surface can check it against the reader's own allow-list before
     * offering the click. @see \App\Concerns\HasWorkflow
     */
    private function metaOf(Model $approvable): array
    {
        return method_exists($approvable, 'notificationRouteName')
            ? array_filter(['route' => $approvable->notificationRouteName()])
            : [];
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
        // v2.141.0 -- a model whose lifecycle is more than one status move
        // (PTW: the Final Verification records the verifier and makes the
        // permit ready for activation) finalises itself.
        if (method_exists($approvable, 'finalizeApprovalFlow')) {
            $approvable->finalizeApprovalFlow($decision, $user, $comments);

            return;
        }

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
            ->where(fn ($query) => $query->whereNotNull('approval_flow_id')->orWhereNotNull('approval_flow_version_id'))
            ->where('is_escalated', false)
            ->chunkById(100, function (Collection $pending) use (&$escalated) {
                foreach ($pending as $approval) {
                    /*
                     * v2.141.0 -- EACH APPROVAL IN ITS OWN TENANT.
                     *
                     * This runs hourly from the scheduler, where no request
                     * has resolved a tenant. The FLOW is tenant-scoped and
                     * fails closed, so `$approval->flow` was always null here
                     * and no step ever escalated in production. The flow is
                     * read unscoped, and the approval is then processed --
                     * step rows, the update and the role notification --
                     * inside that flow's own tenant, never another's.
                     */
                    $flow = ApprovalFlow::withoutGlobalScopes()->with(['steps' => fn ($q) => $q->withoutGlobalScopes()])->find($approval->approval_flow_id);
                    // A pinned request still escalates after its flow was
                    // removed: the tenant comes from the version it runs on.
                    $tenantId = $flow?->tenant_id ?? ($approval->approval_flow_version_id
                        ? ApprovalFlowVersion::withoutGlobalScopes()->whereKey($approval->approval_flow_version_id)->value('tenant_id')
                        : null);
                    $tenant = $tenantId ? Tenant::find($tenantId) : null;

                    if ((! $flow && ! $approval->approval_flow_version_id) || ! $tenant) {
                        continue;
                    }

                    $current = app(CurrentTenant::class);
                    $previous = $current->get();
                    $current->set($tenant);

                    try {
                        $escalated += $this->escalateIfDue($approval, $flow);
                    } finally {
                        $current->set($previous);
                    }
                }
            });

        return $escalated;
    }

    /** One pending approval, inside its flow's tenant. Returns 1 when it escalated. */
    private function escalateIfDue(Approval $approval, ?ApprovalFlow $flow): int
    {
        $steps = $approval->approval_flow_version_id
            ? $this->stepsFor($approval, (int) $approval->step_number)
            : ($flow?->steps ?? new Collection)->where('step_number', $approval->step_number);
        $stepDefinition = $steps->first(fn (ApprovalFlowStep $s) => $s->escalate_after_hours !== null);

        if (! $stepDefinition || ! $stepDefinition->escalate_to_role) {
            return 0;
        }

        $deadline = $approval->created_at->addHours($stepDefinition->escalate_after_hours);
        if (now()->lessThan($deadline)) {
            return 0;
        }

        // Escalation widens WHO can decide the existing pending
        // Approval row -- it does not create a second Approval
        // row (that would leave a dangling duplicate once
        // either approver decides). `authorize()` checks every
        // ApprovalFlowStep at this step_number, so adding one
        // more row here is immediately enough for the
        // escalate_to_role to act on the SAME pending approval.
        // v2.141.0: recorded on THIS approval. It used to add a permanent
        // step row to the flow, which made the escalation role an approver
        // of every later request through that flow as well.
        $approval->update([
            'is_escalated' => true,
            'escalated_at' => now(),
            'escalated_to_role' => $stepDefinition->escalate_to_role,
        ]);

        $this->notifier->notifyRole(
            $stepDefinition->escalate_to_role,
            Notification::CATEGORY_WARNING,
            $this->labelOf($approval->approvable).' '.$this->displayNumberOf($approval->approvable).' was escalated to you: no decision after '.$stepDefinition->escalate_after_hours.'h',
            null,
            $this->urlOf($approval->approvable),
            $approval->approvable,
            $this->metaOf($approval->approvable)
        );

        return 1;
    }
}
