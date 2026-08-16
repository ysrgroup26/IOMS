<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.4 (HSE Waste Management, Part 12). Mirrors HazardCategory/HseEquipmentType's own master-data shape. */
class WasteType extends Model
{
    public const CATEGORY_B3 = 'b3';

    public const CATEGORY_NON_B3 = 'non_b3';

    public const CATEGORIES = [self::CATEGORY_B3, self::CATEGORY_NON_B3];

    protected $fillable = [
        'company_id', 'name', 'code', 'category', 'waste_code', 'characteristics',
        'unit', 'storage_limit_days', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'storage_limit_days' => 'integer'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function wasteRecords()
    {
        return $this->hasMany(WasteRecord::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
