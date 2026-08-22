<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * v1.11.6 (Production Readiness pass, Part 4). One row = one employee's
 * actual worked hours on one date -- see the owning migration's own doc
 * comment for why this is a new minimal record rather than reusing
 * EmployeeShiftAssignment/Shift (neither captures actual worked hours).
 * `total_hours` is deliberately an accessor, never a stored column --
 * regular_hours + overtime_hours is always the source of truth, so it
 * can never drift out of sync with what was actually entered.
 */
class ManHourLog extends Model
{
    protected $fillable = [
        'company_id',
        'employee_id',
        'project_id',
        'work_date',
        'regular_hours',
        'overtime_hours',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'regular_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getTotalHoursAttribute(): float
    {
        return (float) $this->regular_hours + (float) $this->overtime_hours;
    }
}
