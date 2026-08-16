<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v1.11.4 (HSE Waste Management, Part 15). Waste Storage / TPS register --
 * deliberately a separate model/table from Warehouse's own StorageLocation
 * (general inventory bins/racks); see the owning migration's own doc
 * comment for why this is a genuine non-duplication, not an accidental
 * naming collision.
 */
class WasteStorageLocation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'company_id', 'name', 'code', 'location', 'container_type',
        'capacity', 'capacity_unit', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['capacity' => 'decimal:2'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function wasteRecords()
    {
        return $this->hasMany(WasteRecord::class, 'storage_location_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->orderBy('name');
    }
}
