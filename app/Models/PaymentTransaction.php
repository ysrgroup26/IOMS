<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.6 -- one row per gateway checkout/payment attempt. See the owning migration's own doc comment. */
class PaymentTransaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'invoice_id', 'gateway', 'gateway_reference', 'status', 'amount', 'currency', 'redirect_url',
        // v2.55.0: the provider's checkout token, so IOMS can open the
        // payment interface over its OWN order summary page. Carries no
        // authority -- see the owning migration.
        'checkout_token',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * v2.80.0 -- the other direction. A payment is traceable to the
     * subscription it settled and the tenant that owns it THROUGH the
     * invoice, which is the contract a payment settles (ADR 033 section 5).
     * There is deliberately no tenant_id column here: a second copy of the
     * owner could disagree with the invoice about who paid.
     */
    public function subscription()
    {
        return $this->hasOneThrough(
            Subscription::class,
            Invoice::class,
            'id',              // invoices.id
            'id',              // subscriptions.id
            'invoice_id',      // payment_transactions.invoice_id
            'subscription_id', // invoices.subscription_id
        );
    }
}
