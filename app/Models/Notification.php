<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Notification Center). See the creating migration's doc
 * comment for why this is a plain app table rather than Laravel's
 * built-in notification channel. Written to exclusively via
 * `App\Services\NotificationService` -- never create one directly, so
 * every notification has a consistent category/shape.
 */
class Notification extends Model
{
    public const CATEGORY_APPROVAL = 'approval';
    public const CATEGORY_REMINDER = 'reminder';
    public const CATEGORY_WARNING = 'warning';
    public const CATEGORY_SUCCESS = 'success';
    public const CATEGORY_INFORMATION = 'information';

    protected $fillable = [
        'user_id',
        'category',
        'title',
        'body',
        'url',
        'notifiable_type',
        'notifiable_id',
        'meta',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function notifiable()
    {
        return $this->morphTo();
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
