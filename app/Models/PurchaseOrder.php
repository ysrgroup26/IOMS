<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream C4 (Purchase Order). See the owning migration's
 * own doc comment. Same HasWorkflow engine as every other document
 * lifecycle -- no second approval system. Approval gate is
 * config('workflow.approvers') (Manager/Super Admin), same segregation-
 * of-duties precedent as PurchaseRequisition/MaterialRequest -- amount-
 * based/per-tenant-configurable approval THRESHOLDS are a documented
 * future extension point (see docs/MODULES.md), not fabricated here.
 */
class PurchaseOrder extends Model
{
    use HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PARTIALLY_DELIVERED = 'partially_delivered';

    public const STATUS_FULLY_DELIVERED = 'fully_delivered';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_ISSUED, self::STATUS_CANCELLED],
        // partially_delivered/fully_delivered are reached AUTOMATICALLY --
        // see GoodsReceiptController::store()'s own comment -- from real
        // delivered-vs-ordered quantity math, never a manual button.
        self::STATUS_ISSUED => [self::STATUS_PARTIALLY_DELIVERED, self::STATUS_FULLY_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_PARTIALLY_DELIVERED => [self::STATUS_FULLY_DELIVERED, self::STATUS_CANCELLED],
        self::STATUS_FULLY_DELIVERED => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'po_number', 'company_id', 'vendor_id', 'purchase_requisition_id', 'rfq_id', 'vendor_quotation_id',
        'project_id', 'department_id', 'cost_center', 'po_date', 'delivery_date', 'delivery_location',
        'payment_terms', 'currency', 'subtotal', 'discount_amount', 'tax_amount', 'shipping_amount',
        'other_charges', 'grand_total', 'notes', 'terms_conditions', 'attachment_path',
        'requested_by', 'approved_by', 'issued_by', 'issued_at', 'closed_at', 'status',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'delivery_date' => 'date',
            'issued_at' => 'datetime',
            'closed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseRequisition()
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function rfq()
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendorQuotation()
    {
        return $this->belongsTo(VendorQuotation::class);
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

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** Real delivery-overdue flag -- issued/partially delivered, past its own delivery_date. */
    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIALLY_DELIVERED], true)
            && $this->delivery_date && $this->delivery_date->isPast();
    }

    // No notificationRecipient() override needed -- `requester()`/
    // `requested_by` already match HasWorkflow's own default convention,
    // same as PermitToWork.

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('purchase_order', $companyId);
    }
}
