<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\MaterialRequest;
use App\Models\Item;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream C5 (Goods Receipt / GRN integration). Existing
 * module, extended -- NOT a second GRN system. A receipt is EITHER
 * against a Material Request (unchanged pre-Procurement flow) OR a
 * Purchase Order (new, this workstream); never both.
 *
 * Tenant-isolation fix (found while extending this controller for PO
 * integration, same discipline as HseDashboardController/
 * IncidentController earlier this milestone): every query here --
 * index()/create()/store()/show() -- had ZERO company scoping. Fixed by
 * scoping through the parent MaterialRequest/PurchaseOrder/Project's own
 * company_id, and replacing the raw `exists:material_requests,id`/
 * `exists:projects,id` IDOR in store() with Rule::in() over tenant-scoped
 * id collections.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $goodsReceipts = GoodsReceipt::query()
            ->where(fn ($q) => $q
                ->whereHas('materialRequest', fn ($mr) => $mr->whereIn('company_id', $tenantCompanyIds))
                ->orWhereHas('purchaseOrder', fn ($po) => $po->whereIn('company_id', $tenantCompanyIds))
                ->orWhereHas('warehouse', fn ($w) => $w->whereIn('company_id', $tenantCompanyIds)))
            ->with('materialRequest:id,request_number', 'purchaseOrder:id,po_number', 'warehouse:id,name', 'project:id,name', 'receiver:id,name')
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $v) => $q->where('receipt_number', 'like', "%{$v}%"))
            ->latest('received_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('GoodsReceipts/Index', [
            'goodsReceipts' => $goodsReceipts,
            'filters' => $request->only('search'),
            'can' => ['manage' => $request->user()->canManageGoodsReceipts()],
        ]);
    }

    public function create(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $preselectedPo = null;
        if ($request->filled('po')) {
            $po = PurchaseOrder::whereIn('company_id', $tenantCompanyIds)
                ->whereIn('status', [PurchaseOrder::STATUS_ISSUED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED])
                ->with('items')
                ->find($request->integer('po'));
            if ($po) {
                $preselectedPo = [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'items' => $po->items->map(fn ($i) => [
                        'id' => $i->id, 'description' => $i->description, 'unit' => $i->unit,
                        'remaining_quantity' => $i->remaining_quantity,
                    ]),
                ];
            }
        }

        return Inertia::render('GoodsReceipts/Form', [
            // Only requests that have actually reached Approved/Processing/Completed
            // are eligible to receive against -- receiving against a Draft or
            // still-pending request would be recording goods that were never
            // authorized.
            'materialRequests' => MaterialRequest::whereIn('company_id', $tenantCompanyIds)
                ->whereIn('status', ['approved', 'processing', 'completed'])
                ->orderByDesc('request_date')
                ->get(['id', 'request_number', 'company_id']),
            'purchaseOrders' => PurchaseOrder::whereIn('company_id', $tenantCompanyIds)
                ->whereIn('status', [PurchaseOrder::STATUS_ISSUED, PurchaseOrder::STATUS_PARTIALLY_DELIVERED])
                ->with('items:id,purchase_order_id,description,unit,quantity')
                ->get(['id', 'po_number', 'company_id'])
                ->map(fn ($po) => [
                    'id' => $po->id, 'po_number' => $po->po_number,
                    'items' => $po->items->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'unit' => $i->unit, 'remaining_quantity' => $i->remaining_quantity]),
                ]),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'code', 'company_id']),
            'items' => Item::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'item_code', 'unit', 'company_id']),
            'receiptNumber' => GoodsReceipt::generateReceiptNumber(),
            'preselectedPo' => $preselectedPo,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageGoodsReceipts(), 403);

        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantMaterialRequestIds = MaterialRequest::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantPurchaseOrderIds = PurchaseOrder::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantWarehouseIds = Warehouse::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantItemIds = Item::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'received_date' => ['required', 'date'],
            'material_request_id' => ['nullable', Rule::in($tenantMaterialRequestIds)],
            'purchase_order_id' => ['nullable', Rule::in($tenantPurchaseOrderIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            // Milestone 4, Acceleration Part 1B -- optional Warehouse
            // integration. Nullable: an existing/simple receipt can still
            // skip warehouse posting entirely, same backward-compatible
            // shape as the PO link before it.
            'warehouse_id' => ['nullable', Rule::in($tenantWarehouseIds)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
            'items.*.item_id' => ['nullable', Rule::in($tenantItemIds)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity_received' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
        ]);

        // A receipt is against EITHER a Material Request OR a Purchase
        // Order, never both -- application-level rule (see this
        // controller's own doc comment), enforced here rather than a DB
        // constraint since material_request_id/purchase_order_id both
        // stayed plain nullable columns for backward compatibility.
        if (! empty($data['material_request_id']) && ! empty($data['purchase_order_id'])) {
            throw ValidationException::withMessages(['purchase_order_id' => 'A receipt can be linked to a Material Request or a Purchase Order, not both.']);
        }

        // If linked to a PO, every purchase_order_item_id must actually
        // belong to THAT po (not a raw exists: check that would let a
        // valid-but-foreign PO item id through).
        if (! empty($data['purchase_order_id'])) {
            $validPoItemIds = \App\Models\PurchaseOrderItem::where('purchase_order_id', $data['purchase_order_id'])->pluck('id');
            foreach ($data['items'] as $item) {
                if (! empty($item['purchase_order_item_id']) && ! $validPoItemIds->contains($item['purchase_order_item_id'])) {
                    throw ValidationException::withMessages(['items' => 'One or more items do not belong to the selected Purchase Order.']);
                }
            }
        }

        $goodsReceipt = DB::transaction(function () use ($data, $request) {
            $goodsReceipt = GoodsReceipt::create([
                'receipt_number' => GoodsReceipt::generateReceiptNumber(),
                'received_date' => $data['received_date'],
                'material_request_id' => $data['material_request_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'project_id' => $data['project_id'] ?? null,
                'received_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $index => $item) {
                $grItem = $goodsReceipt->items()->create([
                    'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
                    'item_id' => $item['item_id'] ?? null,
                    'description' => $item['description'],
                    'quantity_received' => $item['quantity_received'],
                    'unit' => $item['unit'],
                    'sort_order' => $index,
                ]);

                // Warehouse integration (Milestone 4, Acceleration Part
                // 1B) -- a receipt line only posts real stock when BOTH a
                // warehouse and a catalog Item were specified; a receipt
                // that skips either (the pre-Warehouse-module shape) never
                // touches Stock/StockMovement at all.
                if ($goodsReceipt->warehouse_id && $grItem->item_id) {
                    $this->stockService->recordMovement(
                        companyId: $goodsReceipt->warehouse->company_id,
                        itemId: $grItem->item_id,
                        warehouseId: $goodsReceipt->warehouse_id,
                        type: \App\Models\StockMovement::TYPE_RECEIPT,
                        quantity: (float) $grItem->quantity_received,
                        performedBy: $request->user(),
                        referenceType: GoodsReceipt::class,
                        referenceId: $goodsReceipt->id,
                        movementDate: $goodsReceipt->received_date->toDateString(),
                    );
                }
            }

            ActivityLog::record('created', "Recorded Goods Receipt {$goodsReceipt->receipt_number}.", $goodsReceipt);

            // Delivery tracking (Workstream C4/C5): recompute the PO's
            // real delivery status from actual quantities and advance its
            // HasWorkflow status automatically -- never a manual button,
            // see PurchaseOrder::$transitions' own comment.
            if ($goodsReceipt->purchase_order_id) {
                $this->recomputePurchaseOrderDeliveryStatus($goodsReceipt->purchaseOrder, $request->user());
            }

            return $goodsReceipt;
        });

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('flash', ['success' => 'Goods Receipt recorded.']);
    }

    public function show(GoodsReceipt $goodsReceipt, Request $request): Response
    {
        $this->assertInCurrentTenant($goodsReceipt);
        $goodsReceipt->load('materialRequest:id,request_number', 'purchaseOrder:id,po_number', 'warehouse:id,name,code', 'project:id,name', 'receiver:id,name', 'items.item:id,name,item_code');

        $activities = ActivityLog::where('subject_type', GoodsReceipt::class)
            ->where('subject_id', $goodsReceipt->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('GoodsReceipts/Show', [
            'goodsReceipt' => $goodsReceipt,
            'activities' => $activities,
        ]);
    }

    private function recomputePurchaseOrderDeliveryStatus(PurchaseOrder $po, $user): void
    {
        $po->load('items');
        $allFullyDelivered = $po->items->every(fn ($i) => $i->remaining_quantity <= 0);
        $anyDelivered = $po->items->contains(fn ($i) => $i->delivered_quantity > 0);

        $target = $allFullyDelivered ? PurchaseOrder::STATUS_FULLY_DELIVERED
            : ($anyDelivered ? PurchaseOrder::STATUS_PARTIALLY_DELIVERED : null);

        if ($target && $po->canTransitionTo($target)) {
            $po->transitionTo($target, $user);
        }
    }

    /**
     * Tenant ownership guard -- a GoodsReceipt has no direct company_id of
     * its own, so ownership is derived through whichever parent
     * (MaterialRequest, PurchaseOrder, or -- Acceleration Part 1B --
     * Warehouse) it's linked to.
     */
    private function assertInCurrentTenant(GoodsReceipt $goodsReceipt): void
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $ownerCompanyId = $goodsReceipt->purchase_order_id
            ? $goodsReceipt->purchaseOrder?->company_id
            : ($goodsReceipt->materialRequest?->company_id ?? $goodsReceipt->warehouse?->company_id);

        abort_unless($ownerCompanyId !== null && $tenantCompanyIds->contains($ownerCompanyId), 404);
    }
}
