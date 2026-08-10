<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseOrderRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream C4 (Purchase Order). Same segregation-of-duties
 * split as PurchaseRequisitionController: create/submit gated to
 * canManageProcurement(), approve/reject/cancel gated to
 * config('workflow.approvers'/'overriders'). Issuing (approved -> issued)
 * is back to canManageProcurement() -- Procurement, not the approver,
 * physically sends the PO to the vendor.
 */
class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $orders = PurchaseOrder::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('vendor:id,name,vendor_code', 'project:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('po_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('vendor_id'), fn ($q, $v) => $q->where('vendor_id', $v))
            ->latest('po_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('PurchaseOrders/Index', [
            'orders' => $orders,
            'filters' => $request->only('search', 'status', 'vendor_id'),
            'can' => ['manage' => $request->user()->canManageProcurement()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $prefill = null;
        if ($request->filled('rfq')) {
            $rfq = Rfq::whereIn('company_id', $tenantCompanyIds)->with('selectedVendor', 'purchaseRequisition')->find($request->integer('rfq'));
            if ($rfq && $rfq->selected_vendor_id) {
                $quotation = $rfq->quotations()->where('vendor_id', $rfq->selected_vendor_id)->first();
                $prefill = [
                    'rfq_id' => $rfq->id,
                    'purchase_requisition_id' => $rfq->purchase_requisition_id,
                    'vendor_id' => $rfq->selected_vendor_id,
                    'vendor_quotation_id' => $quotation?->id,
                    'currency' => $quotation?->currency ?? 'IDR',
                    'payment_terms' => $quotation?->payment_terms,
                    'items' => collect($quotation?->items ?? [])->map(fn ($i) => [
                        'description' => $i['description'] ?? '', 'specification' => '', 'quantity' => $i['quantity'] ?? 1,
                        'unit' => $i['unit'] ?? 'pcs', 'unit_price' => $i['unit_price'] ?? 0,
                        'discount' => $i['discount'] ?? 0, 'tax' => $i['tax'] ?? 0,
                    ])->values(),
                    'shipping_amount' => $quotation?->shipping_cost ?? 0,
                    'other_charges' => $quotation?->other_charges ?? 0,
                ];
            }
        }

        return Inertia::render('PurchaseOrders/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'vendor_code', 'company_id']),
            'purchaseRequisitions' => PurchaseRequisition::whereIn('company_id', $tenantCompanyIds)->whereIn('status', ['approved', 'converted_to_rfq'])->get(['id', 'pr_number', 'company_id']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::whereIn('company_id', $tenantCompanyIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'poNumber' => PurchaseOrder::generateNumber(),
            'prefill' => $prefill,
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $po = DB::transaction(function () use ($data, $items, $request) {
            $subtotal = collect($items)->sum(fn ($i) => $i['quantity'] * $i['unit_price']);
            $discountTotal = collect($items)->sum(fn ($i) => $i['discount'] ?? 0);
            $taxTotal = collect($items)->sum(fn ($i) => $i['tax'] ?? 0);
            $shipping = $data['shipping_amount'] ?? 0;
            $other = $data['other_charges'] ?? 0;

            $po = PurchaseOrder::create([
                ...$data,
                'po_number' => PurchaseOrder::generateNumber(),
                'subtotal' => $subtotal,
                'discount_amount' => $discountTotal,
                'tax_amount' => $taxTotal,
                'shipping_amount' => $shipping,
                'other_charges' => $other,
                'grand_total' => $subtotal - $discountTotal + $taxTotal + $shipping + $other,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'requested_by' => $request->user()->id,
            ]);

            foreach ($items as $index => $item) {
                $lineTotal = $item['quantity'] * $item['unit_price'] - ($item['discount'] ?? 0) + ($item['tax'] ?? 0);
                $po->items()->create([...$item, 'line_total' => $lineTotal, 'sort_order' => $index]);
            }

            // PR lifecycle: creating a PO IS the "Converted to PO" event.
            if ($po->purchase_requisition_id) {
                $pr = $po->purchaseRequisition;
                if ($pr && $pr->status === PurchaseRequisition::STATUS_CONVERTED_TO_RFQ) {
                    $pr->transitionTo(PurchaseRequisition::STATUS_CONVERTED_TO_PO, $request->user());
                }
            }

            return $po;
        });

        ActivityLog::record('created', "Created Purchase Order {$po->po_number}.", $po);

        return redirect()->route('purchase-orders.show', $po)->with('flash', ['success' => 'Purchase Order created.']);
    }

    public function show(PurchaseOrder $purchaseOrder, Request $request): Response
    {
        $this->assertInCurrentTenant($purchaseOrder);
        $purchaseOrder->load(
            'company:id,name', 'vendor:id,name,vendor_code,qualification_status', 'purchaseRequisition:id,pr_number',
            'rfq:id,rfq_number', 'project:id,name', 'department:id,name', 'requester:id,name',
            'approver:id,name', 'issuer:id,name', 'items', 'goodsReceipts.items'
        );

        $activities = ActivityLog::where('subject_type', PurchaseOrder::class)
            ->where('subject_id', $purchaseOrder->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
            'activities' => $activities,
            'canManage' => $request->user()->canManageProcurement(),
            'canDecide' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.approvers', []), true),
            'canOverride' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.overriders', []), true),
        ]);
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($purchaseOrder);

        return $this->doTransition($purchaseOrder, PurchaseOrder::STATUS_SUBMITTED, $request);
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeWorkflowAction($request, 'approvers');
        $this->assertInCurrentTenant($purchaseOrder);

        $purchaseOrder->approved_by = $request->user()->id;
        $purchaseOrder->save();

        return $this->doTransition($purchaseOrder, PurchaseOrder::STATUS_APPROVED, $request);
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeWorkflowAction($request, 'approvers');
        $this->assertInCurrentTenant($purchaseOrder);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->doTransition($purchaseOrder, PurchaseOrder::STATUS_REJECTED, $request, $data['reason'] ?? null);
    }

    public function issue(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($purchaseOrder);

        $purchaseOrder->issued_by = $request->user()->id;
        $purchaseOrder->issued_at = now();
        $purchaseOrder->save();

        return $this->doTransition($purchaseOrder, PurchaseOrder::STATUS_ISSUED, $request);
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($purchaseOrder);

        $purchaseOrder->closed_at = now();
        $purchaseOrder->save();

        return $this->doTransition($purchaseOrder, PurchaseOrder::STATUS_CLOSED, $request);
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorizeWorkflowAction($request, 'overriders');
        $this->assertInCurrentTenant($purchaseOrder);

        return $this->doTransition($purchaseOrder, PurchaseOrder::STATUS_CANCELLED, $request);
    }

    private function doTransition(PurchaseOrder $po, string $status, Request $request, ?string $reason = null): RedirectResponse
    {
        try {
            $po->transitionTo($status, $request->user(), $reason);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Purchase Order '.str_replace('_', ' ', $status).'.']);
    }

    private function authorizeWorkflowAction(Request $request, string $configKey): void
    {
        $allowed = config("workflow.{$configKey}", []);
        abort_unless($request->user()->isSuperAdmin() || in_array($request->user()->role, $allowed, true), 403);
    }

    private function assertInCurrentTenant(PurchaseOrder $po): void
    {
        abort_unless(Company::query()->pluck('id')->contains($po->company_id), 404);
    }
}
