<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 4, Workstream A. One-to-one intern/PKL detail extension of
 * Employee -- see the owning migration's own doc comment
 * (2026_08_09_100102_create_employee_internships_table) for why this is
 * a detail table, not a duplicate employee table.
 */
class EmployeeInternship extends Model
{
    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_TERMINATED = 'terminated';

    protected $fillable = [
        'employee_id',
        'institution',
        'program',
        'mentor_name',
        'agreement_number',
        'start_date',
        'end_date',
        'work_location',
        'induction_completed',
        'insurance_coverage',
        'evaluation',
        'completion_status',
        'certificate_path',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'induction_completed' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
