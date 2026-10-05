<?php

namespace App\Concerns;

use App\Models\Approval;
use App\Models\User;
use App\Services\ApprovalEngine;
use Illuminate\Support\Str;

/**
 * Universal Approval Engine (v1.6.9; extended to v2 in Milestone 3) --
 * the actual reuse mechanism. Any model that wants Draft -> Submitted ->
 * Approved/Rejected adds this one trait and calls `submitForApproval()`.
 * `MaterialRequest`/`LeaveRequest` are the existing consumers, both on
 * the legacy single-step path (no `ApprovalFlow` configured for them) --
 * see `App\Services\ApprovalEngine`'s own doc comment for how a future
 * multi-level/parallel/conditional chain gets configured without any
 * change to this trait or the consuming model.
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
     * Starts (or returns the already-pending) approval for this record,
     * via the shared `ApprovalEngine` -- single-step or a configured
     * multi-step flow, transparently.
     */
    public function submitForApproval(User $requester)
    {
        return app(ApprovalEngine::class)->start($this, $requester);
    }

    /**
     * v2.100.0 -- WHO SIGNED, AS IT READ WHEN THEY SIGNED IT.
     *
     * A document's signature block was rendering `requester->name` and
     * `approver->name`, i.e. whatever those accounts are called TODAY.
     * Renaming somebody therefore rewrote every document they had ever
     * raised or approved, including ones already printed and filed.
     *
     * `approvals` now captures both names at the moment each is given, so
     * these two return the snapshot and fall back to the live relation only
     * for rows raised before it was captured -- which renders exactly what
     * those documents render today, and nothing is invented for either.
     */
    public function requesterDisplayName(): ?string
    {
        $approval = $this->approvals()->oldest()->first();

        return $approval?->requester_name
            ?: ($this->requester?->displayName() ?? null);
    }

    public function approverDisplayName(): ?string
    {
        $approval = $this->approvals()
            ->whereNotNull('approved_by')
            ->latest('decided_at')
            ->first();

        return $approval?->approver_name
            ?: ($approval?->approver?->displayName() ?? null);
    }

    /**
     * Module key used to look up an `ApprovalFlow` (and shared with
     * `NumberGeneratorService`'s numbering module keys) -- snake_case of
     * the class basename by default (MaterialRequest -> material_request).
     * Override this method on a consuming model if a different key is
     * needed.
     */
    public function approvalModuleKey(): string
    {
        return Str::snake(class_basename($this));
    }
}
