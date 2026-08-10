<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream C4. See the owning migration's own doc comment on why this is a real child table. */
class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'description', 'specification', 'quantity', 'unit', 'unit_price', 'discount', 'tax', 'line_total', 'sort_order'];

    protected $appends = ['delivered_quantity', 'remaining_quantity', 'delivery_status'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceiptItems()
    {
        return $this->hasMany(GoodsReceiptItem::class, 'purchase_order_item_id');
    }

    /**
     * The real, always-correct "how much has actually arrived" number --
     * summed from goods_receipt_items on every access, never a stored
     * running total that could drift. See purchase_order_items'
     * migration doc comment for the full PO<->GRN reconciliation design.
     */
    public function getDeliveredQuantityAttribute(): float
    {
        return (float) $this->goodsReceiptItems()->sum('quantity_received');
    }

    public function getRemainingQuantityAttribute(): float
    {
        return max(0, (float) $this->quantity - $this->delivered_quantity);
    }

    public function getDeliveryStatusAttribute(): string
    {
        if ($this->delivered_quantity <= 0) {
            return 'pending';
        }

        return $this->remaining_quantity <= 0 ? 'fully_delivered' : 'partially_delivered';
    }
}
