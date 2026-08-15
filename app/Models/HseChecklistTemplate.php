<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v1.11.2 (Final Completion Pass, Part 9). A reusable, configurable seed
 * for `HseInspection.checklist_items` -- see the owning migration's own
 * doc comment for why this is not a second inspection engine.
 */
class HseChecklistTemplate extends Model
{
    protected $fillable = ['company_id', 'category', 'name', 'items', 'is_active', 'sort_order'];

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
}
