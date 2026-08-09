<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 4, Workstream A3. Which Shift an Employee is assigned to for
 * a dated period -- see the owning migration's own doc comment
 * (2026_08_09_113302_create_employee_shift_assignments_table).
 */
class EmployeeShiftAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id',
        'shift_id',
        'effective_date',
        'end_date',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * "Currently effective" -- active status AND today falls within
     * [effective_date, end_date or open-ended]. Computed, matching this
     * codebase's own established convention for anything date-derived
     * (see EmployeePpe/EmployeeCompetency's own effective_status
     * accessors) rather than a separately-maintained boolean that could
     * drift from the dates.
     */
    public function scopeCurrent($query)
    {
        $today = now()->toDateString();

        return $query->where('status', self::STATUS_ACTIVE)
            ->where('effective_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });
    }
}
