<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Incident Management (v1.10.0) -- HSE's first real module beyond PPE.
 * Workflow Engine only, no Approval Engine (see migration's own note).
 */
class Incident extends Model
{
    use HasWorkflow, SoftDeletes;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_CLOSED = 'closed';

    public const SEVERITIES = ['minor', 'moderate', 'major', 'critical'];

    public const CATEGORIES = ['injury', 'near_miss', 'property_damage', 'environmental', 'other'];

    protected static array $transitions = [
        self::STATUS_REPORTED => [self::STATUS_INVESTIGATING, self::STATUS_CLOSED],
        self::STATUS_INVESTIGATING => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    protected $fillable = [
        'incident_number',
        'title',
        'description',
        'incident_date',
        'location',
        'severity',
        'category',
        'status',
        'company_id',
        'project_id',
        'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** Milestone 4, Workstream B14 -- one-to-one investigation enhancement, not a duplicate incident system. */
    public function investigation()
    {
        return $this->hasOne(IncidentInvestigation::class);
    }

    /** Milestone 4, Workstream B14/B15 -- reusable CAPA, same entity Safety Observation/HSE Inspection already use. */
    public function correctiveActions()
    {
        return $this->morphMany(CorrectiveAction::class, 'source');
    }

    /** INC-{YEAR}-{00001}, same per-year sequential convention as Material Request/Leave. */
    /**
     * Milestone 3: delegates to the centralized, lock-safe Numbering
     * Engine -- see MaterialRequest::generateRequestNumber()'s doc
     * comment for why. Same INC-{YEAR}-{00001} shape as before by default.
     */
    public static function generateIncidentNumber(?int $companyId = null): string
    {
        return app(\App\Services\NumberGeneratorService::class)->generate('incident', $companyId);
    }
}
