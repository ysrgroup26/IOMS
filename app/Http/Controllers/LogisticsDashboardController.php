<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\GoodsReceipt;
use App\Models\MaterialRequest;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Logistics Department Dashboard (v1.10.0). Deliberately does NOT
 * include Low Stock, Outgoing Goods, or a Stock Summary -- Inventory,
 * Goods Issue, and Stock Movement have no backing data model yet (they're
 * still disabled sidebar placeholders). Only Material Requests and the
 * new Goods Receipt module back real widgets here.
 */
class LogisticsDashboardController extends Controller
{
    public function index(): Response
    {
        $monthStart = Carbon::now()->startOfMonth();

        return Inertia::render('Logistics/Dashboard', [
            'pendingMaterialRequests' => MaterialRequest::where('status', MaterialRequest::STATUS_SUBMITTED)->count(),
            'waitingApprovals' => Approval::where('approvable_type', MaterialRequest::class)
                ->where('status', Approval::STATUS_PENDING)
                ->count(),
            'goodsReceiptsThisMonth' => GoodsReceipt::where('received_date', '>=', $monthStart)->count(),
            'materialRequestsByStatus' => MaterialRequest::selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'recentGoodsReceipts' => GoodsReceipt::with('materialRequest:id,request_number')
                ->withCount('items')
                ->latest('received_date')
                ->limit(5)
                ->get(['id', 'receipt_number', 'received_date', 'material_request_id']),
        ]);
    }
}
