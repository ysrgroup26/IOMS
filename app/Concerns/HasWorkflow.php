<?php

namespace App\Concerns;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
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

        $this->notifyStatusChange($newStatus, $user, $meta);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, static::$transitions[$this->status] ?? [], true);
    }

    /**
     * Milestone 3 (Notification Center). Every workflow-driven model
     * automatically notifies its "owner" (see `notificationRecipient()`)
     * on every status change -- genuinely event-driven, not a dummy/
     * seeded notification. Silently does nothing if the model has no
     * resolvable recipient (e.g. Incident before a `reported_by` user
     * exists) or the recipient IS the person who made the change
     * (no need to tell yourself what you just did).
     */
    protected function notifyStatusChange(string $newStatus, User $actor, array $meta): void
    {
        $recipient = $this->notificationRecipient();

        if (! $recipient || $recipient->id === $actor->id) {
            return;
        }

        $category = in_array($newStatus, ['approved', 'completed'], true)
            ? Notification::CATEGORY_SUCCESS
            : (in_array($newStatus, ['rejected', 'cancelled'], true)
                ? Notification::CATEGORY_WARNING
                : Notification::CATEGORY_INFORMATION);

        app(NotificationService::class)->notify(
            $recipient,
            $category,
            class_basename($this).' '.$this->displayNumber().' is now '.ucfirst($newStatus),
            $meta['comments'] ?? null,
            null,
            $this
        );
    }

    /**
     * Who should be notified when this record's status changes. Checks,
     * in order: a `requester()`/`reporter()`/`creator()` relation (if
     * defined and loaded/loadable), then the raw `requested_by`/
     * `reported_by`/`created_by` column. Override this method on a
     * consuming model for anything that doesn't fit this convention.
     */
    protected function notificationRecipient(): ?User
    {
        foreach (['requester', 'reporter', 'creator'] as $relation) {
            if (method_exists($this, $relation)) {
                $user = $this->{$relation};
                if ($user instanceof User) {
                    return $user;
                }
            }
        }

        foreach (['requested_by', 'reported_by', 'created_by'] as $column) {
            if (! empty($this->{$column})) {
                return User::find($this->{$column});
            }
        }

        return null;
    }

    /**
     * First column ending in `_number` (e.g. `request_number`,
     * `incident_number`), falling back to `#{id}` -- used only for a
     * readable notification title, never persisted.
     */
    protected function displayNumber(): string
    {
        foreach ($this->getAttributes() as $key => $value) {
            if (str_ends_with($key, '_number') && $value) {
                return $value;
            }
        }

        return '#'.$this->getKey();
    }
}
