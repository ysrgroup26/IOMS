<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * v2.104.0 -- CAPACITY A CUSTOMER BOUGHT, WITH ITS OWN CLOCK.
 *
 * See the creating migration for why extra capacity stopped being a number
 * on the subscription. In short: an add-on is a purchase, nothing is
 * granted until a verified payment settles it, and it renews on its own
 * date rather than on the subscription's.
 *
 * THE ONE RULE EVERY READER DEPENDS ON: `isCounted()`. Capacity counts
 * while it is paid for, which is NOT the same as "not cancelled" -- a
 * cancelled add-on keeps its capacity until the period it already paid for
 * runs out. Anything that asks "how much capacity does this tenant have"
 * must go through `counted()` or `isCounted()` rather than testing the
 * status itself, or a cancellation will take capacity away a customer has
 * paid for.
 */
class SubscriptionAddOn extends Model
{
    /*
     * NO GLOBAL SCOPE, matching `Subscription` and `Invoice`, which this
     * row sits between. Capacity is bought by the organization, so there is
     * no operating unit to scope by, and the commercial tables are filtered
     * explicitly by `tenant_id` at every call site rather than through a
     * scope. Keeping the three consistent matters more here than adopting a
     * different convention for one of them.
     */
    public const TYPE_ADDITIONAL_USERS = 'additional_users';

    public const TYPE_MY_WORK_PACKS = 'my_work_packs';

    public const TYPES = [self::TYPE_ADDITIONAL_USERS, self::TYPE_MY_WORK_PACKS];

    /** Invoiced, not yet paid. Grants nothing. */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    /** Paid and running. Counts until `ends_at`. */
    public const STATUS_ACTIVE = 'active';

    /** Will not renew. STILL COUNTS until `ends_at`. */
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'type',
        'quantity',
        'unit_price',
        'currency',
        'billing_cycle',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'subscription_add_on_id');
    }

    /**
     * Does this add-on's capacity count right now?
     *
     * Paid for, and the period paid for has not run out. A CANCELLED
     * add-on still answers yes until `ends_at`, deliberately: the customer
     * bought that period whole and no refund is computed, so taking the
     * capacity away at the moment they cancel would charge them for
     * something and then withhold it.
     */
    public function isCounted(): bool
    {
        if ($this->status === self::STATUS_PENDING_PAYMENT) {
            return false;
        }

        return $this->ends_at !== null && $this->ends_at->isFuture();
    }

    /** The add-ons whose capacity counts, as a query. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_CANCELLED])
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now());
    }

    /** Still renewing: active, not cancelled, and not yet lapsed. */
    public function scopeRenewing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->whereNull('cancelled_at');
    }

    /**
     * What one period of this add-on costs, from the price agreed when it
     * was bought rather than today's catalogue.
     *
     * An annual add-on is billed for the months the customer PAYS for, the
     * same rule the plan follows, so a Business customer who receives
     * fourteen months of service is not charged fourteen months of add-on.
     */
    public function periodCharge(): float
    {
        $months = $this->billing_cycle === Subscription::CYCLE_MONTHLY
            ? 1.0
            : (float) ($this->subscription?->annualPaidMonths() ?? 12);

        return round($this->quantity * (float) $this->unit_price * $months, 2);
    }

    /**
     * The period this add-on runs for, counted from a given start.
     *
     * An annual add-on receives the SERVICE months the plan receives, so a
     * Business customer's add-on lapses alongside the plan it supports
     * rather than two months before it.
     */
    public function periodEndFrom(\DateTimeInterface $start): \Illuminate\Support\Carbon
    {
        $from = \Illuminate\Support\Carbon::instance(
            $start instanceof \DateTimeImmutable ? \DateTime::createFromImmutable($start) : $start
        );

        return $this->billing_cycle === Subscription::CYCLE_MONTHLY
            ? $from->copy()->addMonth()
            : $from->copy()->addMonths((int) ($this->subscription?->annualServiceMonths() ?? 12));
    }

    public function label(): string
    {
        return match ($this->type) {
            self::TYPE_ADDITIONAL_USERS => 'Additional Users',
            self::TYPE_MY_WORK_PACKS => 'My Work Packs',
            default => $this->type,
        };
    }
}
