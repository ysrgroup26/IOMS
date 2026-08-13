<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.1. Real inspection history for a SafetyEquipment row -- see the owning migration's own doc comment (mirrors GasTestRecord's own "child table, individually meaningful" reasoning). */
class SafetyEquipmentInspection extends Model
{
    public const CONDITIONS = ['good', 'fair', 'poor', 'damaged'];

    public const RESULTS = ['pass', 'fail', 'needs_action'];

    protected $fillable = [
        'safety_equipment_id', 'company_id', 'inspection_date', 'inspector_id',
        'condition', 'result', 'findings', 'next_inspection_due', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
            'next_inspection_due' => 'date',
        ];
    }

    public function safetyEquipment()
    {
        return $this->belongsTo(SafetyEquipment::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
