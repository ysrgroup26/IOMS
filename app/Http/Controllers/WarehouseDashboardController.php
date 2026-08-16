<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Warehouse Department Dashboard (v1.11.3.2 -- Priority Pass Part 9).
 * `warehouses.master` (WarehouseController::master()) is the Warehouse
 * REGISTER/config page (which warehouses + storage locations exist, a
 * master-data setup screen, mirrors Hse/Master.jsx's own role for HSE) --
 * it has never been an Overview/dashboard. Warehouse was confirmed to
 * have real, substantial backend functionality already (Stock/
 * StockMovement/GoodsReceipt/Item, all already exercised by
 * LogisticsDashboardController's own low-stock/recent-movement widgets)
 * but no dedicated Overview of its own -- this is that Overview, reusing
 * the exact same models, not duplicating any stock-management logic.
 *
 * "Pending warehouse transactions" was NOT built as a KPI: neither
 * StockMovement nor GoodsReceipt has any pending/awaiting-approval status
 * concept in this codebase (both are immediate, already-executed records)
 * -- inventing one would be fabricated data. Recent Receiving/Issuing
 * activity lists are shown instead, which are real.
 *
 * v1.11.5 (Dashboard UX Completion, Phase 6) adds `inventoryHealth`: a
 * compact table dataset (Item/Category/Location/Current Stock/
 * Min-Reorder/Status), sorted by quantity ascending so the most at-risk
 * stock rows surface first. Status is derived purely from the real
 * `quantity`/`min_stock` columns already used by `lowStockCount`/
 * `outOfStockCount` above -- same thresholds, just a 4-way label instead
 * of a boolean.
 */
class WarehouseDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

    public function index(): Response
    {
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);
        $monthStart = Carbon::now()->startOfMonth();

        return Inertia::render('Warehouses/Dashboard', [
            'totalItemsCount' => Item::whereIn('company_id', $companyIds)->active()->count(),
            'totalWarehousesCount' => Warehouse::whereIn('company_id', $companyIds)->count(),
            'lowStockCount' => Stock::whereIn('company_id', $companyIds)
                ->where('quantity', '>', 0)
                ->whereRaw('stocks.quantity <= (select items.min_stock from items where items.id = stocks.item_id)')
                ->count(),
            'outOfStockCount' => Stock::whereIn('company_id', $companyIds)->where('quantity', '<=', 0)->count(),
            'goodsReceiptsThisMonth' => GoodsReceipt::where('received_date', '>=', $monthStart)
                ->where(fn ($q) => $q
                    ->whereHas('materialRequest', fn ($mr) => $mr->whereIn('company_id', $companyIds))
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->whereIn('company_id', $companyIds))
                    ->orWhereHas('warehouse', fn ($w) => $w->whereIn('company_id', $companyIds)))
                ->count(),
            'movementsThisMonth' => StockMovement::whereIn('company_id', $companyIds)
                ->where('movement_date', '>=', $monthStart)
                ->count(),
            'recentReceiving' => GoodsReceipt::with('materialRequest:id,request_number', 'warehouse:id,name')
                ->where(fn ($q) => $q
                    ->whereHas('materialRequest', fn ($mr) => $mr->whereIn('company_id', $companyIds))
                    ->orWhereHas('purchaseOrder', fn ($po) => $po->whereIn('company_id', $companyIds))
                    ->orWhereHas('warehouse', fn ($w) => $w->whereIn('company_id', $companyIds)))
                ->latest('received_date')
                ->limit(6)
                ->get(['id', 'receipt_number', 'received_date', 'material_request_id', 'warehouse_id']),
            'recentIssuing' => StockMovement::whereIn('company_id', $companyIds)
                ->whereIn('type', [StockMovement::TYPE_ISSUE, StockMovement::TYPE_TRANSFER_OUT])
                ->with('item:id,name,item_code', 'warehouse:id,name')
                ->latest('movement_date')
                ->latest('id')
                ->limit(6)
                ->get(['id', 'movement_number', 'item_id', 'warehouse_id', 'type', 'quantity', 'movement_date']),
            'lowStockItems' => Stock::whereIn('company_id', $companyIds)
                ->where('quantity', '>', 0)
                ->whereRaw('stocks.quantity <= (select items.min_stock from items where items.id = stocks.item_id)')
                ->with('item:id,name,item_code,unit,min_stock', 'warehouse:id,name')
                ->limit(6)
                ->get(['id', 'item_id', 'warehouse_id', 'quantity']),
            // Inventory Health table -- Phase 6. Item/Category/Location/
            // Current Stock/Min-Reorder/Status, one row per stock record.
            // Status is derived client-side-simple: out (<=0), critical
            // (<=50% of min_stock), low (<=min_stock), else healthy --
            // computed here from real columns only, not a fabricated field.
            'inventoryHealth' => Stock::whereIn('company_id', $companyIds)
                ->with('item:id,name,item_code,category,unit,min_stock', 'warehouse:id,name')
                ->orderBy('quantity')
                ->limit(15)
                ->get(['id', 'item_id', 'warehouse_id', 'quantity'])
                ->map(fn (Stock $s) => [
                    'id' => $s->id,
                    'item_code' => $s->item?->item_code,
                    'item_name' => $s->item?->name,
                    'category' => $s->item?->category,
                    'location' => $s->warehouse?->name,
                    'quantity' => $s->quantity,
                    'min_stock' => $s->item?->min_stock,
                    'unit' => $s->item?->unit,
                    'status' => $s->quantity <= 0
                        ? 'out_of_stock'
                        : ($s->item?->min_stock > 0 && $s->quantity <= $s->item->min_stock * 0.5
                            ? 'critical'
                            : ($s->item?->min_stock > 0 && $s->quantity <= $s->item->min_stock ? 'low' : 'healthy')),
                ]),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'logistics'),
        ]);
    }
}
