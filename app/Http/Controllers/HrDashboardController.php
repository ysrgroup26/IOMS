<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\KpiRecord;
use App\Models\LeaveRequest;
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
 */
class HrDashboardController extends Controller
{
    public function index(): Response
    {
        $today = Carbon::today();

        return Inertia::render('Hr/Dashboard', [
            'totalEmployees' => Employee::count(),
            'activeEmployees' => Employee::active()->count(),
            'employeesOnLeaveToday' => LeaveRequest::where('status', LeaveRequest::STATUS_APPROVED)
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->count(),
            'pendingLeaveRequests' => LeaveRequest::where('status', LeaveRequest::STATUS_SUBMITTED)->count(),
            'employeesNeedCompletionCount' => Employee::whereNull('department_id')->count(),
            'kpiThisMonth' => (int) KpiRecord::forPeriod((int) $today->format('Y'), (int) $today->format('n'))->sum('quantity'),
            'recentLeaveRequests' => LeaveRequest::with('employee:id,full_name')
                ->latest('created_at')
                ->limit(5)
                ->get(['id', 'leave_number', 'employee_id', 'leave_type', 'status', 'start_date', 'end_date']),
        ]);
    }
}
