<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use App\Services\Notifications\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * v2.141.0 -- A PUSH IS A JOB WITH A RECORD, NOT A HOPE.
 *
 * Push used to be sent from an `app()->terminating()` callback: one attempt,
 * a failure logged and forgotten, and nothing at all when the notification
 * was created somewhere that callback does not run (a long-lived worker).
 * Now each notification's push is a queued job that retries with backoff
 * and records every attempt in `notification_deliveries`: sent (to how many
 * devices), no device to send to, or failed with the reason.
 *
 * Dead endpoints (404/410) are still removed by WebPushService itself.
 */
class DeliverWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> seconds between attempts */
    public array $backoff = [30, 120];

    public function __construct(public int $notificationId) {}

    public function handle(WebPushService $push): void
    {
        $notification = Notification::with('user')->find($this->notificationId);

        if (! $notification || ! $notification->user || ! $push->isConfigured()) {
            return;
        }

        $delivery = NotificationDelivery::firstOrCreate(
            ['notification_id' => $notification->id, 'channel' => NotificationDelivery::CHANNEL_PUSH],
            ['user_id' => $notification->user_id, 'status' => NotificationDelivery::STATUS_PENDING]
        );

        if ($delivery->status === NotificationDelivery::STATUS_SENT) {
            return;   // a retry after success sends nothing twice
        }

        $devices = PushSubscription::where('user_id', $notification->user_id)->count();
        $delivery->increment('attempts');

        if ($devices === 0) {
            $delivery->update(['status' => NotificationDelivery::STATUS_NO_DEVICE]);

            return;
        }

        $sent = $push->pushToUser($notification->user, [
            'topic' => $notification->notifiable_type && $notification->notifiable_id
                ? substr(md5($notification->notifiable_type.':'.$notification->notifiable_id), 0, 24)
                : null,
        ]);

        if ($sent > 0) {
            $delivery->update(['status' => NotificationDelivery::STATUS_SENT, 'devices' => $sent, 'delivered_at' => now(), 'last_error' => null]);

            return;
        }

        // Every device refused or was unreachable: let the queue retry.
        $delivery->update(['last_error' => 'No device accepted the push on attempt '.$delivery->attempts.'.']);

        throw new \RuntimeException('Web push was not accepted by any device.');
    }

    public function failed(\Throwable $e): void
    {
        NotificationDelivery::where('notification_id', $this->notificationId)
            ->where('channel', NotificationDelivery::CHANNEL_PUSH)
            ->update(['status' => NotificationDelivery::STATUS_FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 250)]);
    }
}
