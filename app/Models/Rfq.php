<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Workstream C3 (RFQ). See the owning migration's own doc comment. */
class Rfq extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'rfq_number', 'company_id', 'purchase_requisition_id', 'buyer_id', 'issue_date',
        'quotation_deadline', 'currency', 'delivery_location', 'delivery_requirement',
        'payment_terms', 'notes', 'status', 'selected_vendor_id', 'evaluation_notes',
        'selected_by', 'selected_at',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'quotation_deadline' => 'date',
            'selected_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function purchaseRequisition()
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function selectedVendor()
    {
        return $this->belongsTo(Vendor::class, 'selected_vendor_id');
    }

    public function selector()
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function invitedVendors()
    {
        return $this->belongsToMany(Vendor::class, 'rfq_vendors')->withPivot('status', 'invited_at')->withTimestamps();
    }

    public function rfqVendors()
    {
        return $this->hasMany(RfqVendor::class);
    }

    public function quotations()
    {
        return $this->hasMany(VendorQuotation::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** True once the deadline has passed and no decision has been made yet -- a real, computed "needs attention" flag for the dashboard/index, not stored. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::STATUS_ISSUED && $this->quotation_deadline->isPast();
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('rfq', $companyId);
    }
}
