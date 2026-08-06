<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Package + Subscription). One row per Tenant subscription
 * period -- see the migration's own doc comment for why this is a history
 * table rather than a single mutable row per Tenant.
 */
class Subscription extends Model
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const CYCLE_MONTHLY = 'monthly';
    public const CYCLE_YEARLY = 'yearly';

    protected $fillable = [
        'tenant_id',
        'package_id',
        'status',
        'billing_cycle',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIAL], true);
    }
}
