<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeCompetency;
use App\Models\EmployeeShiftAssignment;
use App\Models\KpiRecord;
use App\Models\LeaveRequest;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR Department Dashboard (v1.10.0, redesigned v1.11.5 -- Dashboard UX
 * Completion, Phase 3). Still built entirely from real, already-existing
 * data. Deliberately does NOT include Attendance or Recruitment widgets --
 * no backing data model exists for either.
 *
 * This pass adds two genuinely real data sources that were previously
 * marked "no backing model" in error -- both already existed elsewhere in
 * the app, just not queried from here:
 * - Contract expiry: `Employee.contract_end_date` (already a real column,
 *   already used by the Employee module's own forms).
 * - Certification expiry: `EmployeeCompetency.expiry_date` (already the
 *   backing data for the existing `competency.expiring-soon` page --
 *   reused via the same 30-day window that page and EmployeePpe's own
 *   expiry pattern both already use, not a new convention).
 * - On-shift-today: `EmployeeShiftAssignment`, the same query the Main
 *   Dashboard's own Man-Power widget already uses.
 *
 * v1.11.2 tenant-isolation fix (unchanged from before): every query uses
 * `DashboardStatsService::resolveCompanyIds()`.
 */
class HrDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

    public function index(): Response
    {
        $today = Carbon::today();
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        return Inertia::render('Hr/Dashboard', [
            'totalEmployees' => Employee::whereIn('company_id', $companyIds)->count(),
            'activeEmployees' => Employee::whereIn('company_id', $companyIds)->active()->count(),
            'onShiftToday' => EmployeeShiftAssignment::whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
                ->where('status', 'active')
                ->where('effective_date', '<=', $today)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
                ->count(),
            'employeesOnLeaveToday' => LeaveRequest::whereIn('company_id', $companyIds)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->count(),
            'pendingLeaveRequests' => LeaveRequest::whereIn('company_id', $companyIds)->where('status', LeaveRequest::STATUS_SUBMITTED)->count(),
            'employeesNeedCompletionCount' => Employee::whereIn('company_id', $companyIds)->whereNull('department_id')->count(),
            'kpiThisMonth' => (int) KpiRecord::whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
                ->forPeriod((int) $today->format('Y'), (int) $today->format('n'))
                ->sum('quantity'),
            'contractExpiringCount' => Employee::whereIn('company_id', $companyIds)
                ->whereNotNull('contract_end_date')
                ->whereBetween('contract_end_date', [$today, $today->copy()->addDays(30)])
                ->count(),
            'certificationExpiringCount' => EmployeeCompetency::whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
                ->effectiveStatus('expiring_soon')
                ->count(),
            // "Attention Required" -- real rows merging both expiry
            // sources, oldest-expiring first, so HR sees exactly who
            // needs action instead of only a count.
            'attentionRequired' => collect()
                ->merge(Employee::whereIn('company_id', $companyIds)
                    ->whereNotNull('contract_end_date')
                    ->whereBetween('contract_end_date', [$today, $today->copy()->addDays(30)])
                    ->limit(5)->get(['id', 'full_name', 'contract_end_date'])
                    ->map(fn (Employee $e) => ['type' => 'Contract Expiring', 'label' => $e->full_name, 'date' => $e->contract_end_date, 'href' => route('employees.show', $e->id)]))
                ->merge(EmployeeCompetency::whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
                    ->effectiveStatus('expiring_soon')
                    ->with('employee:id,full_name', 'competencyType:id,name')
                    ->limit(5)->get()
                    ->map(fn (EmployeeCompetency $c) => ['type' => 'Certification Expiring', 'label' => $c->employee?->full_name.' -- '.$c->competencyType?->name, 'date' => $c->expiry_date, 'href' => route('competency.master')]))
                ->sortBy('date')
                ->values()
                ->take(8),
            'recentLeaveRequests' => LeaveRequest::whereIn('company_id', $companyIds)
                ->with('employee:id,full_name')
                ->latest('created_at')
                ->limit(5)
                ->get(['id', 'leave_number', 'employee_id', 'leave_type', 'status', 'start_date', 'end_date', 'company_id']),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'hr'),
        ]);
    }
}
