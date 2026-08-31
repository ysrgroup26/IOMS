<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream B6 (Permit To Work). See the owning migration's
 * doc comment (2026_08_20_100067) for the deliberate "required_qualification
 * is optional, never auto-checked" design.
 */
class PermitToWork extends Model
{
    use HasWorkflow, SoftDeletes;

    // Production bug fix: Eloquent's default table-name inference
    // pluralizes "PermitToWork" as `permit_to_works` (naive last-word
    // pluralization), but the owning migration
    // (2026_08_20_100067_create_permits_to_work_table) -- and the FKs in
    // gas_test_records/loto_records that reference it -- all use
    // `permits_to_work`. Explicit $table makes the model match the real,
    // already-migrated table instead of the other way around.
    protected $table = 'permits_to_work';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPES = ['hot_work', 'cold_work', 'confined_space', 'working_at_height', 'excavation', 'electrical', 'general'];

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'ptw_number', 'company_id', 'project_id', 'risk_assessment_id', 'jsa_id',
        'permit_type', 'work_description', 'location', 'start_datetime', 'end_datetime',
        'required_qualification', 'precautions', 'requested_by', 'area_authority_id',
        // v2.17.0 (PTW Field Workflow Foundation, Part 8): PIC / Supervisor
        // Lapangan -- a separate person from `requested_by` (see this
        // column's own migration doc comment).
        'pic_employee_id',
        'hse_approver_id', 'closed_by', 'closed_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
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

    public function riskAssessment()
    {
        return $this->belongsTo(RiskAssessment::class);
    }

    public function jsa()
    {
        return $this->belongsTo(JobSafetyAnalysis::class, 'jsa_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function areaAuthority()
    {
        return $this->belongsTo(User::class, 'area_authority_id');
    }

    /**
     * v2.17.0 (PTW Field Workflow Foundation, Part 8). References
     * `Employee`, not `User` -- unlike `requester()`/`areaAuthority()`/
     * `hseApprover()` above, a PIC is not necessarily an IOMS login at
     * all (confirmed by audit: `Employee` has no `user_id`/`User`
     * relation in this codebase, they're deliberately separate
     * identities). Optional -- see this column's own migration comment.
     */
    public function pic()
    {
        return $this->belongsTo(Employee::class, 'pic_employee_id');
    }

    /**
     * v2.17.0 (PTW Field Workflow Foundation, Part 9). The permit's
     * overall planned workforce -- distinct from, and never duplicated
     * into, any JSA-level manpower concept (JSA has none today, confirmed
     * by audit; this pass does not add one, per "do NOT force manpower
     * into JSA simply because PTW now has it"). Always drawn from real
     * `Employee` records, never free text.
     */
    public function personnel()
    {
        return $this->belongsToMany(Employee::class, 'permit_to_work_personnel', 'permit_to_work_id', 'employee_id');
    }

    public function hseApprover()
    {
        return $this->belongsTo(User::class, 'hse_approver_id');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function gasTests()
    {
        return $this->hasMany(GasTestRecord::class);
    }

    public function lotoRecords()
    {
        return $this->hasMany(LotoRecord::class);
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('permit_to_work', $companyId);
    }
}
