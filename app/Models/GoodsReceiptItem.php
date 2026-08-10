<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'item_id',
        'description',
        'quantity_received',
        'unit',
        'sort_order',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** Milestone 4, Workstream C5 -- the actual reconciliation link PurchaseOrderItem::getDeliveredQuantityAttribute() sums against. */
    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /** Milestone 4, Acceleration Part 1B -- when set, this receipt line posts a real StockMovement (see GoodsReceiptController::store()'s own comment). */
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
