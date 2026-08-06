<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 2 (Tenancy Foundation). The real SaaS isolation boundary --
 * one Tenant is one paying customer organization. `Company` (GAJ,
 * Maintenance) keeps its pre-existing meaning unchanged: an internal
 * business unit WITHIN one Tenant, not a tenant itself. See
 * docs/ADR/008-tenancy-foundation.md for the full reasoning, in
 * particular why isolation is enforced via `companies.tenant_id` +
 * Company's own global scope rather than a parallel tenant_id on every
 * downstream table.
 */
class Tenant extends Model
{
    use SoftDeletes;

    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['name', 'slug', 'status', 'trial_ends_at'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
        ];
    }

    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE || $this->status === self::STATUS_TRIAL;
    }
}
