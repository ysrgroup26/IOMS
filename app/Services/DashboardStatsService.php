<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use App\Models\ManHourLog;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Aggregation logic for the Dashboard and Home pages: summary cards,
 * pie-by-department, monthly trend, leaderboards, and (v1.2) per-company
 * headcount breakdown, active projects, and today's activities.
 * Centralized here so Dashboard/Home controllers stay thin (SRP).
 */
class DashboardStatsService
{
    /**
     * Tenant-isolation fix (found while verifying Master -> Tenant
     * Management: a brand-new, company-less tenant's Dashboard was
     * showing another tenant's KPI/employee data). Every method below
     * used `inCompany($companyId)` / `when($companyId, ...)`, whose
     * existing behavior when $companyId is null is "apply no company
     * filter at all" -- correct in the single-tenant era this Dashboard
     * was originally built in, but wrong now that multiple tenants share
     * one database: "no company selected" must mean "every company THIS
     * TENANT owns", never "every company in the whole database".
     *
     * `Company`'s own TenantScope (App\Models\Scopes\TenantScope)
     * already correctly restricts `Company::query()` to the current
     * tenant (and fails closed to zero rows if no tenant is resolved,
     * per that class's own doc comment) -- this routes every Dashboard
     * aggregation through that restriction instead of skipping company
     * filtering whenever no specific company is picked.
     *
     * A requested $companyId that does not belong to the current
     * tenant -- a stale bookmark, or a deliberate attempt to probe
     * another tenant's data by guessing an id -- is treated exactly like
     * no selection at all: it silently falls back to the tenant's own
     * full company list, and never reveals whether that id exists
     * elsewhere.
     *
     * `whereIn('company_id', [])` (a tenant with zero companies) is a
     * real, well-defined SQL condition that matches zero rows -- so a
     * brand-new tenant correctly sees all-zero widgets instead of any
     * fallback data.
     */
    public function resolveCompanyIds(?int $companyId): array
    {
        $visible = Company::query()->pluck('id')->all();

        if ($companyId !== null && in_array($companyId, $visible, true)) {
            return [$companyId];
        }

        return $visible;
    }

    public function summaryCards(int $year, ?int $month = null, ?int $companyId = null): array
    {
        $companyIds = $this->resolveCompanyIds($companyId);

        // dashboardVisible() (not active()) is the actual fix for "the
        // Dashboard is still partially hardcoded" -- it's driven entirely
        // by is_active + show_on_dashboard + sort_order, so a newly
        // created category appears immediately once those flags are set,
        // and a disabled/hidden one disappears immediately, with no code
        // change in either direction.
        $categories = KpiCategory::dashboardVisible()->visibleForCompany($companyId)->get();

        $totals = KpiRecord::query()
            ->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->forPeriod($year, $month)
            ->whereIn('departments.company_id', $companyIds)
            ->selectRaw('kpi_categories.code, SUM(kpi_records.quantity) as total')
            ->groupBy('kpi_categories.code')
            ->pluck('total', 'code');

        return [
            'total_employees' => Employee::active()->whereIn('company_id', $companyIds)->count(),
            'categories' => $categories->map(fn ($cat) => [
                'id' => $cat->id,
                'code' => $cat->code,
                'name' => $cat->name,
                'short_label' => $cat->short_label,
                'is_negative' => $cat->is_negative,
                'icon' => $cat->effective_icon,
                'color' => $cat->effective_color,
                'total' => (int) ($totals->get($cat->code) ?? 0),
            ])->values(),
        ];
    }

