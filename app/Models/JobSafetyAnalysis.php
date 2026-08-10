<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream B5 (JSA). Same shape/reasoning as
 * RiskAssessment -- see that model's own doc comment.
 */
class JobSafetyAnalysis extends Model
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
        'jsa_number', 'company_id', 'project_id', 'job_title', 'location', 'jsa_date',
        'prepared_by', 'reviewed_by', 'approved_by', 'required_ppe', 'steps', 'status',
    ];

    protected function casts(): array
    {
        return [
            'jsa_date' => 'date',
            'required_ppe' => 'array',
            'steps' => 'array',
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

    protected function notificationRecipient(): ?User
    {
        return $this->preparer;
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('jsa', $companyId);
    }
}
