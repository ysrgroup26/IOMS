<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Logistics' first real module beyond Material Requests (v1.10.0). */
class GoodsReceipt extends Model
{
    /**
     * v2.63.0 -- a goods receipt has no company_id and SEVERAL possible
     * parents, any of which may be null: it can arrive against a purchase
     * order, against a material request, or straight into a warehouse.
     * So it cannot use BelongsToCompanyThrough, which walks exactly one
     * relation.
     *
     * The scope is the same OR-shape GoodsReceiptController already wrote
     * by hand -- moved here so it applies to every query on this table
     * rather than to the one list endpoint that remembered it. Each
     * `whereHas` inherits that parent's own CompanyOwnedScope, so no
     * tenant rule is restated. A receipt with no resolvable parent at all
     * is unowned and therefore unreachable.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('companyThrough', function (Builder $builder) {
            // withTrashed on the parents for the same reason
            // BelongsToCompanyThrough does it: soft deletion is not an
            // ownership question, and a receipt must not disappear because
            // its purchase order was archived.
            $trashedToo = fn (Builder $parent) => $parent->withTrashed();

            $builder->where(function (Builder $query) use ($trashedToo) {
                $query->whereHas('purchaseOrder', $trashedToo)
                    ->orWhereHas('materialRequest', $trashedToo)
                    ->orWhereHas('warehouse');
            });
        });
    }

    use SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'received_date',
        'material_request_id',
        'purchase_order_id',
        'warehouse_id',
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

    /** Milestone 4, Acceleration Part 1B -- Warehouse integration, additive to the existing flow (see this model's own migration doc comment). */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
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
        return app(NumberGeneratorService::class)->generate('goods_receipt', $companyId);
    }
}
