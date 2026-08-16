<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.4 (HSE Waste Management, Part 16). See the owning migration's own doc comment (mirrors SafetyEquipmentInspection/GasTestRecord's "child log" pattern). */
class WasteMovement extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_DISPOSED = 'disposed';

    public const STATUSES = [self::STATUS_SCHEDULED, self::STATUS_PICKED_UP, self::STATUS_DISPOSED];

    protected $fillable = [
        'waste_record_id', 'company_id', 'vendor_id', 'manifest_number',
        'pickup_date', 'destination', 'disposal_date', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['pickup_date' => 'date', 'disposal_date' => 'date'];
    }

    public function wasteRecord()
    {
        return $this->belongsTo(WasteRecord::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(WasteMovementDocument::class);
    }
}
