<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * v1.11.0 (SaaS Finalization Pass). One row per billing document, raised
 * against a Tenant (never a Company -- billing is a platform/tenant
 * concern, the same boundary `Subscription` already uses). Optionally
 * linked to the Subscription it bills for; nullable because an onboarding
 * invoice is raised before the subscription exists.
 *
 * v2.70.0 -- THE INVOICE IS THE CONTRACT A PAYMENT SETTLES.
 *
 * A verified payment arrives knowing only an order id. Everything else --
 * whether it provisions a tenant, buys another period, or moves the
 * customer to a different plan -- has to be readable from the invoice
 * itself. `purpose` states it outright, and `target_package_id` /
 * `target_billing_cycle` carry what a plan change is for, so the webhook
 * never has to infer intent from which foreign keys happen to be null.
 *
 * `status` becomes 'paid' in exactly two places: `markPaid()` called by
 * PaymentWebhookController after a signature-verified settlement, and a
 * Platform Admin recording a payment made outside the gateway (bank
 * transfer). It is never inferred from a browser redirect.
 */
class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_VOID];

    /*
     |-------------------------------------------------------------------
     | PURPOSE -- what paying this invoice actually buys (v2.70.0)
     |-------------------------------------------------------------------
     */

    /** Buys the tenant itself. Paying it provisions. */
    public const PURPOSE_ONBOARDING = 'onboarding';

    /** Buys the next period of an existing subscription. */
    public const PURPOSE_RENEWAL = 'renewal';

    /** Buys an upgrade, prorated for the rest of the current period. */
    public const PURPOSE_PLAN_CHANGE = 'plan_change';

    public const PURPOSES = [self::PURPOSE_ONBOARDING, self::PURPOSE_RENEWAL, self::PURPOSE_PLAN_CHANGE];

    protected $fillable = [
        'invoice_number', 'tenant_id', 'registration_id', 'subscription_id', 'period_start', 'period_end',
        'purpose', 'target_package_id', 'target_billing_cycle',
        'amount', 'currency', 'status', 'due_date', 'payment_date', 'payment_reference',
        'payment_method', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'payment_date' => 'date',
            'amount' => 'decimal:2',
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

    /** The plan this invoice moves the subscription to once it is paid. */
    public function targetPackage()
    {
        return $this->belongsTo(Package::class, 'target_package_id');
    }

    /** Still owed: issued or overdue, and never voided. */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_OVERDUE], true);
    }

    /**
     * Presentation only. Nothing in IOMS voids or escalates an invoice
     * because a date passed -- an invoice working its way through a
     * customer's own finance process is not a mistake to clean up.
     */
    public function isOverdue(): bool
    {
        return $this->isPayable() && $this->due_date !== null && $this->due_date->isPast();
    }

    /**
     * v2.51.0. An invoice raised during self-service onboarding belongs to
     * a registration, not yet to a tenant -- the tenant does not exist
     * until this invoice is paid. Provisioning back-fills `tenant_id`.
     */
    public function registration()
    {
        return $this->belongsTo(TenantRegistration::class, 'registration_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Deliberately NOT routed through NumberGeneratorService::generate() --
     * that service resolves the sequence scope via CurrentTenant (the
     * tenant of the currently logged-in, request-bound user), which is
     * wrong here: a Platform Admin (no tenant of their own) issues an
     * invoice FOR an explicitly chosen tenant, so the sequence must be
     * scoped to that explicit $tenantId, not ambient request state. Same
     * atomic lockForUpdate() concurrency-safety pattern
     * NumberGeneratorService::nextSequence() itself uses, applied
     * directly here against this table instead.
     */
    public static function generateNumber(?int $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $year = now()->format('Y');

            // v2.51.0: an onboarding invoice has no tenant yet (see
            // registration()). Those are counted in their own series so
            // the per-tenant sequence stays contiguous once the tenant
            // exists -- a customer's first invoice is INV-YYYY-<id>-00001
            // either way, and pre-tenant invoices never consume a number
            // from a tenant that has not been created.
            $query = static::whereYear('created_at', $year);
            $query = $tenantId === null
                ? $query->whereNull('tenant_id')
                : $query->where('tenant_id', $tenantId);

            $count = $query->lockForUpdate()->count();

            return $tenantId === null
                ? sprintf('INV-%s-NEW-%05d', $year, $count + 1)
                : sprintf('INV-%s-%d-%05d', $year, $tenantId, $count + 1);
        });
    }

    public function markPaid(string $paymentReference = null, string $paymentMethod = null, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'payment_date' => now()->toDateString(),
            'payment_reference' => $paymentReference ?? $this->payment_reference,
            'payment_method' => $paymentMethod ?? $this->payment_method,
            'notes' => $notes ?? $this->notes,
        ]);
    }
}