    /**
     * Per-company employee headcount: Total Employee, GAJ, Maintenance,
     * Overall Total. Spec: "Dashboard: Total Employee / GAJ / Maintenance
     * / Overall Total". Returns one entry per active Company plus an
     * 'overall' total, so this stays correct even if more companies are
     * added later in Settings (not hardcoded to just GAJ/Maintenance).
     */
    public function companyHeadcount(): array
    {
        $byCompany = Company::active()
            ->withCount(['employees' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => [
                'company_id' => $c->id,
                'name' => $c->name,
                'total' => $c->employees_count,
            ]);

        return [
            'by_company' => $byCompany->values(),
            'overall_total' => (int) $byCompany->sum('total'),
        ];
    }

    /**
     * Pie chart: employee headcount by department (for the "by department" breakdown),
     * optionally scoped to one company.
     */
    public function departmentDistribution(?int $companyId = null): array
    {
        return Department::query()
            ->whereIn('company_id', $this->resolveCompanyIds($companyId))
            ->withCount(['employees' => fn ($q) => $q->active()])
            // v2.38.0: was `->having('employees_count', '>', 0)`. `withCount`
            // adds a correlated sub-select, and this query has no GROUP BY,
            // so filtering on that alias via HAVING is non-standard SQL --
            // MySQL tolerates it, SQLite rejects it outright ("HAVING clause
            // on a non-aggregate query"), which meant this dashboard query
            // 500'd on any non-MySQL database and could never be covered by
            // the test suite. `whereHas` expresses exactly the same intent
            // ("departments with at least one ACTIVE employee") in portable
            // SQL and returns an identical result set.
            //
            // NOTE: the `having('total', ...)` further down this file is a
            // DIFFERENT case -- it has a real GROUP BY, so it is valid
            // standard SQL and was deliberately left alone.
            ->whereHas('employees', fn ($q) => $q->active())
            ->get()
            ->map(fn ($d) => ['label' => $d->name, 'value' => $d->employees_count])
            ->values()
            ->toArray();
    }

    /**
     * Monthly trend for the current year, one series per KPI category, for
     * the Dashboard's trend line/bar chart (12 buckets, Jan-Dec). Uses the
     * same dashboardVisible() scope as summaryCards() -- this chart is
     * also a Dashboard widget, so it follows the same "generated entirely
     * from database configuration" rule (v1.5.2).
     */
    public function monthlyTrend(int $year, ?int $companyId = null): array
    {
        $categories = KpiCategory::dashboardVisible()->visibleForCompany($companyId)->get();

        $rows = KpiRecord::query()
            ->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $this->resolveCompanyIds($companyId))
            ->where('kpi_records.year', $year)
            ->selectRaw('kpi_records.month, kpi_categories.code, SUM(kpi_records.quantity) as total')
            ->groupBy('kpi_records.month', 'kpi_categories.code')
            ->get()
            ->groupBy('month');

        $labels = collect(range(1, 12))->map(fn ($m) => Carbon::create()->month($m)->format('M'));

        $series = $categories->map(function ($category) use ($rows) {
            $data = collect(range(1, 12))->map(function ($month) use ($rows, $category) {
                $monthRows = $rows->get($month, collect());
                $match = $monthRows->firstWhere('code', $category->code);

                return $match ? (int) $match->total : 0;
            });

            return [
                'label' => $category->short_label,
                'code' => $category->code,
                'data' => $data->values(),
            ];
        });

        return [
            'labels' => $labels->values(),
            'series' => $series->values(),
        ];
    }

    /**
     * Leaderboards: top department by incidents, most active employee (TBM),
     * most BBS reports, most TBM attendance -- per spec's "Dashboard Statistics".
     */
    public function leaderboards(int $year, ?int $companyId = null): array
    {
        $companyIds = $this->resolveCompanyIds($companyId);

        $topDeptByIncidents = KpiRecord::query()
            ->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $companyIds)
            ->where('kpi_records.year', $year)
            ->where('kpi_categories.is_negative', true)
            ->selectRaw('departments.name, SUM(kpi_records.quantity) as total')
            ->groupBy('departments.name')
            ->orderByDesc('total')
            ->first();

        $mostActiveEmployee = $this->topEmployeeByCategory($year, null, $companyId);
        $mostBbs = $this->topEmployeeByCategory($year, KpiCategory::BBS_NEARMISS, $companyId);
        $mostTbm = $this->topEmployeeByCategory($year, KpiCategory::TBM, $companyId);

