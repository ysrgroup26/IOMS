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
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
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

    /**
     * Updates only the Approval record's own fields. The caller is
     * responsible for transitioning the approvable model's own status
     * (via its HasWorkflow::transitionTo()) separately -- keeping this
     * method from also logging avoids two ActivityLog entries for what
     * is, from the user's perspective, a single decision.
     */
    public function approve(User $user, ?string $comments = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $user->id,
            'decided_at' => now(),
            'comments' => $comments,
        ]);
    }

    public function reject(User $user, ?string $comments = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'approved_by' => $user->id,
            'decided_at' => now(),
            'comments' => $comments,
        ]);
    }
}
