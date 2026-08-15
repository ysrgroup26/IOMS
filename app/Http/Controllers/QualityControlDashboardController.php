<?php

namespace App\Http\Controllers;

use App\Models\InspectionRequest;
use App\Models\Ncr;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quality Control Department Dashboard (v1.11.3 -- Global Dashboard/
 * Overview UX Rework, Part 4). New page: `quality-control` previously had
 * no Overview route (confirmed via audit). Every widget reads from
 * `InspectionRequest`/`Ncr`, both real, already-existing models (Milestone
 * 4, Acceleration Part 3 -- QC Foundation).
 */
class QualityControlDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

    public function index(): Response
    {
        $monthStart = Carbon::now()->startOfMonth();
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        return Inertia::render('QualityControl/Dashboard', [
            'openInspectionsCount' => InspectionRequest::whereIn('company_id', $companyIds)
                ->where('status', InspectionRequest::STATUS_REQUESTED)
                ->count(),
            'completedThisMonthCount' => InspectionRequest::whereIn('company_id', $companyIds)
                ->where('status', InspectionRequest::STATUS_COMPLETED)
                ->where('inspection_date', '>=', $monthStart)
                ->count(),
            'failedInspectionsCount' => InspectionRequest::whereIn('company_id', $companyIds)
                ->where('result', InspectionRequest::RESULT_FAILED)
                ->count(),
            'openNcrCount' => Ncr::whereIn('company_id', $companyIds)
                ->whereIn('status', [Ncr::STATUS_OPEN, Ncr::STATUS_IN_PROGRESS])
                ->count(),
            'criticalNcrCount' => Ncr::whereIn('company_id', $companyIds)
                ->whereIn('status', [Ncr::STATUS_OPEN, Ncr::STATUS_IN_PROGRESS])
                ->where('severity', 'critical')
                ->count(),
            'recentInspections' => InspectionRequest::whereIn('company_id', $companyIds)
                ->latest('inspection_date')
                ->limit(5)
                ->get(['id', 'inspection_number', 'inspection_date', 'status', 'result']),
            'recentNcrs' => Ncr::whereIn('company_id', $companyIds)
                ->latest('raised_date')
                ->limit(5)
                ->get(['id', 'ncr_number', 'severity', 'status', 'raised_date']),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'quality-control'),
        ]);
    }
}
