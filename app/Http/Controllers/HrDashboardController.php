<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\KpiRecord;
use App\Models\LeaveRequest;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR Department Dashboard (v1.10.0). Operational HR information only,
 * built entirely from real, already-existing data plus the new Leave
 * module. Deliberately does NOT include Attendance, Recruitment Status,
 * Training Due, Contract Expiry, or Employee Document Expiry widgets --
 * none of those have a backing data model in this app yet (no Attendance,
 * Recruitment, Training, or document-expiry tracking exists), and
 * fabricating numbers for them would violate "no invented features."
 * They'll appear here once their own modules are built, not before.
 *
 * v1.11.2 (Final Completion Pass, Part 19 -- security audit): every query
 * below had ZERO company scoping, unlike its 4 sibling department
 * dashboard controllers (Hse/ProjectManagement/Logistics/Procurement, all
 * already fixed in earlier passes this session) -- a genuine, confirmed
 * cross-tenant leakage bug, the exact same class already fixed elsewhere.
 * Fixed here using the same reusable
 * `DashboardStatsService::resolveCompanyIds()` helper, not a new copy of
 * the same logic. Also adds the Department Calendar widget
 * (`CalendarService::departmentEvents()`, 'hr').
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
            'recentLeaveRequests' => LeaveRequest::whereIn('company_id', $companyIds)
                ->with('employee:id,full_name')
                ->latest('created_at')
                ->limit(5)
                ->get(['id', 'leave_number', 'employee_id', 'leave_type', 'status', 'start_date', 'end_date', 'company_id']),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'hr'),
        ]);
    }
}
