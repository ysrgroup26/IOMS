<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\EmployeePpe;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Work Center (v1.8.0 -- Navigation Architecture Redesign, see
 * docs/ADR/007-workspace-navigation.md). NOT a Department -- a global,
 * cross-cutting aggregation of work assigned to the current user, sourced
 * entirely from engines that already exist (Universal Approval Engine,
 * Universal Task Engine, PPE alerts). No new record types are introduced
 * here; this service only queries and shapes existing data so the topbar
 * badge and the Work Center page share one implementation.
 */
class WorkCenterService
{
    /**
     * Pending approvals the given user is entitled to decide, per
     * config('workflow.approvers') -- the exact same rule
     * ApprovalController::authorizeDecision() already enforces, so this
     * list never shows an approval the user couldn't actually act on.
     *
     * Company scoping: only applied when the VIEWER has a company_id.
     * Most internal staff (managers, HSE, Super Admin) have a null
     * company_id by design (see User::company()'s own doc comment) --
     * scoping those users would hide every approval from the majority of
     * approvers, not narrow it usefully. A company-scoped user (a future
     * Company Admin from the tenant registration flow) only sees
     * approvals for their own company.
     */
    public function pendingApprovalsFor(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        $approvers = config('workflow.approvers', []);
        if (! $user->isSuperAdmin() && ! in_array($user->role, $approvers, true)) {
            return collect();
        }

        return Approval::query()
            ->where('status', Approval::STATUS_PENDING)
            ->with(['approvable', 'requester:id,name'])
            ->latest()
            ->get()
            ->filter(function (Approval $approval) use ($user) {
                $approvable = $approval->approvable;
                if (! $approvable) {
                    return false;
                }

                if ($user->company_id && ! empty($approvable->company_id)) {
                    return $approvable->company_id === $user->company_id;
                }

                return true;
            })
            ->values();
    }

    /** Open tasks assigned to the given user -- reuses Task's own scopes, no new query logic. */
    public function myTasksFor(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        return Task::query()
            ->assignedTo($user->id)
            ->openStatus()
            ->with('company:id,name')
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->get();
    }

    /**
     * PPE items expiring soon or already expired -- the exact query
     * HandleInertiaRequests already ran for the old PPE-only bell, moved
     * here so the topbar badge and the Work Center page use one
     * implementation instead of two copies drifting apart.
     */
    public function ppeAlertCount(): int
    {
        return EmployeePpe::query()->effectiveStatus('expiring_soon')->count()
            + EmployeePpe::query()->effectiveStatus('expired')->count();
    }
}
