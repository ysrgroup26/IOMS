<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreManHourLogRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ManHourLog;
use App\Models\Project;
use App\Services\DashboardStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Man-Hour (v1.11.6, Production Readiness pass, Part 4). A minimal,
 * real operational log -- one row per employee per work date -- built
 * because no existing model (EmployeeShiftAssignment/Shift) actually
 * captures worked hours (see ManHourLog's own migration doc comment).
 * Deliberately simple: no workflow/approval state, matching the "proper
 * operational input source, not a new bureaucracy" spirit of the
 * request. HRD enters records; every department's dashboard reads the
 * aggregated totals (see HseDashboardController/DashboardController).
 */
class ManHourController extends Controller
{
    public function __construct(private readonly DashboardStatsService $dashboardStats) {}

    public function index(Request $request): Response
    {
        /*
         * v2.77.0 -- the period is validated, not trusted. A malformed or
         * reversed range used to reach whereBetween() as-is and silently
         * return nothing, which read as "no hours worked".
         */
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'company_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'string', 'max:20'],
        ]);

        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $companyIds = $this->dashboardStats->resolveCompanyIds($companyId);
        $from = $request->input('from') ?: Carbon::now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: Carbon::now()->toDateString();

        // ONE filtered query. The table, the headline figures and the
        // project breakdown are all read from it, so they cannot disagree.
        $filtered = ManHourLog::query()
            ->whereIn('man_hour_logs.company_id', $companyIds)
            ->whereBetween('man_hour_logs.work_date', [$from, $to])
            ->when($request->input('employee_id'), fn ($q, $v) => $q->where('man_hour_logs.employee_id', $v))
            ->when($request->input('project_id'), fn ($q, $v) => $v === 'none'
                ? $q->whereNull('man_hour_logs.project_id')
                : $q->where('man_hour_logs.project_id', (int) $v))
            ->when($request->input('department_id'), fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)));

        $logs = (clone $filtered)
            ->with('employee:id,full_name,department_id,company_id', 'employee.department:id,name', 'project:id,name', 'recordedBy:id,name')
            ->orderByDesc('work_date')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('ManHour/Index', [
            'logs' => $logs,
            // ROOT CAUSE of the reported Man-Hour HTTP 500 (v2.2.0
            // investigation): `orderedForDisplay()` INNER JOINs
            // `departments` and LEFT JOINs `positions` -- both of which
            // (since 2026_07_16_100002 / 2026_08_11_100035) now have
            // their own `company_id` AND `id` columns, same as
            // `employees`. The previous unqualified `whereIn('company_id',
            // ...)` and `get(['id', ... 'company_id'])` became genuinely
            // ambiguous column references the moment that join was added
            // to the query -- MySQL throws "Column 'company_id'/'id' in
            // {where clause|field list} is ambiguous", a real
            // QueryException surfacing as a 500. This exact hazard is
            // already documented and fixed elsewhere in this codebase --
            // see ProjectController::show()'s "$availableEmployees" query
            // and its own comment ("unqualified references here would be
            // ambiguous once combined with that join") -- ManHourController
            // was simply the one call site that never got the same
            // treatment. Fully qualifying every reference here matches
            // that established, working pattern exactly.
            'employees' => Employee::query()
                ->whereIn('employees.company_id', $companyIds)
                ->active()
                ->orderedForDisplay()
                ->get(['employees.id', 'employees.full_name', 'employees.department_id', 'employees.company_id']),
            'projects' => Project::whereIn('company_id', $companyIds)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            // The range actually applied, so the page can say which period
            // its totals cover even when the defaults were used.
            'filters' => [
                ...$request->only('company_id', 'employee_id', 'department_id', 'project_id'),
                'from' => $from,
                'to' => $to,
            ],
            'can' => ['manage' => $request->user()->canManageManHour()],
            'summary' => $this->summarize($filtered),
        ]);
    }

    /**
     * v2.77.0 -- THE PERIOD'S TOTALS, COMPUTED IN THE DATABASE.
     *
     * Until v2.77.0 `total_hours` was summed from the thirty rows on the
     * CURRENT PAGE of the table, so any period with more than thirty
     * records reported a total that was simply wrong -- and a different
     * wrong number on every page. It is now one aggregate over the whole
     * filtered set.
     *
     * The model the figures follow, stated so it can be audited:
     *
     *   one row      = one person, one work date (unique per employee+date)
     *   row hours    = regular_hours + overtime_hours   (entered, never assumed)
     *   man-hours    = SUM(row hours) over the period
     *                = headcount × hours each person actually worked
     *   headcount    = distinct people with at least one row in the period
     *   work days    = distinct dates with at least one row
     *
     * Man-hours are NOT headcount × a standard day: nobody's hours are
     * inferred from being on the roster. See docs/MODULES.md.
     */
    private function summarize($filtered): array
    {
        $totals = (clone $filtered)->toBase()
            ->selectRaw('COALESCE(SUM(man_hour_logs.regular_hours), 0) as regular')
            ->selectRaw('COALESCE(SUM(man_hour_logs.overtime_hours), 0) as overtime')
            ->selectRaw('COUNT(*) as records')
            ->selectRaw('COUNT(DISTINCT man_hour_logs.employee_id) as headcount')
            ->selectRaw('COUNT(DISTINCT man_hour_logs.work_date) as work_days')
            ->first();

        $regular = round((float) $totals->regular, 2);
        $overtime = round((float) $totals->overtime, 2);

        // Where the hours went. "No project" is its own line rather than
        // being dropped, so the breakdown always adds up to the total.
        $byProject = (clone $filtered)->toBase()
            ->leftJoin('projects', 'projects.id', '=', 'man_hour_logs.project_id')
            ->groupBy('man_hour_logs.project_id', 'projects.name')
            ->selectRaw('man_hour_logs.project_id as project_id, projects.name as name')
            ->selectRaw('SUM(man_hour_logs.regular_hours + man_hour_logs.overtime_hours) as hours')
            ->selectRaw('COUNT(DISTINCT man_hour_logs.employee_id) as headcount')
            ->orderByDesc('hours')
            ->get()
            ->map(fn ($row) => [
                'project_id' => $row->project_id,
                'name' => $row->name,
                'hours' => round((float) $row->hours, 2),
                'headcount' => (int) $row->headcount,
            ])
            ->all();

        return [
            'total_hours' => round($regular + $overtime, 2),
            'regular_hours' => $regular,
            'overtime_hours' => $overtime,
            'record_count' => (int) $totals->records,
            'headcount' => (int) $totals->headcount,
            'work_days' => (int) $totals->work_days,
            'by_project' => $byProject,
        ];
    }

    public function store(StoreManHourLogRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail($data['employee_id']);

        /*
         * One row per person per day is the invariant the whole summary
         * relies on (a unique index enforces it). Saving the same person
         * and date again REPLACES that day's hours -- which is right, since
         * nobody works one day twice -- and the message now says so, rather
         * than reporting "saved" as though a second row had been added.
         */
        $record = ManHourLog::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'work_date' => $data['work_date']],
            [
                'company_id' => $employee->company_id,
                'project_id' => $data['project_id'] ?? null,
                'regular_hours' => $data['regular_hours'],
                'overtime_hours' => $data['overtime_hours'],
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]
        );

        return back()->with('success', $record->wasRecentlyCreated
            ? 'Man-hour record saved.'
            : 'Man-hour record updated: that employee already had hours on this date, and they have been replaced.');
    }

    public function destroy(ManHourLog $manHourLog): RedirectResponse
    {
        $this->authorize('delete', $manHourLog);

        $manHourLog->delete();

        return back()->with('success', 'Man-hour record removed.');
    }
}
