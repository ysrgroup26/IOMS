<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2.141.0 -- one channel's attempt to deliver one notification: what was
 * tried, how many times, to how many devices, and why it failed. Owned by a
 * user through its notification; never reached outside that person.
 */
class NotificationDelivery extends Model
{
    public const CHANNEL_PUSH = 'push';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_NO_DEVICE = 'no_device';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['notification_id', 'user_id', 'channel', 'status', 'attempts', 'devices', 'last_error', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }
}
