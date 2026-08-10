<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\RfqVendor;
use App\Models\Vendor;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream C6 (Vendor Performance). Every metric below is
 * computed from real transactions this workstream actually created --
 * PurchaseOrder/GoodsReceipt/RfqVendor -- never a fabricated score. No
 * stored "performance" table; recalculated on every view so it can never
 * drift from the underlying data.
 */
class VendorPerformanceController extends Controller
{
    public function index(): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $vendors = Vendor::whereIn('company_id', $tenantCompanyIds)
            ->with(['purchaseOrders' => fn ($q) => $q->with('goodsReceipts')])
            ->get()
            ->map(function (Vendor $vendor) {
                $pos = $vendor->purchaseOrders;
                $completedPos = $pos->whereIn('status', [PurchaseOrder::STATUS_FULLY_DELIVERED, PurchaseOrder::STATUS_CLOSED])
                    ->filter(fn ($po) => $po->delivery_date);

                $onTimeCount = $completedPos->filter(function ($po) {
                    $lastReceipt = $po->goodsReceipts->max('received_date');

                    return $lastReceipt && $lastReceipt->lte($po->delivery_date);
                })->count();

                $invited = RfqVendor::where('vendor_id', $vendor->id)->count();
                $responded = RfqVendor::where('vendor_id', $vendor->id)->where('status', 'responded')->count();

                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'vendor_code' => $vendor->vendor_code,
                    'qualification_status' => $vendor->qualification_status,
                    'total_po_count' => $pos->count(),
                    'total_po_value' => (float) $pos->whereNotIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED])->sum('grand_total'),
                    'open_po_count' => $pos->whereIn('status', [PurchaseOrder::STATUS_ISSUED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED])->count(),
                    'completed_po_count' => $completedPos->count(),
                    'on_time_delivery_rate' => $completedPos->count() > 0 ? round($onTimeCount / $completedPos->count() * 100, 1) : null,
                    'rfq_invited_count' => $invited,
                    'rfq_response_rate' => $invited > 0 ? round($responded / $invited * 100, 1) : null,
                ];
            })
            ->sortByDesc('total_po_value')
            ->values();

        return Inertia::render('Procurement/VendorPerformance', [
            'vendors' => $vendors,
        ]);
    }
}
