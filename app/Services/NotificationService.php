<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

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
        $stored = json_decode(CompanySetting::where('key', 'notification_preferences')->value('value') ?? '', true);

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

        return Notification::create([
            'user_id' => $user->id,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'notifiable_type' => $subject?->getMorphClass(),
            'notifiable_id' => $subject?->getKey(),
            'meta' => $meta,
        ]);
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
