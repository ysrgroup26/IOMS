<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\WebPushService;
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

        return array_merge(self::DEFAULT_PREFERENCES, is_array($stored) ? $stored : []);
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
     * v2.129.0 -- THE ROW IS THE RECORD; DELIVERY IS BEST EFFORT ON TOP.
     *
     * Every module that calls `notify()` gets real-time delivery from this
     * one place: the open tab learns through the live-sync endpoint, which
     * reads the same table, and a device that is not looking is woken by a
     * push. Nothing module-specific happens here and nothing needs to --
     * that is what makes this the shared foundation rather than a PTW
     * feature (ADR 069).
     *
     * TWO RULES, both of which this method exists to enforce:
     *
     *  1. NEVER BEFORE THE COMMIT. `notify()` is called from inside workflow
     *     transactions (PTW holds a row lock while it records a decision).
     *     A push sent from inside one would announce a decision that can
     *     still be rolled back, and the person would open a permit that
     *     never moved. `DB::afterCommit` defers to the real commit, and
     *     runs immediately when there is no transaction open.
     *  2. NEVER AT THE CALLER'S EXPENSE. The HTTP request to a push service
     *     happens AFTER the response has been sent (`dispatchAfterResponse`
     *     semantics via `app()->terminating`), so a slow or dead endpoint
     *     costs the person who pressed Approve nothing at all. And it is
     *     wrapped: v2.127.0 found `HasWorkflow` destroying an approval
     *     because its notifier threw inside the caller's transaction, and
     *     the lesson is not one that needs learning twice.
     *
     * NO QUEUE WORKER IS REQUIRED, which is a deployment fact rather than a
     * preference: IOMS runs on cPanel shared hosting with no long-running
     * process, so a queued job would sit in `jobs` until the next cron
     * minute at best. After-response delivery is immediate and needs
     * nothing installed. If a worker ever exists, this is the one method
     * that changes.
     */
    private function deliver(Notification $notification, User $user): void
    {
        DB::afterCommit(function () use ($notification, $user) {
            app()->terminating(function () use ($notification, $user) {
                try {
                    app(WebPushService::class)->pushToUser($user, [
                        // Lets a push service collapse an older undelivered
                        // tickle for the same record -- five moves on one
                        // permit while a phone was off is one thing to look
                        // at, not five. Carries no operational data: it is
                        // a type and an id.
                        'topic' => $notification->notifiable_type && $notification->notifiable_id
                            ? substr(md5($notification->notifiable_type.':'.$notification->notifiable_id), 0, 24)
                            : null,
                    ]);
                } catch (\Throwable $e) {
                    // The notification row exists and the Notification
                    // Center will show it. A failed push is a quieter
                    // product, never a lost record and never a failed
                    // business transaction.
                    report($e);
                }
            });
        });
    }

    /**
     * Notifies every active user with the given `role` column value.
     * Used for role-based approval-step assignment, where there's no
     * single specific user to notify. Deliberately does NOT also notify
     * Super Admin implicitly -- Work Center's own aggregate query already
     * surfaces everything to them; this is an additive convenience
     * layer, not the sole visibility mechanism.
     */
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

        User::withoutGlobalScope(\App\Models\Scopes\UserTenantScope::class)
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
