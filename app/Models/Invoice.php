<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * v1.11.0 (SaaS Finalization Pass). One row per billing document, raised
 * against a Tenant (never a Company -- billing is a platform/tenant
 * concern, the same boundary `Subscription` already uses). Optionally
 * linked to the Subscription it bills for; nullable because an invoice
 * can legitimately outlive the Subscription row it was raised against
 * (a plan change creates a NEW Subscription row per that table's own
 * "history table" convention).
 *
 * No payment gateway integration exists in this codebase.
 * `payment_reference`/`payment_method` are free-text fields a Platform
 * Admin fills in manually after confirming a payment happened outside
 * this system -- `markPaid()` is deliberately the only way `status`
 * becomes 'paid', never inferred or auto-confirmed.
 */
class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_VOID = 'void';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ISSUED, self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_VOID];

    protected $fillable = [
        'invoice_number', 'tenant_id', 'subscription_id', 'period_start', 'period_end',
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
    public static function generateNumber(int $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $year = now()->format('Y');
            $count = static::where('tenant_id', $tenantId)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();

            return sprintf('INV-%s-%d-%05d', $year, $tenantId, $count + 1);
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
