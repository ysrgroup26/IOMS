<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Workstream C1 (Vendor/Supplier Master). See the owning migration's own doc comment. */
class Vendor extends Model
{
    use SoftDeletes;

    public const TYPES = ['goods', 'services', 'both'];

    public const QUALIFICATION_DRAFT = 'draft';

    public const QUALIFICATION_UNDER_REVIEW = 'under_review';

    public const QUALIFICATION_QUALIFIED = 'qualified';

    public const QUALIFICATION_CONDITIONAL = 'conditionally_qualified';

    public const QUALIFICATION_REJECTED = 'rejected';

    public const QUALIFICATION_SUSPENDED = 'suspended';

    public const QUALIFICATION_EXPIRED = 'expired';

    public const QUALIFICATION_STATUSES = [
        self::QUALIFICATION_DRAFT, self::QUALIFICATION_UNDER_REVIEW, self::QUALIFICATION_QUALIFIED,
        self::QUALIFICATION_CONDITIONAL, self::QUALIFICATION_REJECTED, self::QUALIFICATION_SUSPENDED,
        self::QUALIFICATION_EXPIRED,
    ];

    protected $fillable = [
        'vendor_code', 'company_id', 'name', 'type', 'legal_entity_name', 'address', 'city',
        'province', 'country', 'pic_name', 'pic_phone', 'pic_email', 'website', 'npwp', 'nib',
        'bank_name', 'bank_account_number', 'bank_account_holder', 'payment_terms', 'tax_info',
        'category', 'capability', 'is_active', 'qualification_status', 'reviewed_by', 'reviewed_at',
        'qualified_until', 'rejection_reason', 'notes',
        // v1.11.4, HSE Waste Management (Part 16) -- see the owning
        // migration's own doc comment. Independent of `type`/`category`;
        // a vendor can be a goods/services vendor AND a waste vendor.
        'is_waste_vendor',
    ];

    protected $appends = ['is_qualification_expired'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reviewed_at' => 'date',
            'qualified_until' => 'date',
            'is_waste_vendor' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents()
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function quotations()
    {
        return $this->hasMany(VendorQuotation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /** v1.11.4, HSE Waste Management -- vendors flagged as licensed waste transporters/disposal handlers. */
    public function scopeWasteVendors($query)
    {
        return $query->where('is_waste_vendor', true);
    }

    /** A vendor considered actually usable for a new RFQ/PO right now -- qualified, not suspended/expired. */
    public function scopeQualified($query)
    {
        return $query->where('qualification_status', self::QUALIFICATION_QUALIFIED)
            ->where(fn ($q) => $q->whereNull('qualified_until')->orWhereDate('qualified_until', '>=', now()));
    }

    public function getIsQualificationExpiredAttribute(): bool
    {
        return $this->qualified_until !== null && $this->qualified_until->isPast()
            && ! in_array($this->qualification_status, [self::QUALIFICATION_EXPIRED, self::QUALIFICATION_REJECTED, self::QUALIFICATION_SUSPENDED], true);
    }

    public static function generateVendorCode(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('vendor', $companyId);
    }
}
