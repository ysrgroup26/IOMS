<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
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
    public function summaryCards(int $year, ?int $month = null, ?int $companyId = null): array
    {
        // dashboardVisible() (not active()) is the actual fix for "the
        // Dashboard is still partially hardcoded" -- it's driven entirely
        // by is_active + show_on_dashboard + sort_order, so a newly
        // created category appears immediately once those flags are set,
        // and a disabled/hidden one disappears immediately, with no code
        // change in either direction.
        $categories = KpiCategory::dashboardVisible()->visibleForCompany($companyId)->get();

        $totals = KpiRecord::query()
            ->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
            ->forPeriod($year, $month)
            ->when($companyId, function ($q) use ($companyId) {
                $q->join('departments', 'departments.id', '=', 'kpi_records.department_id')
                    ->where('departments.company_id', $companyId);
            })
            ->selectRaw('kpi_categories.code, SUM(kpi_records.quantity) as total')
            ->groupBy('kpi_categories.code')
            ->pluck('total', 'code');

        return [
            'total_employees' => Employee::active()->inCompany($companyId)->count(),
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
            ->inCompany($companyId)
            ->withCount(['employees' => fn ($q) => $q->active()])
            ->having('employees_count', '>', 0)
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
            ->when($companyId, function ($q) use ($companyId) {
                $q->join('departments', 'departments.id', '=', 'kpi_records.department_id')
                    ->where('departments.company_id', $companyId);
            })
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
        $topDeptByIncidents = KpiRecord::query()
            ->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->when($companyId, fn ($q) => $q->where('departments.company_id', $companyId))
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
            ->when($companyId, fn ($q) => $q->where('departments.company_id', $companyId))
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
            ->inCompany($companyId)
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
            ->when($companyId, function ($q) use ($companyId) {
                $q->join('departments', 'departments.id', '=', 'kpi_records.department_id')
                    ->where('departments.company_id', $companyId)
                    ->select('kpi_records.*');
            })
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
            ->inCompany($companyId)
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
            ->when($companyId, fn ($q) => $q->where('employees.company_id', $companyId))
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
}
