<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use BelongsToCompany;

    // v1.10.5 fix: EmployeeFactory (database/factories/EmployeeFactory.php)
    // already existed and EmployeeSeeder already called Employee::factory()
    // -- this trait was simply missing, so db:seed failed on any run that
    // actually needed to seed demo employees (a fresh/empty database).
    // Unrelated to the Multi-Company migration; a separate, pre-existing gap.
    //
    // v2.115.0 -- `SoftDeletes` IS DELIBERATELY ABSENT.
    //
    // It was here, and the reasoning was defensible: `employees` is the
    // target of a long row of cascading keys, so a hard delete takes a
    // person's operational history with it. What it actually produced was
    // a product that could not recover from a bad import -- nothing in
    // IOMS ever showed a deleted employee or offered to restore one, so
    // the only observable effect was that a row the customer had
    // deliberately removed went on holding its employee number and NIK
    // against unique indexes that know nothing about `deleted_at`. An
    // import of 75 people was refused row by row as duplicates of
    // employees the Employees page said did not exist.
    //
    // Deletion is now PERMANENT, and `App\Services\EmployeeEraser` is the
    // one place that answers for every reference to the row: records that
    // only describe the employee go with them, records of their own keep
    // themselves and lose the pointer, and the two documents that PRINT
    // the name keep it as text. Do not re-add this trait without reading
    // ADR 055 -- it would restore the defect, not the safety.
    use HasFactory;

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

    /**
     * v2.69.0 -- Employee Cases (HR employee relations / discipline).
     */
    public function cases()
    {
        return $this->hasMany(EmployeeCase::class)->orderByDesc('reported_at');
    }

    /**
     * Every disciplinary action ever issued to this person, across all of
     * their cases -- the actual "historical record" this module exists to
     * provide, as opposed to a current-state flag.
     */
    public function caseActions()
    {
        return $this->hasManyThrough(
            EmployeeCaseAction::class,
            EmployeeCase::class,
            'employee_id',
            'employee_case_id',
        );
    }

    /**
     * v2.69.0 -- THE EMPLOYEE'S CURRENT STANDING, DERIVED AND NEVER STORED.
     *
     * The most severe disciplinary action still in force, or null for a
     * clean standing. This is deliberately NOT a column on `employees`,
     * for the reason a column could not survive: an SP lapses on a date,
     * and nothing would be watching. Derived from the actions' own
     * validity windows, the answer is correct on every read, including the
     * morning after one expires, with no scheduled job to run or forget.
     *
     * Same principle as `profile_status` directly above, and as
     * PurchaseOrderItem::delivered_quantity -- if it can be computed from
     * records that already exist, it is not state.
     *
     * Not appended: it costs a query per employee, so a list of 200 people
     * must not pay for it. Callers that need it load it explicitly.
     */
    public function currentDisciplinaryStanding(): ?EmployeeCaseAction
    {
        return $this->caseActions()
            ->inForce()
            ->get()
            ->sortByDesc('severity_rank')
            ->first();
    }

    /**
     * Milestone 4, Workstream A3 (Shift & Roster Management).
     */
    public function shiftAssignments()
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function rosters()
    {
        return $this->hasMany(EmployeeRoster::class);
    }

    public function currentShiftAssignment()
    {
        return $this->hasOne(EmployeeShiftAssignment::class)->current()->latest('effective_date');
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
        /*
         * v2.113.0 -- fully-qualified, for the third time in this class.
         * `positions.department_id` also exists, so once this is combined
         * with scopeOrderedForDisplay() (which joins positions) an
         * unqualified column here is genuinely ambiguous -- the same root
         * cause already written up on scopeInDepartment() (v1.3.2) and
         * scopeInCompany() (v2.2.0), which were fixed while this scope
         * was not. Filtering the Employees list by profile status was a
         * hard 500 on every use, on MySQL and on SQLite alike.
         */
        if ($status === 'complete') {
            return $query->whereNotNull('employees.department_id');
        }

        if ($status === 'needs_completion') {
            return $query->whereNull('employees.department_id');
        }

        return $query;
    }

    public function scopeSearch($query, ?string $term)
    {
        if (! $term) {
            return $query;
        }

        // Qualified for the same reason as every other scope in this
        // class: this one runs beside two joined tables.
        return $query->where(function ($q) use ($term) {
            $q->where('employees.full_name', 'like', "%{$term}%")
                ->orWhere('employees.employee_id', 'like', "%{$term}%");
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
        /*
         * v2.113.0 -- THIS WAS AN INNER JOIN, AND IT HID PEOPLE.
         *
         * `department_id` is nullable on purpose: "Unassigned" is a real,
         * supported state, which is the entire reason `profile_status`
         * exists and reports `needs_completion`. An INNER JOIN on
         * `departments` therefore did not order the list -- it FILTERED
         * it, silently, dropping every employee who had no department
         * yet.
         *
         * Nine call sites read this scope, so the same row vanished from
         * the Employees list, the Excel export, the printed register, the
         * Document Library register, KPI input, Man-Hour, Projects and
         * PPE. The visible symptom was an import that reported "50
         * imported successfully" and then showed nothing: the records
         * were in the database the whole time, and the list was refusing
         * to admit it. Filtering a `profile_status` of `needs_completion`
         * could likewise only ever return nothing.
         *
         * A LEFT JOIN orders without filtering. Unassigned people sort
         * LAST rather than first, because `sort_order` is NULL for them
         * and NULL sorts first on both MySQL and SQLite -- a roster that
         * opened with everybody who is missing a department would be a
         * different kind of wrong. The CASE expression is plain SQL and
         * needs no driver guard (see CONVENTIONS.md on driver-aware SQL).
         */
        return $query
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->leftJoin('positions', 'positions.id', '=', 'employees.position_id')
            ->select('employees.*')
            ->orderByRaw('CASE WHEN departments.id IS NULL THEN 1 ELSE 0 END')
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
