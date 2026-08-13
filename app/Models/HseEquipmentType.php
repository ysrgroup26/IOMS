<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.1. Configurable HSE operational equipment category master -- mirrors HazardCategory exactly. See the owning migration's own doc comment. */
class HseEquipmentType extends Model
{
    protected $fillable = ['company_id', 'name', 'code', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
