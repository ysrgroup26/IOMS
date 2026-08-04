<?php

namespace App\Concerns;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Workflow Engine (v1.6.9.1) -- complements `HasApprovals` rather than
 * duplicating it. `HasApprovals` is specifically about the
 * submit/approve/reject decision itself (a single `Approval` record);
 * this trait is the more general state-machine guard around a model's
 * *own* `status` column, valid for every transition in its lifecycle
 * (draft -> submitted -> approved -> processing -> completed, plus the
 * cancelled/rejected branches), not just the approval decision point.
 *
 * A consuming model defines its own transition map as a protected static
 * property:
 *
 *   protected static array $transitions = [
 *       'draft' => ['submitted', 'cancelled'],
 *       'submitted' => ['approved', 'rejected', 'cancelled'],
 *       'approved' => ['processing', 'cancelled'],
 *       'processing' => ['completed', 'cancelled'],
 *       'rejected' => ['draft'], // override only, see actual controller check
 *       'completed' => [],
 *       'cancelled' => [],
 *   ];
 *
 * Any future module with its own multi-step lifecycle (Permit To Work,
 * Purchase Request, Asset Request, Inspection) defines the same shape of
 * map and gets the identical guard + automatic ActivityLog entry, rather
 * than each module hand-rolling its own `if ($old === 'x' && $new ===
 * 'y')` checks that drift apart over time.
 */
trait HasWorkflow
{
    /**
     * Throws a validation exception naming the actual problem (current
     * status, attempted status, and what's actually allowed) rather than
     * a generic 403 -- this is a business-rule violation the user should
     * understand, not a permission failure.
     */
    public function transitionTo(string $newStatus, User $user, ?string $description = null, array $meta = []): void
    {
        $current = $this->status;
        $allowed = static::$transitions[$current] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Cannot move from \"{$current}\" to \"{$newStatus}\". Allowed from \"{$current}\": ".
                    (empty($allowed) ? 'none (this is a final state).' : implode(', ', $allowed).'.'),
            ]);
        }

        $this->update(['status' => $newStatus]);

        ActivityLog::record(
            $newStatus,
            $description ?? (class_basename($this).' moved from '.ucfirst($current).' to '.ucfirst($newStatus).'.'),
            $this,
            $meta
        );
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, static::$transitions[$this->status] ?? [], true);
    }
}
