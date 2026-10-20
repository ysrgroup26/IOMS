<?php

namespace App\Concerns;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Str;
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

        // v2.73.0: `Str::headline()` rather than `ucfirst()`. Every status
        // in this product used to be a single word, so ucfirst was
        // indistinguishable from correct; the investigation workflow
        // introduced `in_progress` and `under_review`, and the audit trail
        // started reading "moved from In_progress to Under_review". The
        // audit trail is the record of record for every workflow here, so
        // it should be legible. Behaviour is otherwise identical --
        // single-word statuses render exactly as they did.
        /*
         * v2.125.0 -- and the AUDIT TRAIL is read by people too.
         *
         * `class_basename()` here produced "PermitToWork moved from Draft
         * to Submitted." on the permit's own activity timeline, found in
         * the browser while verifying this release. The notification
         * titles beside it were fixed from the same root cause; this line
         * was the third copy of it and the one most likely to be read,
         * because the timeline is what an auditor opens.
         *
         * `documentLabel()` is the single place that decides what a record
         * is called, so the timeline, the notification and the approval
         * request now cannot disagree. The statuses already went through
         * `Str::headline()` (v2.73.0) for exactly this reason.
         */
        ActivityLog::record(
            $newStatus,
            $description ?? ($this->documentLabel().' moved from '.Str::headline($current).' to '.Str::headline($newStatus).'.'),
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

        /*
         * v2.127.0 -- A NOTIFICATION MAY FAIL; A TRANSITION MAY NOT BE LOST.
         *
         * This call sits inside `transitionTo()`, which callers wrap in a
         * transaction when the state change has to be atomic with the
         * decision it records -- which PTW's two gates now do, because an
         * approval written without its actor, or an actor written without
         * its approval, is worse than either. An exception from here
         * therefore rolled the DECISION back: a mail driver timing out
         * un-approved a permit somebody had approved.
         *
         * Found by a test that replaced the notifier with one that throws,
         * written for the owner's requirement that "kegagalan pengiriman
         * notifikasi tidak membuat keputusan persetujuan hilang" -- and it
         * was already true of every other workflow model in the product,
         * silently, for as long as notifications have existed here.
         *
         * The state change is the record. Telling somebody about it is a
         * courtesy on top, and a courtesy is allowed to fail as long as it
         * fails loudly in the log and quietly everywhere else.
         */
        try {
            app(NotificationService::class)->notify(
            $recipient,
            $category,
            $this->documentLabel().' '.$this->displayNumber().' is now '.$this->statusLabel($newStatus),
            $meta['comments'] ?? null,
            $this->notificationUrl(),
            $this,
            // The route NAME travels with the row, so a surface rendering
            // this notification can ask the same allow-list question the
            // server would ask about its destination. See the shared
            // `notifications.items` prop in HandleInertiaRequests.
            array_filter(['route' => $this->notificationRouteName()])
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * v2.125.0 -- WHAT THE RECORD IS CALLED TO THE PERSON READING ABOUT IT.
     *
     * This was `class_basename($this)`, so the notification a field worker
     * received said **"PermitToWork PTW-2026-0001 is now Submitted"**. That
     * is a PHP class name, delivered to somebody standing on a deck with a
     * phone, and it is the exact defect `docs/CONVENTIONS.md` records for
     * Settings' module keys: a stored identifier escaping into customer
     * copy because nothing stood between the two.
     *
     * `Str::headline()` turns every existing consumer's class name into
     * words with no per-model work ("PermitToWork" -> "Permit To Work",
     * "MaterialRequest" -> "Material Request"), and a model whose product
     * name differs from its class name overrides this. Nothing invents a
     * name: the fallback is still derived from the class, just rendered as
     * language rather than code.
     */
    public function documentLabel(): string
    {
        return Str::headline(class_basename($this));
    }

    /**
     * The status as the product says it, not as the column stores it.
     *
     * `ucfirst()` was right while every status was one word and wrong the
     * moment one was not -- the same root cause already fixed for the
     * activity description above, which read "In_progress". A notification
     * is the one place a status reaches somebody who never opens the
     * record, so it is the worst place to show the enum.
     */
    public function statusLabel(?string $status = null): string
    {
        return Str::headline($status ?? (string) $this->status);
    }

    /**
     * WHERE THIS NOTIFICATION SHOULD TAKE THE READER, or null.
     *
     * v2.125.0 -- every workflow notification in this product was created
     * with `null` here, and the notification menu only navigates when a
     * url is set. So the whole chain existed and worked except for its
     * destination: a permit was returned for correction, the creator was
     * told, they tapped it, it was marked read, and nothing opened. The
     * reported symptom was "notifications only show text" -- which was
     * exactly true.
     *
     * NULL IS STILL THE DEFAULT, deliberately. A model opts in by naming
     * the route that shows ONE of its records, and only a route the
     * recipient is allowed to reach should be named: a notification that
     * opens a 403 is worse than one that opens nothing, because it reads
     * as the account being broken. The recipient of a status change is the
     * record's own requester (see notificationRecipient()), so a route
     * that shows the record to its requester is the safe answer; anything
     * narrower stays null.
     */
    public function notificationUrl(): ?string
    {
        $route = $this->notificationRouteName();

        if ($route === null || ! \Illuminate\Support\Facades\Route::has($route)) {
            return null;
        }

        return route($route, $this);
    }

    /**
     * The named route that shows one of these records. Null means "no
     * destination", which is a valid and safe answer.
     *
     * Public rather than protected because the notification row carries it
     * too (see notifyStatusChange()), and because ApprovalEngine asks the
     * same question about the same record for the approver's copy.
     */
    public function notificationRouteName(): ?string
    {
        return null;
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
