<?php

namespace App\Services\Notifications;

use App\Models\Notification;

/**
 * v2.141.0 -- WHAT IOMS TELLS PEOPLE ABOUT, IN ONE PLACE.
 *
 * Before this, every module called `notify()` with whatever category it
 * chose, and most of what people expected to hear about produced nothing
 * at all: nobody was told a task or a corrective action had been handed to
 * them, or that one was due. Each event here names:
 *
 *   category   the group a person can switch on or off (UserNotificationPreference)
 *   priority   low / normal / high / critical -- how loudly it is shown
 *   mandatory  in-app delivery cannot be switched off by the person, because
 *              it asks them to ACT (an assignment, an approval waiting on them).
 *              Push can still be switched off; the in-app record cannot.
 *
 * Events not in this list still work through the original `notify()` path
 * (category given by the caller, priority `normal`), so nothing that already
 * notifies changes behaviour by this release.
 */
class NotificationCatalogue
{
    public const EVENTS = [
        'task.assigned' => ['category' => Notification::CATEGORY_ASSIGNMENT, 'priority' => 'normal', 'mandatory' => true],
        'task.due_soon' => ['category' => Notification::CATEGORY_REMINDER, 'priority' => 'normal', 'mandatory' => false],
        'task.overdue' => ['category' => Notification::CATEGORY_WARNING, 'priority' => 'high', 'mandatory' => false],
        'capa.assigned' => ['category' => Notification::CATEGORY_ASSIGNMENT, 'priority' => 'high', 'mandatory' => true],
        'capa.due_soon' => ['category' => Notification::CATEGORY_REMINDER, 'priority' => 'normal', 'mandatory' => false],
        'capa.overdue' => ['category' => Notification::CATEGORY_WARNING, 'priority' => 'high', 'mandatory' => false],
    ];

    /**
     * Categories whose IN-APP record a person cannot switch off: they ask the
     * person to act. Push for them still follows the person's choice.
     */
    public const IN_APP_LOCKED = [Notification::CATEGORY_ASSIGNMENT, Notification::CATEGORY_APPROVAL];

    /** The categories a person sees in their preferences, in display order. */
    public const CATEGORIES = [
        Notification::CATEGORY_ASSIGNMENT => ['label' => 'Assignments', 'description' => 'Tugas dan tindakan perbaikan yang diserahkan kepada Anda.'],
        Notification::CATEGORY_APPROVAL => ['label' => 'Approvals', 'description' => 'Permintaan persetujuan dan keputusan atas pengajuan Anda.'],
        Notification::CATEGORY_REMINDER => ['label' => 'Reminders', 'description' => 'Pengingat tenggat yang mendekat.'],
        Notification::CATEGORY_WARNING => ['label' => 'Warnings', 'description' => 'Hal yang terlambat, kedaluwarsa, atau perlu perhatian segera.'],
        Notification::CATEGORY_SUCCESS => ['label' => 'Completions', 'description' => 'Pekerjaan atau pengajuan yang selesai.'],
        Notification::CATEGORY_INFORMATION => ['label' => 'Information', 'description' => 'Pemberitahuan umum.'],
    ];

    /** @return array{category: string, priority: string, mandatory: bool} */
    public static function event(string $key): array
    {
        if (! isset(self::EVENTS[$key])) {
            throw new \InvalidArgumentException("Unknown notification event [{$key}].");
        }

        return self::EVENTS[$key];
    }
}
