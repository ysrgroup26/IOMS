<?php

namespace App\Services;

use App\Jobs\DeliverWebPush;
use App\Models\CompanySetting;
use App\Models\Notification;
use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\NotificationCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Milestone 3 (Notification Center). The only class that should create
 * `Notification` rows -- always fired FROM a real workflow event
 * (`App\Concerns\HasWorkflow::transitionTo()`, `App\Services\ApprovalEngine`),
 * never dummy/seeded data. See docs/ADR/011-notification-center.md.
 *
 * Milestone 3 (Company Settings completion, Task #62): respects a
 * `notification_preferences` CompanySetting (JSON, one bool per
 * category) -- Settings > Notification lets an admin turn a whole
 * category off platform-wide. Genuinely functional: a disabled category
 * is never written to the table at all, not just hidden in the UI.
 * Stored via the same (currently install-wide, not per-tenant)
 * CompanySetting mechanism as branding/enabled_modules -- a known,
 * pre-existing limitation (see docs/ADR/008), not a new one introduced
 * here.
 */
class NotificationService
{
    private const DEFAULT_PREFERENCES = [
        Notification::CATEGORY_APPROVAL => true,
        Notification::CATEGORY_REMINDER => true,
        Notification::CATEGORY_WARNING => true,
        Notification::CATEGORY_SUCCESS => true,
        Notification::CATEGORY_INFORMATION => true,
    ];

    public static function preferences(): array
    {
        $stored = json_decode(CompanySetting::getUncached('notification_preferences') ?? '', true);

        // v2.141.0 (N1, owner-approved): assignments and approvals ask a
        // person to ACT, so a company switch can never turn them off -- an
        // approver for a PTW stage must always be told.
        $merged = array_merge(self::DEFAULT_PREFERENCES, is_array($stored) ? $stored : []);
        foreach (NotificationCatalogue::IN_APP_LOCKED as $category) {
            $merged[$category] = true;
        }

        return $merged;
    }

    public function notify(
        User $user,
        string $category,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?Model $subject = null,
        array $meta = []
    ): ?Notification {
        if (! (self::preferences()[$category] ?? true)) {
            return null;
        }

        // v2.141.0: a person may switch a category off for themselves.
        if (! $this->personWants($user, $category, 'in_app')) {
            return null;
        }

        $notification = Notification::create([
            'user_id' => $user->id,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'notifiable_type' => $subject?->getMorphClass(),
            'notifiable_id' => $subject?->getKey(),
            'meta' => $meta,
        ]);

        $this->deliver($notification, $user);

        return $notification;
    }

    /**
     * v2.141.0 -- send a CATALOGUED event (NotificationCatalogue).
     *
     * The event decides the category and priority. A `dedupeKey` makes the
     * call idempotent per person: if they already hold a notification with
     * that key, nothing new is created -- so an hourly reminder job can ask
     * every hour without telling anyone twice. A mandatory event (work handed
     * to the person, a decision waiting on them) is delivered in the app even
     * if they switched its category off; push still follows their choice.
     */
    public function send(
        string $event,
        User $user,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?Model $subject = null,
        array $meta = [],
        ?string $dedupeKey = null,
        ?string $groupKey = null,
    ): ?Notification {
        $definition = NotificationCatalogue::event($event);
        $category = $definition['category'];

        if (! $definition['mandatory'] && (! (self::preferences()[$category] ?? true) || ! $this->personWants($user, $category, 'in_app'))) {
            return null;
        }

        if ($dedupeKey !== null && Notification::where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $notification = Notification::create([
            'user_id' => $user->id,
            'category' => $category,
            'event_key' => $event,
            'priority' => $definition['priority'],
            'group_key' => $groupKey,
            'dedupe_key' => $dedupeKey,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'notifiable_type' => $subject?->getMorphClass(),
            'notifiable_id' => $subject?->getKey(),
            'meta' => $meta,
        ]);

        $this->deliver($notification, $user);

        return $notification;
    }

    /** A person's own switch for one category and channel; no row means on. */
    public function personWants(User $user, string $category, string $channel): bool
    {
        if ($channel === 'in_app' && in_array($category, NotificationCatalogue::IN_APP_LOCKED, true)) {
            return true;
        }

        $preference = UserNotificationPreference::where('user_id', $user->id)->where('category', $category)->first();

        return $preference ? (bool) $preference->{$channel} : true;
    }

    /**
     * v2.141.0 -- push is a queued job with retries and a delivery record
     * (App\Jobs\DeliverWebPush), dispatched after the surrounding
     * transaction commits so a rolled-back decision is never announced
     * (ADR 067). It honours the person's push switch for the category.
     */
    private function deliver(Notification $notification, User $user): void
    {
        if (! $this->personWants($user, $notification->category, 'push')) {
            return;
        }

        DB::afterCommit(fn () => DeliverWebPush::dispatch($notification->id));
    }

    public function notifyRole(
        string $role,
        string $category,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?Model $subject = null,
        array $meta = []
    ): int {
        $count = 0;

        User::where('role', $role)->where('is_active', true)->each(function (User $user) use (&$count, $category, $title, $body, $url, $subject, $meta) {
            $this->notify($user, $category, $title, $body, $url, $subject, $meta);
            $count++;
        });

        return $count;
    }

    /**
     * v2.79.0 -- NOTIFY THE PLATFORM OPERATORS.
     *
     * notifyRole() cannot reach them: `User` carries a tenant global scope,
     * so inside a tenant request that query returns nobody with
     * `tenant_id = null`, and the notification is silently dropped. The
     * scope is lifted here deliberately and ONLY to find platform
     * administrators by role.
     *
     * These are PLATFORM events -- a subscription began, a payment failed,
     * a tenant went read-only. They are business notifications about the
     * operator's own product, never a tenant's operational data, and they
     * are never delivered to a tenant user.
     */
    public function notifyPlatformAdmins(
        string $category,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?Model $subject = null,
        array $meta = []
    ): int {
        $count = 0;

        User::withoutGlobalScope(UserTenantScope::class)
            ->whereNull('tenant_id')
            ->where('role', User::ROLE_PLATFORM_ADMIN)
            ->where('is_active', true)
            ->each(function (User $user) use (&$count, $category, $title, $body, $url, $subject, $meta) {
                $this->notify($user, $category, $title, $body, $url, $subject, $meta);
                $count++;
            });

        return $count;
    }

    public function markRead(Notification $notification): void
    {
        if (! $notification->isRead()) {
            $notification->update(['read_at' => now()]);
        }
    }

    public function markAllRead(User $user): int
    {
        return Notification::where('user_id', $user->id)->unread()->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)->unread()->count();
    }
}
