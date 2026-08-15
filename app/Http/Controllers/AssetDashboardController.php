<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asset Management Department Dashboard (v1.11.3 -- Global Dashboard/
 * Overview UX Rework, Part 4). New page: `asset-management` previously had
 * no Overview route at all (confirmed via audit -- `workspaces.js` had no
 * `'Overview'` item for this workspace, only the injected global
 * Dashboard link). Every widget here reads from `Asset`/`MaintenanceRequest`/
 * `WorkOrder`, all real, already-existing models -- no fabricated metric
 * (e.g. no invented "utilization %" without a real source). Same
 * constructor-injection + `resolveCompanyIds()` pattern as every other
 * department dashboard controller (see LogisticsDashboardController).
 */
class AssetDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

    public function index(): Response
    {
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        return Inertia::render('Assets/Dashboard', [
            'totalAssetsCount' => Asset::whereIn('company_id', $companyIds)->count(),
            'activeAssetsCount' => Asset::whereIn('company_id', $companyIds)->where('status', 'active')->count(),
            'underMaintenanceCount' => Asset::whereIn('company_id', $companyIds)->where('status', 'under_maintenance')->count(),
            'retiredCount' => Asset::whereIn('company_id', $companyIds)->whereIn('status', ['retired', 'disposed'])->count(),
            'openMaintenanceRequestsCount' => MaintenanceRequest::whereIn('company_id', $companyIds)
                ->whereIn('status', [MaintenanceRequest::STATUS_REPORTED, MaintenanceRequest::STATUS_APPROVED])
                ->count(),
            'openWorkOrdersCount' => WorkOrder::whereIn('company_id', $companyIds)
                ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_IN_PROGRESS])
                ->count(),
            'assetsByCategory' => Asset::whereIn('company_id', $companyIds)
                ->selectRaw('category, count(*) as total')
                ->groupBy('category')
                ->pluck('total', 'category'),
            'recentAssets' => Asset::whereIn('company_id', $companyIds)
                ->latest('id')
                ->limit(6)
                ->get(['id', 'asset_code', 'name', 'category', 'status', 'location']),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'asset-management'),
        ]);
    }
}
