<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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
    use BelongsToCompany;

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

    /**
     * v2.77.0 -- A WORK DATE IS A DATE, SO IT IS STORED AS ONE.
     *
     * The `date` cast serializes through the model's datetime format, so it
     * wrote "2026-09-30 00:00:00". A MySQL DATE column quietly truncates
     * that, which is why production never showed it; any store that keeps
     * the string does not, and there two things broke:
     *
     *   - a period ending 2026-09-30 excluded that day's hours, because
     *     "2026-09-30 00:00:00" sorts after "2026-09-30";
     *   - saving the same person and date again missed the existing row and
     *     hit the unique index instead of replacing the day's hours.
     *
     * Normalising on write fixes both at the source, for every driver,
     * rather than asking every query to remember whereDate(). Reading is
     * unchanged: the cast still returns a Carbon date.
     */
    public function setWorkDateAttribute($value): void
    {
        $this->attributes['work_date'] = $value === null
            ? null
            : \Illuminate\Support\Carbon::parse($value)->toDateString();
    }

    public function getTotalHoursAttribute(): float
    {
        return (float) $this->regular_hours + (float) $this->overtime_hours;
    }
}
