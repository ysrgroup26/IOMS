<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Universal Approval Engine v2). One row PER APPROVER at a
 * given step_number of an ApprovalFlow -- a parallel step is several
 * rows sharing the same step_number. See ApprovalFlow's own doc comment.
 */
class ApprovalFlowStep extends Model
{
    // v2.141.0 -- A13: a step's approver is part of the approval route.
    use RecordsChanges;

    public const KIND_APPROVAL = 'approval';

    /**
     * v2.141.0 -- A8b: the last stage of a PTW flow. Decided only by the
     * people it names (no Super Admin override), never by the requester and
     * never by somebody who approved an earlier stage of the same record.
     */
    public const KIND_FINAL_VERIFICATION = 'final_verification';

    public const MODE_SINGLE = 'single';

    public const MODE_PARALLEL_ANY = 'parallel_any';

    public const MODE_PARALLEL_ALL = 'parallel_all';

    protected $fillable = [
        'approval_flow_id',
        'step_number',
        'kind',
        'mode',
        'approver_role',
        'approver_user_id',
        // v2.141.0 -- a position from the company's own structure.
        'approver_position_id',
        'escalate_after_hours',
        'escalate_to_role',
    ];

    /** A step has no company of its own: it belongs to its flow's tenant. */
    public function recordChangeTenantId(): ?int
    {
        $tenantId = ApprovalFlow::withoutGlobalScopes()->whereKey($this->approval_flow_id)->value('tenant_id');

        return $tenantId ? (int) $tenantId : app(CurrentTenant::class)->id();
    }

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function isFinalVerification(): bool
    {
        return $this->kind === self::KIND_FINAL_VERIFICATION;
    }

    public function matchesApprover(User $user): bool
    {
        // A Final Verifier is exactly who the company named -- the Super
        // Admin override below does not reach this stage (A8b).
        $override = ! $this->isFinalVerification() && $user->isSuperAdmin();

        if ($this->approver_user_id) {
            return (int) $this->approver_user_id === (int) $user->id;
        }

        if ($this->approver_role) {
            return $override || $user->role === $this->approver_role;
        }

        /*
         * v2.141.0 -- by POSITION: whoever holds that position in the
         * company's own structure (users.employee_id -> employees.position_id),
         * on the same terms as a role step (a Super Admin may also decide).
         */
        if ($this->approver_position_id) {
            return $override
                || ($user->employee_id && (int) $user->employee?->position_id === (int) $this->approver_position_id);
        }

        return false;
    }
}
