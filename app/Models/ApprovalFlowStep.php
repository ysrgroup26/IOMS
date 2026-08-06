<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Universal Approval Engine v2). One row PER APPROVER at a
 * given step_number of an ApprovalFlow -- a parallel step is several
 * rows sharing the same step_number. See ApprovalFlow's own doc comment.
 */
class ApprovalFlowStep extends Model
{
    public const MODE_SINGLE = 'single';
    public const MODE_PARALLEL_ANY = 'parallel_any';
    public const MODE_PARALLEL_ALL = 'parallel_all';

    protected $fillable = [
        'approval_flow_id',
        'step_number',
        'mode',
        'approver_role',
        'approver_user_id',
        'escalate_after_hours',
        'escalate_to_role',
    ];

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function matchesApprover(User $user): bool
    {
        if ($this->approver_user_id) {
            return (int) $this->approver_user_id === (int) $user->id;
        }

        if ($this->approver_role) {
            return $user->isSuperAdmin() || $user->role === $this->approver_role;
        }

        return false;
    }
}
