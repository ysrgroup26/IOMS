<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2.86.0 -- what an invoice's total is made of.
 *
 * `invoices.amount` remains the authoritative total and is what the payment
 * path charges; these describe it. The approved direction is explicit that
 * itemisation must be real accounting rather than a presentational trick, so
 * the items are written when the invoice is raised, by the same code that
 * computes the total, and they sum to it.
 */
class InvoiceItem extends Model
{
    /** A subscription period. */
    public const KIND_SUBSCRIPTION = 'subscription';

    /** Additional Full Users, per user per month. */
    public const KIND_ADDITIONAL_USERS = 'additional_users';

    /** Additional My Work capacity, per pack of ten per month. */
    public const KIND_MY_WORK_PACKS = 'my_work_packs';

    /** A one-off PTW document top-up. */
    public const KIND_PTW_TOPUP = 'ptw_topup';

    protected $fillable = [
        'invoice_id', 'kind', 'description', 'quantity', 'unit_amount', 'amount', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
