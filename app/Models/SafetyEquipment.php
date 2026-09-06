<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B10. See the owning migration's own doc comment. */
class SafetyEquipment extends Model
{
    public const TYPES = ['fire_extinguisher', 'safety_shower', 'eyewash_station', 'emergency_alarm', 'spill_kit', 'other'];

    public const STATUSES = ['active', 'out_of_service'];

    protected $fillable = [
        'company_id', 'asset_id', 'name', 'type', 'location', 'serial_number',
        'last_inspection_date', 'next_inspection_due', 'status', 'notes',
        // v2.53.0 -- the REGISTER describes an actual unit, and points at
        // the MASTER that defines its type. See the
        // separate_equipment_master_from_register migration for why
        // lifecycle dates belong here while which lifecycles APPLY is a
        // property of the type.
        'equipment_type_id', 'equipment_code', 'brand', 'model', 'commissioned_at',
        'expiry_date', 'next_service_due', 'next_calibration_due',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'last_inspection_date' => 'date',
            'next_inspection_due' => 'date',
            'commissioned_at' => 'date',
            'expiry_date' => 'date',
            'next_service_due' => 'date',
            'next_calibration_due' => 'date',
        ];
    }

    /** The Equipment Master entry that defines this unit's type. */
    public function equipmentType()
    {
        return $this->belongsTo(HseEquipmentType::class, 'equipment_type_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Milestone 4, Acceleration Part 1C -- optional link to the Asset register, additive (see the owning migration's own doc comment). */
    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    /** v1.11.1 -- real inspection history, see SafetyEquipmentInspection's own doc comment. */
    public function inspections()
    {
        return $this->hasMany(SafetyEquipmentInspection::class)->latest('inspection_date');
    }

    /** Computed, not stored -- true once past next_inspection_due and still in service. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'active' && $this->next_inspection_due && $this->next_inspection_due->isPast();
    }
}
