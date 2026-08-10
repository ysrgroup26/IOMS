<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 4, Workstream B0. Tenant-scoped Hazard Category master -- see
 * the owning migration's own doc comment
 * (2026_08_19_100061_create_hazard_categories_table).
 */
class HazardCategory extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function safetyObservations()
    {
        return $this->hasMany(SafetyObservation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
