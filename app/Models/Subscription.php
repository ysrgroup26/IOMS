<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Package + Subscription). A tenant's commercial
 * arrangement with the platform.
 *
 * v1.11.0 (SaaS Finalization Pass): extended with `type` (trial/
 * subscription/lifetime), `seat_limit`, `license_key`, `billing_reference`,
 * `notes`, `created_by` -- see that migration's own doc comment for why
 * these were added to this EXISTING table instead of a new one. `type`
 * and `status` answer two different questions and must not be conflated:
 * `type` is the commercial arrangement (does this ever expire at all?),
 * `status` is what was DECIDED about it.
 *
 * v2.70.0 -- ONE LIVING ROW PER TENANT, NOT A HISTORY TABLE.
 *
 * The original migration described this as a period-per-row history
 * table. It never behaved that way: provisioning creates exactly one row,
 * every read (`Tenant::subscription()`, EntitlementService, the billing
 * page) assumes a single current subscription, and nothing has ever
 * created a second row for a tenant. Renewal now extends this row rather
 * than adding another, which is the behaviour the rest of the codebase
 * already depended on.
 *
 * PERIOD HISTORY LIVES ON `invoices`. Each invoice carries its own
 * `period_start`/`period_end` and what it bought, so "what did this
 * customer pay for, and when" is answerable in full -- from the billing
 * documents, which is where an auditor would look for it anyway.
 *
 * Nothing in this model's lifecycle ever deletes tenant data. A
 * subscription can lapse, be downgraded, be suspended and be revived;
 * the tenant, its users and every operational record are untouched
 * throughout. See docs/ADR/033-subscription-lifecycle.md.
 */
