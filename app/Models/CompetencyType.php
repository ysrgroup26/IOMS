<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 4, Workstream A2. The training/certification catalog -- see
 * the owning migration's own doc comment
 * (2026_08_09_104450_create_competency_types_table) for why Training and
 * Certification share one table instead of two near-duplicate ones, and
 * why `company_id` is required rather than nullable-means-global.
 */
class CompetencyType extends Model
{
    public const TYPE_TRAINING = 'training';

    public const TYPE_CERTIFICATION = 'certification';

    public const TYPES = [self::TYPE_TRAINING, self::TYPE_CERTIFICATION];

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'issuing_body',
        'validity_months',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'validity_months' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function employeeCompetencies()
    {
        return $this->hasMany(EmployeeCompetency::class);
    }

    /**
     * The other half of "what job can this person perform" -- see
     * 2026_08_09_104453_create_position_competency_requirements_table's
     * own doc comment.
     */
    public function requiredByPositions()
    {
        return $this->belongsToMany(Position::class, 'position_competency_requirements');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function scopeOfType($query, ?string $type)
    {
        return $type ? $query->where('type', $type) : $query;
    }

    public function isRequestBased(): bool
    {
        return is_null($this->validity_months);
    }
}
