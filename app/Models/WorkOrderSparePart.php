<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyThrough;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 2. See the owning migration's own doc comment on the Warehouse integration. */
class WorkOrderSparePart extends Model
{
    use BelongsToCompanyThrough;

    /** Ownership resolves through this relation -- see BelongsToCompanyThrough. */
    protected string $companyOwnerRelation = 'workOrder';

    protected $fillable = ['work_order_id', 'item_id', 'warehouse_id', 'quantity_used', 'stock_movement_id'];

    protected function casts(): array
    {
        return ['quantity_used' => 'decimal:2'];
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }
}
