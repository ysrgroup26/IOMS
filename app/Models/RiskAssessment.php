<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Models\Concerns\BelongsToCompany;
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
    use BelongsToCompany, HasWorkflow, SoftDeletes;

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

    /**
     * v2.124.0 -- the same number, NOT taken. For a create page that
     * tells the reader what will be saved; see
     * NumberGeneratorService::preview() for why a preview must never
     * consume a sequence.
     */
    public static function previewNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->preview('risk_assessment', $companyId);
    }

    /**
     * v2.125.0 -- where a notification about this record opens.
     *
     * Every workflow notification in this product was created with a null
     * url, so the notification menu (which navigates only when one is set)
     * had nothing to navigate to: the reader tapped it, it was marked
     * read, and nothing happened. One line per model, named explicitly
     * rather than derived from the class name, because a derived route
     * name that is subtly wrong fails silently and this is the thing that
     * was silently missing in the first place.
     *
     * @see \App\Concerns\HasWorkflow::notificationUrl()
     */
    public function notificationRouteName(): ?string
    {
        return 'risk-assessments.show';
    }
}
