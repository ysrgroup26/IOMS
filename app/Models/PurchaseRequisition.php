<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream C2 (Purchase Requisition). See the owning
 * migration's own doc comment. Lifecycle matches the spec's own suggested
 * chain exactly (draft -> submitted -> under_review -> approved/rejected
 * -> converted_to_rfq -> converted_to_po -> completed, plus cancelled from
 * most states) via HasWorkflow, same engine as every other document
 * lifecycle in this codebase -- no second approval engine.
 */
class PurchaseRequisition extends Model
{
    use HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED_TO_RFQ = 'converted_to_rfq';

    public const STATUS_CONVERTED_TO_PO = 'converted_to_po';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_UNDER_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_CONVERTED_TO_RFQ, self::STATUS_CANCELLED],
        // converted_to_rfq/converted_to_po are reached automatically (see
        // RfqController::store()/PurchaseOrderController::store()), not
        // via a manual transition button -- they still go through
        // transitionTo() so the guard/ActivityLog/notification all still
        // fire consistently.
        self::STATUS_CONVERTED_TO_RFQ => [self::STATUS_CONVERTED_TO_PO, self::STATUS_CANCELLED],
        self::STATUS_CONVERTED_TO_PO => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'pr_number', 'company_id', 'project_id', 'department_id', 'source_material_request_id',
        'cost_center', 'requested_by', 'request_date', 'priority', 'required_date', 'justification',
        'items', 'estimated_total', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'required_date' => 'date',
            'items' => 'array',
            'estimated_total' => 'decimal:2',
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

    public function sourceMaterialRequest()
    {
        return $this->belongsTo(MaterialRequest::class, 'source_material_request_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function rfqs()
    {
        return $this->hasMany(Rfq::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('purchase_requisition', $companyId);
    }
}
