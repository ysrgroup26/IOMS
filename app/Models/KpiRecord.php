<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiRecord extends Model
{
    protected $fillable = [
        'employee_id',
        'department_id',
        'kpi_category_id',
        'record_date',
        'month',
        'year',
        'quantity',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'record_date' => 'date',
            'quantity' => 'integer',
            'month' => 'integer',
            'year' => 'integer',
        ];
    }

    /**
     * Auto-derive month/year/department from record_date and employee
     * whenever a record is created, so callers only need to supply
     * employee_id, kpi_category_id, record_date, remarks.
     */
    protected static function booted(): void
    {
        static::creating(function (KpiRecord $record) {
            $date = $record->record_date ?? now();
            $record->month = $record->month ?? (int) $date->format('n');
            $record->year = $record->year ?? (int) $date->format('Y');

            if (! $record->department_id && $record->employee_id) {
                $record->department_id = Employee::find($record->employee_id)?->department_id;
            }
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function kpiCategory()
    {
        return $this->belongsTo(KpiCategory::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForPeriod($query, ?int $year, ?int $month = null)
    {
        if ($year) {
            $query->where('kpi_records.year', $year);
        }
        if ($month) {
            $query->where('kpi_records.month', $month);
        }

        return $query;
    }

    public function scopeForDepartment($query, ?int $departmentId)
    {
        return $departmentId ? $query->where('kpi_records.department_id', $departmentId) : $query;
    }
}