        // Top Department Workload (v1.6.1): total KPI record volume per
        // department for the year -- the honest, already-tracked proxy
        // for "workload" here. There's no task-tracking module yet (no
        // "Tasks" table exists), so this deliberately reuses real KPI
        // activity counts rather than inventing a number with no backing
        // data.
        $topDepartmentsByWorkload = KpiRecord::query()
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $companyIds)
            ->where('kpi_records.year', $year)
            ->selectRaw('departments.name, SUM(kpi_records.quantity) as total')
            ->groupBy('departments.name')
            ->having('total', '>', 0)
            ->orderByDesc('total')
            ->limit(4)
            ->get();

        return [
            'top_department_incidents' => $topDeptByIncidents ? [
                'name' => $topDeptByIncidents->name,
                'total' => (int) $topDeptByIncidents->total,
            ] : null,
            'most_active_employee' => $mostActiveEmployee,
            'most_bbs_report' => $mostBbs,
            'most_tbm_attendance' => $mostTbm,
            'top_departments_workload' => $topDepartmentsByWorkload->map(fn ($d) => [
                'name' => $d->name,
                'total' => (int) $d->total,
            ])->values(),
        ];
    }

    /**
     * Active Projects count, optionally scoped by company.
     */
    public function activeProjectsCount(?int $companyId = null): int
    {
        return Project::query()
            ->whereIn('company_id', $this->resolveCompanyIds($companyId))
            ->whereIn('status', ['planned', 'ongoing'])
            ->count();
    }

    /**
     * Today's Activities: KPI records logged today, optionally scoped by company.
     */
    public function todaysActivities(?int $companyId = null): array
    {
        return KpiRecord::query()
            ->with('employee:id,full_name', 'kpiCategory:id,short_label')
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $this->resolveCompanyIds($companyId))
            ->select('kpi_records.*')
            ->whereDate('record_date', now()->toDateString())
            ->latest('kpi_records.id')
            ->limit(8)
            ->get()
            ->map(fn (KpiRecord $r) => [
                'id' => $r->id,
                'employee_name' => $r->employee->full_name,
                'category' => $r->kpiCategory->short_label,
            ])
            ->toArray();
    }

    /**
     * Upcoming Reminder: projects whose end_date falls within the next 14
     * days. This is a lightweight placeholder reminder source for v1.2 --
     * a dedicated reminder/notification engine (PPE replacement, service
     * years, etc.) is V2 scope per the spec.
     */
    public function upcomingReminders(?int $companyId = null): array
    {
        return Project::query()
            ->whereIn('company_id', $this->resolveCompanyIds($companyId))
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->orderBy('end_date')
            ->limit(5)
            ->get(['id', 'name', 'end_date'])
            ->map(fn (Project $p) => [
                'project_id' => $p->id,
                'label' => "{$p->name} ends {$p->end_date->format('d M Y')}",
            ])
            ->toArray();
    }

    private function topEmployeeByCategory(int $year, ?string $categoryCode, ?int $companyId = null): ?array
    {
        $query = KpiRecord::query()
            ->join('employees', 'employees.id', '=', 'kpi_records.employee_id')
            ->whereIn('employees.company_id', $this->resolveCompanyIds($companyId))
            ->where('kpi_records.year', $year)
            ->selectRaw('employees.id, employees.full_name, SUM(kpi_records.quantity) as total')
            ->groupBy('employees.id', 'employees.full_name')
            ->orderByDesc('total');

        if ($categoryCode) {
            $query->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
                ->where('kpi_categories.code', $categoryCode);
        }

        $top = $query->first();

        return $top ? ['employee_id' => $top->id, 'name' => $top->full_name, 'total' => (int) $top->total] : null;
    }

    /**
     * v1.11.6 (Production Readiness pass, Part 4) -- shared by
     * DashboardController and HseDashboardController, the two places
     * Man-Hours are surfaced. Returns null (not 0) when no ManHourLog
     * rows exist for the period at all, so an empty log reads as "not
     * recorded" rather than a genuine zero -- per the explicit
     * instruction not to let an empty dataset masquerade as a real zero.
     */
    public function sumManHours(array $companyIds, string $from, string $to): ?float
    {
        $query = ManHourLog::whereIn('company_id', $companyIds)->whereBetween('work_date', [$from, $to]);

        if (! $query->exists()) {
            return null;
        }

        return (float) $query->selectRaw('SUM(regular_hours + overtime_hours) as total')->value('total');
    }
}
