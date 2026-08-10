<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream B4 (HIRADC / Risk Assessment). Document-level
 * sign-off lifecycle via HasWorkflow (same choice as Incident/Safety
 * Observation -- HSE sign-off, not a separate Approval Engine gate).
 * `items` is the ordered list of hazard/risk/control rows -- see the
 * owning migration's doc comment for why this is JSON, not a child table.
 */
class RiskAssessment extends Model
{
    use HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_CANCELLED = 'cancelled';

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'ra_number', 'company_id', 'project_id', 'title', 'location', 'assessment_date',
        'prepared_by', 'reviewed_by', 'approved_by', 'items', 'status',
    ];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'items' => 'array',
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

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** HasWorkflow::notificationRecipient() doesn't know "preparer" -- point it at the right person explicitly. */
    protected function notificationRecipient(): ?User
    {
        return $this->preparer;
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('risk_assessment', $companyId);
    }
}
