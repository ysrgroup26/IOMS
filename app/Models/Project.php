<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Project is intentionally a SIMPLE grouping container -- not a project
 * management system. Future modules (Inspection, Gas Test, Permit, Daily
 * Report, Waste, Incident, Nearmiss, etc. -- none built yet, V2 scope) can
 * optionally reference project_id without changes to this model.
 */
class Project extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'vessel_name',
        'start_date',
        'end_date',
        'status',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manpower()
    {
        return $this->hasMany(ProjectManpower::class);
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'project_manpower')
            ->withPivot('assigned_date', 'added_by')
            ->withTimestamps();
    }

    public function timelineEvents()
    {
        return $this->hasMany(ProjectTimelineEvent::class)->orderBy('event_date')->orderBy('id');
    }

    public function dailyReports()
    {
        return $this->hasMany(DailyReport::class)->orderByDesc('report_date');
    }

    public function scopeInCompany($query, ?int $companyId)
    {
        return $companyId ? $query->where('company_id', $companyId) : $query;
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('vessel_name', 'like', "%{$term}%");
        });
    }

    /**
     * Employees on this project, grouped by department name -- matches the
     * "Manpower grouped by Department" display requirement in the spec.
     */
    /**
     * Employees on this project, grouped by department name -- matches the
     * "Manpower grouped by Department" display requirement in the spec.
     * Ordered by each employee's configured department/position display
     * order (v1.3.1). Joins are added directly here (not via
     * Employee::scopeOrderedForDisplay()) to avoid overriding this
     * relation's own pivot-column select() clause.
     */
    public function manpowerGroupedByDepartment()
    {
        return $this->employees()
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employees.position_id')
            ->with('department:id,name')
            ->orderBy('departments.sort_order')
            ->orderBy('departments.name')
            ->orderBy('positions.sort_order')
            ->orderBy('employees.full_name')
            ->get()
            ->groupBy(fn (Employee $e) => $e->department->name ?? 'Unassigned');
    }
}
