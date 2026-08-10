<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B11. See the owning migration's own doc comment. */
class HseMaterial extends Model
{
    public const CATEGORIES = ['consumable', 'reusable_material', 'chemical', 'other'];

    protected $fillable = ['company_id', 'name', 'category', 'unit', 'current_stock', 'reorder_level', 'notes', 'is_active'];

    protected $appends = ['is_low_stock'];

    protected function casts(): array
    {
        return [
            'current_stock' => 'integer',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->current_stock <= $this->reorder_level;
    }
}
