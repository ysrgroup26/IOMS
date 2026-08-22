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
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $companyIds = $this->dashboardStats->resolveCompanyIds($companyId);
        $from = $request->input('from') ?: Carbon::now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: Carbon::now()->toDateString();

        $logs = ManHourLog::whereIn('company_id', $companyIds)
            ->whereBetween('work_date', [$from, $to])
            ->when($request->input('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->input('department_id'), fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $v)))
            ->with('employee:id,full_name,department_id,company_id', 'employee.department:id,name', 'project:id,name')
            ->orderByDesc('work_date')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('ManHour/Index', [
            'logs' => $logs,
            'employees' => Employee::whereIn('company_id', $companyIds)->active()->orderedForDisplay()->get(['id', 'full_name', 'department_id', 'company_id']),
            'projects' => Project::whereIn('company_id', $companyIds)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('company_id', 'employee_id', 'department_id', 'from', 'to'),
            'can' => ['manage' => $request->user()->canManageManHour()],
            'summary' => [
                'total_hours' => (float) $logs->getCollection()->sum(fn (ManHourLog $l) => $l->total_hours),
                'record_count' => $logs->total(),
            ],
        ]);
    }

    public function store(StoreManHourLogRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail($data['employee_id']);

        ManHourLog::updateOrCreate(
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

        return back()->with('success', 'Man-hour record saved.');
    }

    public function destroy(ManHourLog $manHourLog): RedirectResponse
    {
        $this->authorize('delete', $manHourLog);

        $manHourLog->delete();

        return back()->with('success', 'Man-hour record removed.');
    }
}
