<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream B1 (Safety Observation). HSE's first module
 * beyond PPE/Incident. Workflow Engine only, same choice as Incident --
 * closing/verifying an observation is an operational HSE decision, not a
 * multi-party approval. See the owning migration's own doc comment
 * (2026_08_19_100062_create_safety_observations_table).
 */
class SafetyObservation extends Model
{
    use HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_UNSAFE_ACT = 'unsafe_act';

    public const TYPE_UNSAFE_CONDITION = 'unsafe_condition';

    public const TYPE_POSITIVE = 'positive';

    public const TYPES = [self::TYPE_UNSAFE_ACT, self::TYPE_UNSAFE_CONDITION, self::TYPE_POSITIVE];

    /** Same scale as Incident::SEVERITIES -- one consistent HSE severity vocabulary. */
    public const SEVERITIES = ['minor', 'moderate', 'major', 'critical'];

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_OPEN, self::STATUS_CANCELLED],
        self::STATUS_OPEN => [self::STATUS_ASSIGNED, self::STATUS_CANCELLED],
        self::STATUS_ASSIGNED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_PENDING_VERIFICATION, self::STATUS_CANCELLED],
        self::STATUS_PENDING_VERIFICATION => [self::STATUS_CLOSED, self::STATUS_IN_PROGRESS],
        self::STATUS_CLOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'observation_number',
        'company_id',
        'project_id',
        'hazard_category_id',
        'observed_at',
        'location',
        'reported_by',
        'type',
        'description',
        'immediate_action',
        'severity',
        'assigned_to',
        'due_date',
        'status',
        'closed_by',
        'closed_at',
        'closure_notes',
    ];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'due_date' => 'date',
            'closed_at' => 'datetime',
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

    public function hazardCategory()
    {
        return $this->belongsTo(HazardCategory::class);
    }

    /**
     * `reported_by` is the column name (see migration's doc comment for
     * why -- HasWorkflow::notificationRecipient() reads it for free); the
     * relation is called `reporter()`, same name as Incident's, and is
     * ALSO what that same trait method checks first.
     */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function photos()
    {
        return $this->hasMany(SafetyObservationPhoto::class);
    }

    /** Reusable CAPA -- see CorrectiveAction's own doc comment. */
    public function correctiveActions()
    {
        return $this->morphMany(CorrectiveAction::class, 'source');
    }

    public static function generateObservationNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('safety_observation', $companyId);
    }
}
