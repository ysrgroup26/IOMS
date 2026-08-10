<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\Vendor;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream C6 (Procurement Dashboard). Every widget is a
 * REAL computation over the actual transactions built this workstream --
 * nothing here is a fabricated metric. Tenant-safe from the start (reuses
 * DashboardStatsService::resolveCompanyIds(), the same helper that fixed
 * the exact same leak class in HseDashboardController/IncidentController
 * earlier this milestone -- never reimplemented as a second copy).
 */
class ProcurementDashboardController extends Controller
{
    public function __construct(private readonly DashboardStatsService $dashboardStats) {}

    public function index(): Response
    {
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);
        $yearStart = Carbon::now()->startOfYear();

        $openPOs = PurchaseOrder::whereIn('company_id', $companyIds)
            ->whereIn('status', [PurchaseOrder::STATUS_ISSUED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED]);

        $monthlyTrend = PurchaseOrder::whereIn('company_id', $companyIds)
            ->where('po_date', '>=', $yearStart)
            ->whereNotIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED])
            ->selectRaw('MONTH(po_date) as month, SUM(grand_total) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $deptBreakdown = PurchaseOrder::whereIn('company_id', $companyIds)
            ->whereNotIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED])
            ->with('department:id,name')
            ->get()
            ->groupBy(fn ($po) => $po->department?->name ?? 'Unassigned')
            ->map(fn ($group) => (float) $group->sum('grand_total'));

        // Purchase Cycle Time: real average days between a PR's own
        // request_date and the PO that was actually issued from it,
        // ONLY for PRs that reached a PO (nothing fabricated for PRs
        // still in flight).
        $cycleDays = PurchaseOrder::whereIn('company_id', $companyIds)
            ->whereNotNull('purchase_requisition_id')
            ->whereNotNull('issued_at')
            ->with('purchaseRequisition:id,request_date')
            ->get()
            ->filter(fn ($po) => $po->purchaseRequisition)
            ->map(fn ($po) => $po->purchaseRequisition->request_date->diffInDays($po->issued_at))
            ->average();

        return Inertia::render('Procurement/Dashboard', [
            'pendingPRCount' => PurchaseRequisition::whereIn('company_id', $companyIds)
                ->whereIn('status', [PurchaseRequisition::STATUS_SUBMITTED, PurchaseRequisition::STATUS_UNDER_REVIEW])
                ->count(),
            'openRfqCount' => Rfq::whereIn('company_id', $companyIds)->where('status', Rfq::STATUS_ISSUED)->count(),
            'quotationsAwaitingEvaluationCount' => Rfq::whereIn('company_id', $companyIds)
                ->where('status', Rfq::STATUS_ISSUED)
                ->whereNull('selected_vendor_id')
                ->whereHas('quotations')
                ->count(),
            'pendingPOApprovalCount' => PurchaseOrder::whereIn('company_id', $companyIds)->where('status', PurchaseOrder::STATUS_SUBMITTED)->count(),
            'openPOCount' => (clone $openPOs)->count(),
            'overdueDeliveryCount' => (clone $openPOs)->get()->filter(fn ($po) => $po->is_overdue)->count(),
            'partiallyDeliveredCount' => PurchaseOrder::whereIn('company_id', $companyIds)->where('status', PurchaseOrder::STATUS_PARTIALLY_DELIVERED)->count(),
            'completedPOCount' => PurchaseOrder::whereIn('company_id', $companyIds)->where('status', PurchaseOrder::STATUS_CLOSED)->count(),
            'procurementValueYtd' => (float) PurchaseOrder::whereIn('company_id', $companyIds)
                ->where('po_date', '>=', $yearStart)
                ->whereNotIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_CANCELLED])
                ->sum('grand_total'),
            'monthlyTrend' => $monthlyTrend,
            'departmentBreakdown' => $deptBreakdown,
            'purchaseCycleDaysAvg' => $cycleDays !== null ? round($cycleDays, 1) : null,
            'activeVendorCount' => Vendor::whereIn('company_id', $companyIds)->active()->count(),
            'recentPOs' => PurchaseOrder::whereIn('company_id', $companyIds)
                ->with('vendor:id,name')
                ->latest('po_date')
                ->limit(6)
                ->get(['id', 'po_number', 'vendor_id', 'grand_total', 'status', 'po_date']),
        ]);
    }
}
