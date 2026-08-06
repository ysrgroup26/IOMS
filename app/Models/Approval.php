<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'status',
        'requested_by',
        'approved_by',
        'decided_at',
        'comments',
        // Milestone 3 (Approval Engine v2) -- null approval_flow_id means
        // this row is on the legacy single-step path (see ApprovalFlow's
        // own doc comment).
        'approval_flow_id',
        'step_number',
        'is_escalated',
        'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'is_escalated' => 'boolean',
            'escalated_at' => 'datetime',
        ];
    }

    public function approvable()
    {
        return $this->morphTo();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    // Milestone 3: the old approve()/reject() instance methods (which
    // only updated this row's own fields) were removed -- calling them
    // directly would skip App\Services\ApprovalEngine's chain-advancement
    // and finalization logic, leaving a multi-step approvable stuck
    // forever or a legacy single-step one never transitioned. Use
    // app(ApprovalEngine::class)->decide($approval, $user, $decision, $comments)
    // instead; that is now the ONLY path that changes an Approval's status.
}
