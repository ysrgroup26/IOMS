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
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
