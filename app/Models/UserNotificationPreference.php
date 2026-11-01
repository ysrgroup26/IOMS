<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2.141.0 -- one person's choice for one notification category: in the
 * app, as a push, or both. A missing row means the catalogue's default.
 * A mandatory event ignores `in_app = false` (see NotificationCatalogue).
 */
class UserNotificationPreference extends Model
{
    protected $fillable = ['user_id', 'category', 'in_app', 'push'];

    protected function casts(): array
    {
        return ['in_app' => 'boolean', 'push' => 'boolean'];
    }
}
