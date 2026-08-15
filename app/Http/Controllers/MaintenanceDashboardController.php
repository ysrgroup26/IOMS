<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Maintenance Department Dashboard (v1.11.3 -- Global Dashboard/Overview
 * UX Rework, Part 4). New page: `maintenance` previously had no Overview
 * route (confirmed via audit). Every widget reads from `WorkOrder`/
 * `MaintenanceRequest`, both real, already-existing models. Its
 * Department Calendar is genuine reuse, not new plumbing --
 * `CalendarService::provideWorkOrders()` already stamps `department_key
 * = 'maintenance'` on every WorkOrder virtual event, so
 * `departmentEvents($companyIds, 'maintenance')` surfaces real planned-
 * date events with zero new code in the Calendar Engine.
 */
class MaintenanceDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

    public function index(): Response
    {
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        return Inertia::render('Maintenance/Dashboard', [
            'openWorkOrdersCount' => WorkOrder::whereIn('company_id', $companyIds)
                ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_IN_PROGRESS])
                ->count(),
            'overdueWorkOrdersCount' => WorkOrder::whereIn('company_id', $companyIds)
                ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_IN_PROGRESS])
                ->where('planned_date', '<', $today)
                ->count(),
            'completedThisMonthCount' => WorkOrder::whereIn('company_id', $companyIds)
                ->where('status', WorkOrder::STATUS_COMPLETED)
                ->where('actual_date', '>=', $monthStart)
                ->count(),
            'pendingRequestsCount' => MaintenanceRequest::whereIn('company_id', $companyIds)
                ->where('status', MaintenanceRequest::STATUS_REPORTED)
                ->count(),
            'urgentRequestsCount' => MaintenanceRequest::whereIn('company_id', $companyIds)
                ->whereIn('status', [MaintenanceRequest::STATUS_REPORTED, MaintenanceRequest::STATUS_APPROVED])
                ->where('priority', 'urgent')
                ->count(),
            'recentWorkOrders' => WorkOrder::whereIn('company_id', $companyIds)
                ->with('asset:id,name,asset_code')
                ->latest('planned_date')
                ->limit(6)
                ->get(['id', 'wo_number', 'asset_id', 'maintenance_type', 'planned_date', 'status']),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'maintenance'),
        ]);
    }
}
