<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = ['name', 'description', 'company_id', 'department_id', 'sort_order', 'is_active'];

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

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Milestone 4, Workstream A2. The competencies (training/certification)
     * this position requires -- see 2026_08_09_104453's own doc comment.
     */
    public function requiredCompetencies()
    {
        return $this->belongsToMany(CompetencyType::class, 'position_competency_requirements');
    }

    /**
     * Company-Scoped Master Data (v1.6.10) -- backs the new Settings
     * filter and Employee Import's Smart Master Data Detection, both of
     * which need "positions belonging to this company" as a first-class
     * query, not a join through department_id.
     */
    public function scopeInCompany($query, ?int $companyId)
    {
        if ($companyId) {
            return $query->where('company_id', $companyId);
        }

        return $query;
    }

    /**
     * Configurable display order (Super Admin/HSE-editable via Settings),
     * with name as a stable tiebreaker for positions sharing sort_order 0.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
