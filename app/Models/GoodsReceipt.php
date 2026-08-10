<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Logistics' first real module beyond Material Requests (v1.10.0). */
class GoodsReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'received_date',
        'material_request_id',
        'purchase_order_id',
        'project_id',
        'received_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
        ];
    }

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    /** Milestone 4, Workstream C5 -- Procurement's PO->GRN integration, additive to the existing Material Request flow (see this model's own migration doc comment). */
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('sort_order');
    }

    /** GR-{YEAR}-{00001}, same per-year sequential convention as Material Request/Leave/Incident. */
    /**
     * Milestone 3: delegates to the centralized, lock-safe Numbering
     * Engine -- see MaterialRequest::generateRequestNumber()'s doc
     * comment for why. Same GR-{YEAR}-{00001} shape as before by default.
     */
    public static function generateReceiptNumber(?int $companyId = null): string
    {
        return app(\App\Services\NumberGeneratorService::class)->generate('goods_receipt', $companyId);
    }
}
