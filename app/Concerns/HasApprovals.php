<?php

namespace App\Concerns;

use App\Models\Approval;
use App\Models\User;

/**
 * Universal Approval Engine (v1.6.9) -- the actual reuse mechanism.
 * Any model that wants Draft -> Submitted -> Approved/Rejected adds this
 * one trait and calls `submitForApproval()`; `MaterialRequest` is the
 * first real consumer. A future PPE Replacement Request, Permit To Work,
 * Purchase Request, Asset Request, or Inspection model would do exactly
 * the same thing, with zero new approval-specific tables, controllers,
 * or frontend logic of its own.
 */
trait HasApprovals
{
    public function approvals()
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function latestApproval(): ?Approval
    {
        return $this->approvals()->latest()->first();
    }

    /**
     * Creates a new pending Approval record for this model instance.
     * Idempotent in the sense that calling it again while a pending
     * approval already exists just returns the existing one, rather than
     * creating a duplicate -- resubmitting an already-submitted request
     * isn't meant to spawn a second parallel approval.
     */
    public function submitForApproval(User $requester): Approval
    {
        $existing = $this->approvals()->where('status', Approval::STATUS_PENDING)->first();

        if ($existing) {
            return $existing;
        }

        return $this->approvals()->create([
            'status' => Approval::STATUS_PENDING,
            'requested_by' => $requester->id,
        ]);
    }
}
