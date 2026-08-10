<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream C3 (Vendor Quotation). See the owning migration's own doc comment. */
class VendorQuotation extends Model
{
    public const STATUSES = ['submitted', 'withdrawn'];

    protected $fillable = [
        'rfq_id', 'vendor_id', 'company_id', 'vendor_reference_number', 'quotation_date',
        'valid_until', 'currency', 'items', 'subtotal', 'discount_amount', 'tax_amount',
        'shipping_cost', 'other_charges', 'total_amount', 'lead_time_days', 'payment_terms',
        'delivery_terms', 'attachment_path', 'notes', 'status',
    ];

    protected $appends = ['attachment_url'];

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'valid_until' => 'date',
            'items' => 'array',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path ? asset('storage/'.$this->attachment_path) : null;
    }
}
