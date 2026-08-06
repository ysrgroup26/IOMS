<?php

namespace App\Models;

use App\Concerns\HasApprovals;
use App\Concerns\HasWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Material Request MVP (v1.6.8). Deliberately department-agnostic --
 * built for HSE first, but nothing here assumes HSE specifically. Any
 * department can use the same module without redesign.
 *
 * First real consumer of the Universal Approval Engine (v1.6.9) -- see
 * HasApprovals -- and the Workflow Engine (v1.6.9.1) -- see HasWorkflow.
 * "Pending Approval" is deliberately not a stored status distinct from
 * "submitted" -- see docs/ADR/006-material-request-workflow.md.
 */
class MaterialRequest extends Model
{
    use HasApprovals, HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The actual lifecycle guard HasWorkflow enforces. "Rejected" only
     * allows a return to "draft" -- and even that path is additionally
     * gated to Company Admin (Super Admin) in the controller, matching
     * "Rejected returns to Draft only if business rules allow" and the
     * Action Buttons spec, which shows Rejected as read-only
     * ("View Rejection Reason") for everyone else.
     */
    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_DRAFT],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'request_number',
        'request_date',
        'company_id',
        'project_id',
        'department_id',
        'requested_by',
        'status',
        'notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'completed_at' => 'datetime',
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

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items()
    {
        return $this->hasMany(MaterialRequestItem::class)->orderBy('sort_order');
    }

    public function scopeVisibleTo($query, $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where('company_id', $user->company_id);
    }

    /**
     * Milestone 3: delegates to the centralized, lock-safe Numbering
     * Engine (`App\Services\NumberGeneratorService`) instead of the old
     * unlocked `ORDER BY ... DESC LIMIT 1` read-then-write, which was a
     * real race condition under concurrent submissions. Same
     * MR-{YEAR}-{00001} shape as before by default.
     */
    public static function generateRequestNumber(?int $companyId = null): string
    {
        return app(\App\Services\NumberGeneratorService::class)->generate('material_request', $companyId);
    }
}
