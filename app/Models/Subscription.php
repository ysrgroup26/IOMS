<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Package + Subscription). One row per Tenant subscription
 * period -- see the migration's own doc comment for why this is a history
 * table rather than a single mutable row per Tenant.
 *
 * v1.11.0 (SaaS Finalization Pass): extended with `type` (trial/
 * subscription/lifetime), `seat_limit`, `license_key`, `billing_reference`,
 * `notes`, `created_by` -- see that migration's own doc comment for why
 * these were added to this EXISTING table instead of a new one. `type`
 * and `status` answer two different questions and must not be conflated:
 * `type` is the commercial arrangement (does this ever expire at all?),
 * `status` is whether it's usable RIGHT NOW.
 */
class Subscription extends Model
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_GRACE_PERIOD = 'grace_period';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_GRACE_PERIOD,
        self::STATUS_EXPIRED, self::STATUS_SUSPENDED, self::STATUS_CANCELLED,
    ];

    public const CYCLE_MONTHLY = 'monthly';
    public const CYCLE_YEARLY = 'yearly';

    public const TYPE_TRIAL = 'trial';
    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_LIFETIME = 'lifetime';

    /**
     * A generic license type, not tied to any particular customer --
     * `lifetime` means perpetual usage rights to the purchased edition
     * with no recurring SOFTWARE charge; it says nothing about hosting/
     * infrastructure/domain/support, which stay governed by whatever
     * separate arrangement exists (never encoded here). No tenant is
     * ever hardcoded as lifetime in application code -- it is set the
     * same way any other type is, per-record, by a Platform Admin.
     */
    public const TYPES = [self::TYPE_TRIAL, self::TYPE_SUBSCRIPTION, self::TYPE_LIFETIME];

    protected $fillable = [
        'tenant_id',
        'package_id',
        'type',
        'status',
        'billing_cycle',
        'seat_limit',
        'license_key',
        'billing_reference',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'cancelled_at',
        'notes',
        'created_by',
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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function isLifetime(): bool
    {
        return $this->type === self::TYPE_LIFETIME;
    }

    /** A lifetime record has no expiry to check at all -- only cancellation/suspension can ever make it unusable. */
    public function isExpired(): bool
    {
        if ($this->isLifetime()) {
            return false;
        }

        $deadline = $this->status === self::STATUS_TRIAL ? $this->trial_ends_at : $this->ends_at;

        return $deadline !== null && $deadline->isPast();
    }

    /**
     * v1.11.1 (Final Production Readiness Pass, Part 15). Redefined into
     * two separate questions, on purpose, after auditing what actually
     * happens for each status/type combination -- the previous single
     * isUsable() conflated "explicitly suspended by an admin" (should
     * genuinely block access) with "expired by a date that might just be
     * stale/never-renewed seed data" (should NOT silently lock out a
     * paying tenant on a data assumption nobody has verified). This is
     * what made it unsafe to enable by default before -- now it's safe:
     *
     * - isBlocked(): TRUE only for `suspended`/`cancelled` -- always the
     *   result of an explicit Platform Admin action (PlatformController::
     *   updateSubscription()), never a side effect of an unattended date
     *   passing. This is the ONLY thing EnforceTenantEntitlement hard-
     *   blocks on.
     * - isDegraded(): TRUE when expired-by-date (trial or subscription)
     *   but NOT explicitly suspended/cancelled -- surfaced as a banner/
     *   warning (Settings > Subscription, and a Dashboard notice), never
     *   a 403. An admin who genuinely stopped paying should be caught by
     *   Platform Admin actually setting status=suspended, not by a cron
     *   job or stale seed date silently doing it.
     * - A record with no Subscription row at all (UNCONFIGURED) is
     *   treated as degraded, not blocked, for the exact same reason.
     */
    public function isBlocked(): bool
    {
        return in_array($this->status, [self::STATUS_SUSPENDED, self::STATUS_CANCELLED], true);
    }

    public function isDegraded(): bool
    {
        return ! $this->isBlocked() && $this->isExpired();
    }

    /** Back-compat alias -- "not hard-blocked". Used by EntitlementService::tenantIsUsable(). */
    public function isUsable(): bool
    {
        return ! $this->isBlocked();
    }

    public function seatLimit(): ?int
    {
        return $this->seat_limit ?? $this->package?->max_users;
    }
}
