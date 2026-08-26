<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 6/7/8). Physical
 * waste container/equipment inventory (drums, IBC tanks, jumbo bags) --
 * a total/available/in_use/damaged COUNT of reusable equipment the
 * company owns, deliberately separate from `WasteRecord` (actual waste
 * material tracked by weight/volume) and `WasteStorageLocation` (a
 * place/TPS register). See the owning migration's own doc comment for
 * the full "why a new table" reasoning.
 */
class WasteContainerInventory extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNDER_MAINTENANCE = 'under_maintenance';

    public const STATUS_DISPOSED = 'disposed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_UNDER_MAINTENANCE, self::STATUS_DISPOSED];

    protected $fillable = [
        'company_id',
        'container_type',
        'code',
        'unit',
        'total_quantity',
        'in_use_quantity',
        'damaged_quantity',
        'capacity',
        'capacity_unit',
        'storage_location_id',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total_quantity' => 'integer',
            'in_use_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'capacity' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function storageLocation()
    {
        return $this->belongsTo(WasteStorageLocation::class, 'storage_location_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Computed, never stored -- `total - in_use - damaged`, same
     * "never store what can be derived" convention as
     * `Stock::getAvailableQuantityAttribute()` and `WasteRecord`'s own
     * `is_approaching_storage_limit`/`is_storage_overdue` accessors.
     * Floored at 0 so an inconsistent manual entry (in_use+damaged >
     * total) never surfaces a negative "available" count.
     */
    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->total_quantity - $this->in_use_quantity - $this->damaged_quantity);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
