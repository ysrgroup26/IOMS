<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'code', 'sort_order', 'is_active'];

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

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function positions()
    {
        return $this->hasMany(Position::class);
    }

    public function kpiRecords()
    {
        return $this->hasMany(KpiRecord::class);
    }

    public function scopeInCompany($query, ?int $companyId)
    {
        return $companyId ? $query->where('company_id', $companyId) : $query;
    }

    /**
     * Configurable display order (Super Admin/HSE-editable via Settings),
     * with name as a stable tiebreaker for departments that share the
     * default sort_order of 0.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
