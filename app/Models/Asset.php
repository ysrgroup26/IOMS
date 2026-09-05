<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Acceleration Part 1C (Asset Management). See the owning migration's own doc comment. */
class Asset extends Model
{
    use HasSecureDocument;
    use SoftDeletes;

    public const STATUSES = ['active', 'assigned', 'under_maintenance', 'retired', 'disposed'];

    public const CATEGORIES = ['Heavy Equipment', 'Vehicle', 'Marine Equipment', 'Workshop Equipment', 'Safety Equipment', 'Measuring Equipment', 'Other'];

    protected $fillable = [
        'asset_code', 'company_id', 'name', 'category', 'serial_number', 'brand', 'model',
        'purchase_date', 'vendor_id', 'purchase_order_id', 'location', 'responsible_employee_id',
        'status', 'attachment_path', 'notes',
    ];

    protected $appends = ['attachment_url'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function responsibleEmployee()
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function transactions()
    {
        return $this->hasMany(AssetTransaction::class)->latest('transaction_date')->latest('id');
    }

    public function safetyEquipment()
    {
        return $this->hasOne(SafetyEquipment::class);
    }

    public function maintenanceRequests()
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['retired', 'disposed'])->orderBy('name');
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path ? $this->secureDocumentUrl() : null;
    }

    public static function generateCode(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('asset', $companyId);
    }

    /** v2.38.0 (Master Audit): see App\Concerns\HasSecureDocument. */
    public function secureDocumentPathColumn(): string
    {
        return 'attachment_path';
    }
}
