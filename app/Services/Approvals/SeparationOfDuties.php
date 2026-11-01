<?php

namespace App\Services\Approvals;

use App\Models\Approval;
use App\Models\ApprovalFlowStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * v2.141.0 -- A8b, AS DECIDED BY THE OWNER.
 *
 *   1. The requester is never the Final Verifier of their own record.
 *   2. Somebody who approved any stage of the same record is never its
 *      Final Verifier.
 *   3./4. HSE, a Project Manager or anybody else may be an approver or the
 *      Final Verifier -- whoever the company's flow names (5).
 *   6. Enforced HERE, on the server: ApprovalEngine::authorize() asks this
 *      before any decision, so a hidden button is never the control.
 *
 * Applies to Final Verification steps. Ordinary approval steps keep their
 * existing rules.
 */
class SeparationOfDuties
{
    /** Who may not be the Final Verifier of this record, and why. */
    public function excludedFor(Model $approvable, ?int $requesterId = null): array
    {
        $excluded = [];

        $requesterId ??= $approvable->getAttribute('requested_by');
        if ($requesterId) {
            $excluded[(int) $requesterId] = 'requester';
        }

        if (method_exists($approvable, 'approvals')) {
            foreach ($approvable->approvals()->where('status', Approval::STATUS_APPROVED)->whereNotNull('approved_by')->pluck('approved_by') as $id) {
                $excluded[(int) $id] ??= 'approved_earlier_stage';
            }
        }

        return $excluded;
    }

    public function conflicts(Approval $approval, User $user): bool
    {
        $approvable = $approval->approvable;
        if (! $approvable) {
            return true;
        }

        return array_key_exists((int) $user->id, $this->excludedFor($approvable, $approval->requested_by));
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function eligible(Collection $candidates, array $excluded): Collection
    {
        return $candidates->reject(fn (User $user) => array_key_exists((int) $user->id, $excluded));
    }

    /** @param  iterable<ApprovalFlowStep>  $rows */
    public static function isFinalStage(iterable $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->isFinalVerification()) {
                return true;
            }
        }

        return false;
    }
}
