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
        // v2.94.0 -- reconciliation fields. See the owning migration for
        // what each one answers. Every one is a RECORD of what the provider
        // said; none of them decides anything.
        'provider_reference', 'publisher_order_id', 'payment_method',
        'result_code', 'failure_reason',
        'paid_at', 'expired_at', 'callback_received_at', 'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
            'callback_received_at' => 'datetime',
            'provider_metadata' => 'array',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * v2.94.0 -- THE LIVE PAYMENT SESSION FOR AN INVOICE, IF THERE IS ONE.
     *
     * Both checkout paths asked this question and each answered it
     * differently. `SubscriptionController` reused a session only when it
     * carried a `checkout_token`, which is a Midtrans concept: Duitku is a
     * hosted redirect and returns no token, so the reuse branch could never
     * be taken and every page load opened a new inquiry at the provider.
     * The registration checkout did not ask at all and created a new
     * transaction every single time.
     *
     * The consequence was the same in both: one customer returning to
     * checkout three times left three "pending payments" against one
     * invoice, each a real inquiry, and an operator reconciling later could
     * not tell which one the customer actually used.
     *
     * A session counts as live when all three hold:
     *
     *   PENDING      a settled, failed or expired attempt is finished, and
     *                reusing one would point the customer at a dead page.
     *   USABLE       it has somewhere to send the customer -- a provider
     *                token or a redirect URL. A row with neither is the
     *                debris of a failed create, not a session.
     *   NOT STALE    inside `payment.checkout_expiry_hours`, which is the
     *                SAME window handed to the provider as the session's
     *                own expiry. Past it the provider has abandoned the
     *                session, so IOMS must not keep offering it.
     */
    public static function liveFor(Invoice $invoice): ?self
    {
        $hours = max(1, (int) config('payment.checkout_expiry_hours', 24));

        return static::where('invoice_id', $invoice->id)
            ->where('status', self::STATUS_PENDING)
            ->where('created_at', '>', now()->subHours($hours))
            ->where(fn ($q) => $q->whereNotNull('checkout_token')->orWhereNotNull('redirect_url'))
            ->latest('id')
            ->first();
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
