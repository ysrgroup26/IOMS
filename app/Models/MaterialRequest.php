<?php

namespace App\Models;

use App\Concerns\HasApprovals;
use App\Concerns\HasWorkflow;
use App\Models\Concerns\BelongsToCompany;
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
    use BelongsToCompany, HasApprovals, HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    /**
     * v2.69.0. Procurement has DELIBERATELY retained this demand so it
     * can be purchased together with related requests. This is normal,
     * correct procurement behaviour, not a problem -- see the class doc
     * comment and ADR/030.
     */
    public const STATUS_CONSOLIDATING = 'consolidating';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses where the requester is still owed something. Everything
     * else is terminal or not yet submitted, and neither ages.
     */
    public const OUTSTANDING_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_CONSOLIDATING,
        self::STATUS_PROCESSING,
    ];

    /**
     * Aging thresholds, in days since `request_date`.
     *
     * These are presentation emphasis, never business rules: nothing
     * transitions, expires or escalates because of them, and the exact
     * day count is always shown next to the label so the reader sees the
     * fact rather than only our opinion of it. They exist because "47
     * days" means nothing to someone scanning eighty rows.
     *
     * 14 and 30 are the operational reading of this workflow rather than
     * arbitrary round numbers: a request that has not moved inside two
     * weeks has missed at least one normal procurement cycle, and one
     * past a month has missed the monthly one. A future per-tenant
     * setting is the natural home for these (the same place numbering
     * formats went); a constant is honest until a customer asks.
     */
    public const AGING_ATTENTION_DAYS = 14;

    public const AGING_OVERDUE_DAYS = 30;

    /**
     * The actual lifecycle guard HasWorkflow enforces. "Rejected" only
     * allows a return to "draft" -- and even that path is additionally
     * gated to Company Admin (Super Admin) in the controller, matching
     * "Rejected returns to Draft only if business rules allow" and the
     * Action Buttons spec, which shows Rejected as read-only
     * ("View Rejection Reason") for everyone else.
     *
     * v2.69.0 adds `consolidating` between approval and processing, in
     * both directions: Procurement may retain approved demand, and must
     * be able to release it once there is enough to buy. It is reachable
     * ONLY from `approved` -- consolidating something already being
     * fulfilled would mean un-processing it, which is not a thing.
     */
    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_CONSOLIDATING, self::STATUS_PROCESSING, self::STATUS_CANCELLED],
        self::STATUS_CONSOLIDATING => [self::STATUS_PROCESSING, self::STATUS_APPROVED, self::STATUS_CANCELLED],
        // `processing -> approved` is the HAND-BACK path, and exists for
        // one caller: PurchaseRequisitionController::releaseSourcedDemand(),
        // when the purchase that was sourcing this demand is cancelled.
        // Without it a cancelled PR would strand every request it carried
        // in `processing` with nobody working on them -- the precise
        // failure this release exists to remove. It is not offered as a
        // user action anywhere.
        self::STATUS_PROCESSING => [self::STATUS_COMPLETED, self::STATUS_APPROVED, self::STATUS_CANCELLED],
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
        'consolidation_reason',
        'consolidated_by',
        'consolidated_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'completed_at' => 'datetime',
            'consolidated_at' => 'datetime',
        ];
    }

    /**
     * Exposed on every serialization so the index, the detail page and
     * the dashboards all read aging from one derivation instead of three.
     */
    protected $appends = ['open_age_days', 'aging_level', 'is_outstanding'];

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

    public function consolidatedBy()
    {
        return $this->belongsTo(User::class, 'consolidated_by');
    }

    /**
     * v2.69.0. Many-to-many, because consolidation means several requests
     * become one purchase. This replaces
     * `purchase_requisitions.source_material_request_id`, which could only
     * ever express one -- see the pivot's own migration for why the column
     * was dropped rather than kept alongside it.
     *
     * This is also the answer to "what is actually happening with my
     * request": the PR, and through it the RFQ and Purchase Order, are
     * where a request that has left the warehouse actually lives.
     */
    public function purchaseRequisitions()
    {
        return $this->belongsToMany(PurchaseRequisition::class, 'material_request_purchase_requisition')
            ->withTimestamps();
    }

    /** Still owed to the requester: submitted through processing. */
    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', self::OUTSTANDING_STATUSES);
    }

    /**
     * Oldest outstanding demand first -- the order every "what has been
     * sitting too long" view wants, and the reason this release added the
     * (status, request_date) index.
     */
    public function scopeAgingFirst($query)
    {
        return $query->orderBy('request_date');
    }

    public function getIsOutstandingAttribute(): bool
    {
        return in_array($this->status, self::OUTSTANDING_STATUSES, true);
    }

    /**
     * Days this request has been waiting. Null once it is no longer
     * outstanding, because a completed request has an age that means
     * nothing and would sort alongside live work if it were rendered.
     */
    public function getOpenAgeDaysAttribute(): ?int
    {
        if (! $this->is_outstanding || ! $this->request_date) {
            return null;
        }

        // `startOfDay` on both sides: a request raised this morning is 0
        // days old, not "1" because the clock crossed an hour boundary.
        return $this->request_date->startOfDay()->diffInDays(now()->startOfDay());
    }

    /** Presentation emphasis only -- see the AGING_* constants. */
    public function getAgingLevelAttribute(): ?string
    {
        $age = $this->open_age_days;

        if ($age === null) {
            return null;
        }

        return match (true) {
            $age >= self::AGING_OVERDUE_DAYS => 'overdue',
            $age >= self::AGING_ATTENTION_DAYS => 'attention',
            default => 'normal',
        };
    }

    /**
     * v2.12.0 (Product Finalization pass, Part 26 -- Security). CONFIRMED
     * P0 fix: this scope predates the Milestone 2 Tenancy Foundation --
     * back when "company" was the only top-level differentiator, "Super
     * Admin sees everything" correctly meant "sees every company," since
     * there was only ever one tenant. Multi-tenancy was added later
     * without updating this scope, so the Super Admin bypass below
     * (`return $query` with no further constraint) had been silently
     * returning EVERY TENANT's material requests, not just the current
     * tenant's -- a real cross-tenant leak for exactly the role most
     * likely to be trusted with broad access. Fixed by making the
     * tenant boundary (`Company::query()->pluck('id')`, already
     * TenantScope-filtered) an unconditional floor BEFORE the existing
     * Super-Admin-sees-every-company-in-their-own-tenant /
     * regular-user-sees-only-their-own-company-id narrowing, which is
     * otherwise unchanged.
     */
    public function scopeVisibleTo($query, $user)
    {
        $query->whereIn('company_id', Company::query()->pluck('id'));

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
