<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 3. See the owning migration's own doc comment on how this differs from DailyReportActivity. */
class ProjectActivity extends Model
{
    public const STATUSES = ['not_started', 'in_progress', 'completed', 'on_hold'];

    protected $fillable = ['project_id', 'name', 'assigned_employee_id', 'progress', 'status'];

    protected function casts(): array
    {
        return ['progress' => 'integer'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedEmployee()
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }
}
