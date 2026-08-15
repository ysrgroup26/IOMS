<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\MaterialRequest;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logistics Department Dashboard (v1.10.0). Milestone 4, Acceleration
 * Part 1B/7: Low Stock + Recent Movement now have a real backing data
 * model (Warehouse/Stock/StockMovement) and are included below --
 * previously this controller's own doc comment explicitly said they
 * didn't exist yet.
 *
 * Tenant-isolation fix (found while extending this controller for the new
 * Warehouse widgets, same discipline as HseDashboardController/
 * IncidentController/GoodsReceiptController earlier this milestone):
 * every existing query here (MaterialRequest/Approval/GoodsReceipt) had
 * ZERO company scoping. Fixed via DashboardStatsService::resolveCompanyIds(),
 * the same reusable helper, not a second copy of the same logic.
 */
class LogisticsDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

    public function index(): Response
    {
        $monthStart = Carbon::now()->startOfMonth();
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        return Inertia::render('Logistics/Dashboard', [
            'pendingMaterialRequests' => MaterialRequest::whereIn('company_id', $companyIds)->where('status', MaterialRequest::STATUS_SUBMITTED)->count(),
            'waitingApprovals' => Approval::where('approvable_type', MaterialRequest::class)
                ->where('status', Approval::STATUS_PENDING)
                ->whereHasMorph('approvable', [MaterialRequest::class], fn ($q) => $q->whereIn('company_id', $companyIds))
                ->count(),
            'goodsReceiptsThisMonth' => GoodsReceipt::where('received_date', '>=', $monthStart)
                ->where(fn ($q) => $q
                    ->whereHas('materialRequest', fn ($mr) => $mr->whereIn('company_id', $companyIds))
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->whereIn('company_id', $companyIds))
                    ->orWhereHas('warehouse', fn ($w) => $w->whereIn('company_id', $companyIds)))
                ->count(),
            'materialRequestsByStatus' => MaterialRequest::whereIn('company_id', $companyIds)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'recentGoodsReceipts' => GoodsReceipt::with('materialRequest:id,request_number')
                ->where(fn ($q) => $q
                    ->whereHas('materialRequest', fn ($mr) => $mr->whereIn('company_id', $companyIds))
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->whereIn('company_id', $companyIds))
                    ->orWhereHas('warehouse', fn ($w) => $w->whereIn('company_id', $companyIds)))
                ->withCount('items')
                ->latest('received_date')
                ->limit(5)
                ->get(['id', 'receipt_number', 'received_date', 'material_request_id']),
            'lowStockCount' => Stock::whereIn('company_id', $companyIds)
                ->whereRaw('stocks.quantity <= (select items.min_stock from items where items.id = stocks.item_id)')
                ->count(),
            'recentStockMovements' => StockMovement::whereIn('company_id', $companyIds)
                ->with('item:id,name,item_code', 'warehouse:id,name')
                ->latest('movement_date')
                ->latest('id')
                ->limit(5)
                ->get(['id', 'movement_number', 'item_id', 'warehouse_id', 'type', 'quantity', 'movement_date']),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'logistics'),
        ]);
    }
}
