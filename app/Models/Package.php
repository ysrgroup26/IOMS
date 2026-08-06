<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Package + Subscription). A pricing/feature tier a Tenant
 * can subscribe to. Deliberately NOT tenant-scoped -- packages are the
 * platform operator's own catalog, visible/manageable only from the
 * future Platform Super Admin surface (Task #44), not per-tenant data.
 */
class Package extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'max_users',
        'max_companies',
        'features',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->features ?? [], true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