class Subscription extends Model
{
    /*
     |-------------------------------------------------------------------
     | STORED STATUS -- the deliberate axis only (v2.70.0)
     |-------------------------------------------------------------------
     | What a human or a verified payment DECIDED about this subscription.
     | Nothing here is ever written by the passage of time.
     |
     | `expired` and `grace_period` used to live here and were written by
     | nothing at all. Where a subscription sits in TIME is now derived on
     | every read -- see lifecycleState(). A stored time-state is correct
     | on the day a job writes it and wrong the moment the customer pays,
     | and it needs a scheduled job to stay true; a derived one cannot
     | drift and needs nothing running.
     */
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_CANCELLED,
    ];

    /*
     |-------------------------------------------------------------------
     | DERIVED LIFECYCLE STATE -- never stored (v2.70.0)
     |-------------------------------------------------------------------
     | The answer to "what may this customer do right now".
     */

    /** Inside the paid period. Full access. */
    public const LIFECYCLE_ACTIVE = 'active';

    /** Past the period end, inside the grace window. Full access, loudly warned. */
    public const LIFECYCLE_GRACE = 'grace';

    /** Past the grace window. READ-ONLY -- never a lockout. */
    public const LIFECYCLE_LAPSED = 'lapsed';

    /** An explicit operator action. Blocked, apart from the billing surface. */
    public const LIFECYCLE_SUSPENDED = 'suspended';

    public const LIFECYCLE_CANCELLED = 'cancelled';

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
        // v2.70.0 -- a change the customer asked for that takes effect at
        // the end of the period they already paid for. See
        // SubscriptionLifecycleService::requestPlanChange().
        'pending_package_id',
        'pending_billing_cycle',
        'pending_requested_at',
        'renewal_reminded_at',
        'type',
        'status',
        'billing_cycle',
        // v2.60.0 -- the price this customer actually agreed to. See
        // agreedAmountFor(); null means "follow the catalogue".
        'agreed_price_monthly',
        'agreed_price_yearly',
        'agreed_currency',
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
            'pending_requested_at' => 'datetime',
            'renewal_reminded_at' => 'datetime',
            // Nullable on purpose -- null means "follow the catalogue", so
            // `decimal:2` must not coerce an absent snapshot into 0.00.
            'agreed_price_monthly' => 'decimal:2',
            'agreed_price_yearly' => 'decimal:2',
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

    /** The plan this subscription switches to at the end of the current period. */
    public function pendingPackage()
    {
        return $this->belongsTo(Package::class, 'pending_package_id');
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

    /**
     * The end of the period this subscription has been paid for. A trial
     * is bounded by `trial_ends_at`; everything else by `ends_at`. Null
     * means "never ends" -- a lifetime licence, or a record whose dates
     * were never configured.
     */
    public function periodEndsAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->isLifetime()) {
            return null;
        }

        return $this->status === self::STATUS_TRIAL ? $this->trial_ends_at : $this->ends_at;
    }

    /** When read-only begins. Null when the subscription has no end at all. */
    public function graceEndsAt(): ?\Illuminate\Support\Carbon
    {
        $end = $this->periodEndsAt();

        return $end?->copy()->addDays(self::graceDays());
    }

    public static function graceDays(): int
    {
        return max(0, (int) config('saas.grace_days', 14));
    }

    /** Past the paid period. Says nothing on its own about access -- see lifecycleState(). */
    public function isExpired(): bool
    {
        $end = $this->periodEndsAt();

        return $end !== null && $end->isPast();
    }

    /**
     * v2.70.0 -- WHERE THIS SUBSCRIPTION ACTUALLY IS, derived every time.
     *
     * The deliberate axis wins: an operator who suspended or cancelled a
     * subscription has said something no date may override. Otherwise the
     * dates decide, and they decide correctly without anything running.
     */
    public function lifecycleState(): string
    {
        if ($this->status === self::STATUS_SUSPENDED) {
            return self::LIFECYCLE_SUSPENDED;
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return self::LIFECYCLE_CANCELLED;
        }

        if (! $this->isExpired()) {
            return self::LIFECYCLE_ACTIVE;
        }

        $graceEnd = $this->graceEndsAt();

        return $graceEnd !== null && $graceEnd->isFuture()
            ? self::LIFECYCLE_GRACE
            : self::LIFECYCLE_LAPSED;
    }

    /**
     * Whether this subscription may still CHANGE the customer's data.
     *
     * A lapsed customer keeps every byte and can read all of it; what they
     * lose is the ability to add more. That is the whole access model --
     * see docs/ADR/033-subscription-lifecycle.md for why a read-only lapse
     * beats a lockout in a system of record for safety compliance.
     */
    public function allowsWrites(): bool
    {
        return in_array($this->lifecycleState(), [self::LIFECYCLE_ACTIVE, self::LIFECYCLE_GRACE], true);
    }

    /** Suspension and cancellation are the only states that close the door. */
    public function allowsReads(): bool
    {
        return ! $this->isBlocked();
    }

    /** Whole days until the paid period ends. Negative once past it, null when it never ends. */
    public function daysUntilPeriodEnd(): ?int
    {
        $end = $this->periodEndsAt();

        if ($end === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false);
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

    /** Past the paid period but not blocked -- i.e. in grace or lapsed. */
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

    /**
     * v2.60.0 -- WHAT THIS CUSTOMER AGREED TO PAY, for the given cycle.
     *
     * Reads the snapshot taken when the subscription was created and falls
     * back to the current catalogue price when there is none. That
     * fallback is what makes the column safe to add: a subscription
     * without a snapshot behaves exactly as it did before this release.
     *
     * The point is the direction of authority. Before this, every amount
     * was recomputed from the package's CURRENT price, so editing a
     * catalogue row silently repriced live customers -- which this
     * release's own Enterprise increase would have done to every existing
     * Enterprise subscription at renewal. A price the customer agreed to
     * is now a fact recorded against their subscription, and changing it
     * is something the operator does deliberately (see repriceTo()), not
     * something that happens as a side effect of a marketing decision.
     */
    public function agreedAmountFor(?string $cycle = null): ?float
    {
        $cycle = $cycle ?? $this->billing_cycle;

        $agreed = $cycle === self::CYCLE_MONTHLY
            ? $this->agreed_price_monthly
            : $this->agreed_price_yearly;

        if ($agreed !== null) {
            return (float) $agreed;
        }

        $catalogue = $cycle === self::CYCLE_MONTHLY
            ? $this->package?->price_monthly
            : $this->package?->price_yearly;

        return $catalogue === null ? null : (float) $catalogue;
    }

    public function agreedCurrency(): string
    {
        return $this->agreed_currency ?? $this->package?->currency ?? config('payment.currency', 'IDR');
    }

    /** True when this customer is on a price that no longer matches the public catalogue. */
    public function isOnLegacyPricing(): bool
    {
        $package = $this->package;

        if (! $package || $this->agreed_price_monthly === null) {
            return false;
        }

        return (float) $this->agreed_price_monthly !== (float) $package->price_monthly
            || (float) $this->agreed_price_yearly !== (float) $package->price_yearly;
    }

    /**
     * Records a DELIBERATE price change on this subscription -- the only
     * supported way an agreed price moves. Nothing calls this
     * automatically; it exists so that when the operator does move a
     * customer onto new pricing, the change is written down rather than
     * inferred from whatever the catalogue happens to say that day.
     */
    public function repriceTo(float $monthly, float $yearly, ?string $currency = null): void
    {
        $this->update([
            'agreed_price_monthly' => $monthly,
            'agreed_price_yearly' => $yearly,
            'agreed_currency' => $currency ?? $this->agreedCurrency(),
        ]);
    }
}
