<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    // v1.10.5 fix: EmployeeFactory (database/factories/EmployeeFactory.php)
    // already existed and EmployeeSeeder already called Employee::factory()
    // -- this trait was simply missing, so db:seed failed on any run that
    // actually needed to seed demo employees (a fresh/empty database).
    // Unrelated to the Multi-Company migration; a separate, pre-existing gap.
    use HasFactory, SoftDeletes;

    // Milestone 4, Workstream A. Plain string constants, not a DB enum
    // (see the 2026_08_09_100101 migration's own doc comment for why) --
    // matches every other status/type field in this codebase.
    public const EMPLOYMENT_TYPE_PKWTT = 'pkwtt'; // permanent

    public const EMPLOYMENT_TYPE_PKWT = 'pkwt'; // fixed-term contract

    public const EMPLOYMENT_TYPE_DAILY = 'daily'; // pekerja harian

    public const EMPLOYMENT_TYPE_INTERN = 'intern'; // magang

    public const EMPLOYMENT_TYPE_PKL = 'pkl'; // praktik kerja lapangan

    public const EMPLOYMENT_TYPE_CONTRACTOR = 'contractor'; // external contractor worker

    public const EMPLOYMENT_TYPE_OUTSOURCE = 'outsource'; // outsourced worker

    public const EMPLOYMENT_TYPES = [
        self::EMPLOYMENT_TYPE_PKWTT,
        self::EMPLOYMENT_TYPE_PKWT,
        self::EMPLOYMENT_TYPE_DAILY,
        self::EMPLOYMENT_TYPE_INTERN,
        self::EMPLOYMENT_TYPE_PKL,
        self::EMPLOYMENT_TYPE_CONTRACTOR,
        self::EMPLOYMENT_TYPE_OUTSOURCE,
    ];

    protected $fillable = [
        'employee_id',
        'nik',
        'full_name',
        'company_id',
        'department_id',
        'position_id',
        'status',
        'employment_type',
        'photo_path',
        'join_date',
        'contract_start_date',
        'contract_end_date',
        'phone',
        'email',
        'address',
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
        ];
    }

    protected $appends = ['photo_url', 'profile_status', 'employment_type_label'];

    /**
     * Employee Import (v1.6.8). Deliberately a computed accessor, not a
     * stored column -- fully derivable from existing data, so it can
     * never drift out of sync with the record it describes. Only
     * `department_id` being null matters here: `employee_id` and
     * `full_name` are enforced NOT NULL at the database level, so an
     * already-saved employee record can never be missing either one --
     * "Need Completion" specifically means "imported without a
     * department," matching the spec's explicit list of what should (and
     * should not) affect this status.
     */
    public function getProfileStatusAttribute(): string
    {
        return $this->department_id === null ? 'needs_completion' : 'complete';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employeePpes()
    {
        return $this->hasMany(EmployeePpe::class);
    }

    /**
     * One-to-one intern/PKL detail record -- only meaningful (and only
     * ever populated) when employment_type is intern or pkl. See
     * database/migrations/2026_08_09_100102_create_employee_internships_table.php's
     * own doc comment for why this is a detail extension of Employee,
     * not a duplicate employee table.
     */
    public function internship()
    {
        return $this->hasOne(EmployeeInternship::class);
    }

    /**
     * Milestone 4, Workstream A2 (Training & Competency Management).
     */
    public function competencies()
    {
        return $this->hasMany(EmployeeCompetency::class);
    }

    public function isInternOrPkl(): bool
    {
        return in_array($this->employment_type, [self::EMPLOYMENT_TYPE_INTERN, self::EMPLOYMENT_TYPE_PKL], true);
    }

    public function employmentTypeLabel(): string
    {
        return match ($this->employment_type) {
            self::EMPLOYMENT_TYPE_PKWTT => 'PKWTT (Permanent)',
            self::EMPLOYMENT_TYPE_PKWT => 'PKWT (Fixed-Term Contract)',
            self::EMPLOYMENT_TYPE_DAILY => 'Daily Worker',
            self::EMPLOYMENT_TYPE_INTERN => 'Intern (Magang)',
            self::EMPLOYMENT_TYPE_PKL => 'PKL (Praktik Kerja Lapangan)',
            self::EMPLOYMENT_TYPE_CONTRACTOR => 'Contractor Worker',
            self::EMPLOYMENT_TYPE_OUTSOURCE => 'Outsourced Worker',
            default => (string) $this->employment_type,
        };
    }

    // Real Eloquent accessor (see getPhotoUrlAttribute()'s own comment on
    // why this pattern is used) so `employment_type_label` is
    // automatically present on every Employee JSON/Inertia response.
    public function getEmploymentTypeLabelAttribute(): string
    {
        return $this->employmentTypeLabel();
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function kpiRecords()
    {
        return $this->hasMany(KpiRecord::class);
    }

    public function projectAssignments()
    {
        return $this->hasMany(ProjectManpower::class);
    }

    public function ppeAssignments()
    {
        return $this->hasMany(EmployeePpe::class);
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_manpower')
            ->withPivot('assigned_date', 'added_by')
            ->withTimestamps();
    }

    /**
     * Real Eloquent accessor (not a plain method) so it's automatically
     * included in every JSON/Inertia response via $appends above --
     * v1.5.2 fix. Previously this was a plain photoUrl() method that
     * Eloquent never serializes, so every page that needed the photo had
     * to reconstruct the raw /storage/{path} string by hand instead.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Backs the Employee page's All/Complete/Need Completion filter --
     * a plain department_id null-check, matching the profile_status
     * accessor's own logic exactly, so the filter and the displayed
     * badge can never disagree with each other.
     */
    public function scopeProfileStatus($query, ?string $status)
    {
        if ($status === 'complete') {
            return $query->whereNotNull('department_id');
        }

        if ($status === 'needs_completion') {
            return $query->whereNull('department_id');
        }

        return $query;
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('full_name', 'like', "%{$term}%")
                ->orWhere('employee_id', 'like', "%{$term}%");
        });
    }

    public function scopeInDepartment($query, ?int $departmentId)
    {
        // Fully-qualified: `positions.department_id` also exists, so once
        // this is combined with scopeOrderedForDisplay() (which joins
        // positions), an unqualified column here becomes genuinely
        // ambiguous to MySQL (error 1052) -- this was the v1.3.2 regression.
        return $departmentId ? $query->where('employees.department_id', $departmentId) : $query;
    }

    public function scopeInCompany($query, ?int $companyId)
    {
        // Fully-qualified: `departments.company_id` also exists, so once
        // this is combined with scopeOrderedForDisplay() (which joins
        // departments), an unqualified column here becomes genuinely
        // ambiguous to MySQL (error 1052) -- same root cause as above.
        return $companyId ? $query->where('employees.company_id', $companyId) : $query;
    }

    /**
     * Orders employees by their Department's configured display order,
     * then their Position's configured display order, then name as a
     * final tiebreaker. This is the "all employee lists follow the
     * configured display order" behavior (v1.3.1): since it's the base
     * ordering, any downstream grouping-by-department (Reports,
     * Project Manpower, PPE issue picker) ends up with department
     * groups appearing in the configured sequence too, without each of
     * those call sites needing to re-sort anything themselves.
     *
     * Uses explicit joins (not whereHas/orderBy on the relation, which
     * Eloquent doesn't support directly) and selects only employees.* to
     * avoid column collisions with departments/positions.
     */
    public function scopeOrderedForDisplay($query)
    {
        return $query
            ->join('departments', 'departments.id', '=', 'employees.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employees.position_id')
            ->select('employees.*')
            ->orderBy('departments.sort_order')
            ->orderBy('departments.name')
            ->orderBy('positions.sort_order')
            ->orderBy('employees.full_name');
    }

    /**
     * Years of service, derived from join_date. Used today for display;
     * reserved for future PPE-replacement-reminder / service-year logic
     * per the v1.2 spec (not yet built).
     */
    public function yearsOfService(): ?float
    {
        return $this->join_date ? round($this->join_date->floatDiffInYears(now()), 1) : null;
    }

    /**
     * Aggregated KPI counts for this employee, optionally scoped by year/month.
     * Returns an associative array keyed by kpi_categories.code, e.g.
     * ['tbm' => 12, 'lti' => 0, 'bbs_nearmiss' => 3, ...]
     */
    public function kpiTotals(?int $year = null, ?int $month = null): array
    {
        $query = $this->kpiRecords()
            ->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
            ->selectRaw('kpi_categories.code as code, SUM(kpi_records.quantity) as total')
            ->groupBy('kpi_categories.code');

        if ($year) {
            $query->where('kpi_records.year', $year);
        }
        if ($month) {
            $query->where('kpi_records.month', $month);
        }

        return $query->pluck('total', 'code')->map(fn ($v) => (int) $v)->toArray();
    }
}
