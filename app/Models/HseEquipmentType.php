<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.1. Configurable HSE operational equipment category master -- mirrors HazardCategory exactly. See the owning migration's own doc comment. */
class HseEquipmentType extends Model
{
    protected $fillable = [
        'company_id', 'name', 'code', 'code_prefix', 'description', 'is_active', 'sort_order',
        // v2.53.0 -- WHICH LIFECYCLES THIS TYPE HAS. A gas detector is
        // calibrated, a fire extinguisher expires, a safety shower is
        // serviced. Forcing an expiry date onto equipment that does not
        // expire produces either blank columns or invented data, so the
        // TYPE declares what applies and the register carries the dates.
        'tracks_inspection', 'tracks_calibration', 'tracks_service', 'tracks_expiry',
        'inspection_interval_months',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'tracks_inspection' => 'boolean',
            'tracks_calibration' => 'boolean',
            'tracks_service' => 'boolean',
            'tracks_expiry' => 'boolean',
            'inspection_interval_months' => 'integer',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Units of this type in the Equipment Register. */
    public function equipment()
    {
        return $this->hasMany(SafetyEquipment::class, 'equipment_type_id');
    }

    /** The lifecycles this type actually tracks, for a UI that should only ask for dates that apply. */
    public function trackedLifecycles(): array
    {
        return array_keys(array_filter([
            'inspection' => $this->tracks_inspection,
            'calibration' => $this->tracks_calibration,
            'service' => $this->tracks_service,
            'expiry' => $this->tracks_expiry,
        ]));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
