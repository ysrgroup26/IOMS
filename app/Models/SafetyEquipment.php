<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B10. See the owning migration's own doc comment. */
class SafetyEquipment extends Model
{
    public const TYPES = ['fire_extinguisher', 'safety_shower', 'eyewash_station', 'emergency_alarm', 'spill_kit', 'other'];

    public const STATUSES = ['active', 'out_of_service'];

    protected $fillable = [
        'company_id', 'name', 'type', 'location', 'serial_number',
        'last_inspection_date', 'next_inspection_due', 'status', 'notes',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'last_inspection_date' => 'date',
            'next_inspection_due' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Computed, not stored -- true once past next_inspection_due and still in service. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'active' && $this->next_inspection_due && $this->next_inspection_due->isPast();
    }
}
