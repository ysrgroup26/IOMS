<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * v1.11.2 (Final Completion Pass, Part 9). A reusable, configurable seed
 * for `HseInspection.checklist_items` -- see the owning migration's own
 * doc comment for why this is not a second inspection engine.
 */
class HseChecklistTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'category', 'equipment_type_id', 'name', 'items', 'is_active', 'sort_order'];

    /** v2.135.0 -- the category an equipment-type template is filed under (not an area-inspection type). */
    public const EQUIPMENT_CATEGORY = 'equipment';

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function equipmentType()
    {
        return $this->belongsTo(HseEquipmentType::class, 'equipment_type_id');
    }

    /** v2.135.0 -- the active template for one equipment type, preferring the unit's own operating unit. */
    public static function forEquipmentType(?int $typeId, int $companyId): ?self
    {
        if (! $typeId) {
            return null;
        }

        return self::query()->active()
            ->where('equipment_type_id', $typeId)
            ->orderByRaw('company_id = ? desc', [$companyId])
            ->orderBy('sort_order')->orderBy('id')
            ->first();
    }
}
