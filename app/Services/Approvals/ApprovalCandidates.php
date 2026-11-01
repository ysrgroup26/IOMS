<?php

namespace App\Services\Approvals;

use App\Models\ApprovalFlowStep;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * v2.141.0 -- WHO, IN THIS TENANT, A STEP ACTUALLY NAMES.
 *
 * One answer for the three places that need it: the configuration
 * validator (is there anybody eligible at all?), the submission check (is
 * there anybody eligible for THIS requester?) and the notification when a
 * step opens. Active, full accounts of the tenant only -- a My Work account
 * is a field login and is never configured as a decider (ADR 062).
 *
 * The Super Admin override of matchesApprover() is deliberately NOT a
 * candidate source: an override is a fallback, not a configuration, and a
 * Final Verification step does not honour it at all.
 */
class ApprovalCandidates
{
    /** @return Collection<int, User> keyed by user id */
    public function forStep(ApprovalFlowStep $step, ?int $tenantId): Collection
    {
        if (! $tenantId) {
            return collect();
        }

        $query = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('user_type')->orWhere('user_type', '!=', User::TYPE_MY_WORK));

        if ($step->approver_user_id) {
            $query->whereKey($step->approver_user_id);
        } elseif ($step->approver_role) {
            $query->where('role', $step->approver_role);
        } elseif ($step->approver_position_id) {
            $query->whereNotNull('employee_id')
                ->whereIn('employee_id', function ($sub) use ($step) {
                    $sub->select('id')->from('employees')->where('position_id', $step->approver_position_id);
                });
        } else {
            return collect();
        }

        return $query->get()->keyBy('id');
    }

    /**
     * Everybody named by any row of a step.
     *
     * @param  iterable<ApprovalFlowStep>  $rows
     * @return Collection<int, User>
     */
    public function forRows(iterable $rows, ?int $tenantId): Collection
    {
        $all = collect();
        foreach ($rows as $row) {
            $all = $all->union($this->forStep($row, $tenantId));
        }

        return $all;
    }
}
