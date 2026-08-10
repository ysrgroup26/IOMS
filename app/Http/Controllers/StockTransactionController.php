<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Acceleration Part 1B. Goods Issue, Stock Transfer, Stock
 * Adjustment, Stock Opname -- four transaction types, one controller
 * (they share the same tenant-scoping/validation shape and all delegate
 * the actual balance change to the SAME StockService::recordMovement(),
 * never a duplicated read-then-write per type).
 */
class StockTransactionController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('Warehouses/Transaction', [
            'warehouses' => Warehouse::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'code']),
            'items' => Item::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'item_code', 'unit']),
            'materialRequests' => MaterialRequest::whereIn('company_id', $tenantCompanyIds)
                ->whereIn('status', ['approved', 'processing'])
                ->orderByDesc('request_date')
                ->get(['id', 'request_number', 'company_id']),
        ]);
    }

    /** Goods Issue: Material Request -> stock check -> issue. */
    public function issue(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantWarehouseIds = Warehouse::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantItemIds = Item::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantMrIds = MaterialRequest::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'warehouse_id' => ['required', Rule::in($tenantWarehouseIds)],
            'item_id' => ['required', Rule::in($tenantItemIds)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'material_request_id' => ['nullable', Rule::in($tenantMrIds)],
            'movement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $stock = Stock::where('item_id', $data['item_id'])->where('warehouse_id', $data['warehouse_id'])->first();
        if (! $stock || $stock->available_quantity < $data['quantity']) {
            return back()->with('error', 'Insufficient available stock for this issue.')->withInput();
        }

        $warehouse = Warehouse::find($data['warehouse_id']);
        $movement = $this->stockService->recordMovement(
            companyId: $warehouse->company_id,
            itemId: $data['item_id'],
            warehouseId: $data['warehouse_id'],
            type: StockMovement::TYPE_ISSUE,
            quantity: $data['quantity'],
            performedBy: $request->user(),
            referenceType: $data['material_request_id'] ? MaterialRequest::class : null,
            referenceId: $data['material_request_id'] ?? null,
            notes: $data['notes'] ?? null,
            movementDate: $data['movement_date'],
        );

        ActivityLog::record('created', "Goods Issue {$movement->movement_number} recorded.", $movement);

        return back()->with('success', 'Goods issued.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantWarehouseIds = Warehouse::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantItemIds = Item::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'item_id' => ['required', Rule::in($tenantItemIds)],
            'from_warehouse_id' => ['required', Rule::in($tenantWarehouseIds), 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', Rule::in($tenantWarehouseIds)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'movement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $stock = Stock::where('item_id', $data['item_id'])->where('warehouse_id', $data['from_warehouse_id'])->first();
        if (! $stock || $stock->available_quantity < $data['quantity']) {
            return back()->with('error', 'Insufficient available stock in the source warehouse.')->withInput();
        }

        $company = Company::find(Warehouse::find($data['from_warehouse_id'])->company_id);

        [$out, $in] = $this->stockService->transfer(
            companyId: $company->id,
            itemId: $data['item_id'],
            fromWarehouseId: $data['from_warehouse_id'],
            toWarehouseId: $data['to_warehouse_id'],
            quantity: $data['quantity'],
            performedBy: $request->user(),
            notes: $data['notes'] ?? null,
            movementDate: $data['movement_date'],
        );

        ActivityLog::record('created', "Stock transfer {$out->movement_number} -> {$in->movement_number} recorded.", $out);

        return back()->with('success', 'Stock transferred.');
    }

    public function adjust(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantWarehouseIds = Warehouse::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantItemIds = Item::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'warehouse_id' => ['required', Rule::in($tenantWarehouseIds)],
            'item_id' => ['required', Rule::in($tenantItemIds)],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'movement_date' => ['required', 'date'],
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $warehouse = Warehouse::find($data['warehouse_id']);
        $movement = $this->stockService->recordMovement(
            companyId: $warehouse->company_id,
            itemId: $data['item_id'],
            warehouseId: $data['warehouse_id'],
            type: $data['direction'] === 'in' ? StockMovement::TYPE_ADJUSTMENT_IN : StockMovement::TYPE_ADJUSTMENT_OUT,
            quantity: $data['quantity'],
            performedBy: $request->user(),
            notes: $data['notes'],
            movementDate: $data['movement_date'],
        );

        ActivityLog::record('created', "Stock adjustment {$movement->movement_number} recorded.", $movement);

        return back()->with('success', 'Stock adjusted.');
    }

    /** Stock Opname: physical count reconciliation -- counted qty vs system qty, records the VARIANCE as an opname movement. */
    public function opname(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantWarehouseIds = Warehouse::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantItemIds = Item::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'warehouse_id' => ['required', Rule::in($tenantWarehouseIds)],
            'item_id' => ['required', Rule::in($tenantItemIds)],
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'movement_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $stock = Stock::where('item_id', $data['item_id'])->where('warehouse_id', $data['warehouse_id'])->first();
        $systemQty = (float) ($stock->quantity ?? 0);
        $variance = $data['counted_quantity'] - $systemQty;

        if (abs($variance) < 0.001) {
            return back()->with('success', 'No variance -- system quantity already matches the physical count.');
        }

        // A physical count variance can go either direction -- recorded as
        // a real ADJUSTMENT_IN/ADJUSTMENT_OUT (never a same-typed
        // "opname" row that would need its direction inferred some other
        // way), tagged in its own notes so it's still identifiable as a
        // Stock Opname in the movement history. See StockMovement's own
        // doc comment on why TYPE_OPNAME itself is never used here.
        $warehouse = Warehouse::find($data['warehouse_id']);
        $movement = $this->stockService->recordMovement(
            companyId: $warehouse->company_id,
            itemId: $data['item_id'],
            warehouseId: $data['warehouse_id'],
            type: $variance > 0 ? StockMovement::TYPE_ADJUSTMENT_IN : StockMovement::TYPE_ADJUSTMENT_OUT,
            quantity: abs($variance),
            performedBy: $request->user(),
            notes: '[Stock Opname] '.($data['notes'] ?? '')." (system: {$systemQty}, counted: {$data['counted_quantity']}, variance: {$variance})",
            movementDate: $data['movement_date'],
        );

        ActivityLog::record('created', "Stock opname {$movement->movement_number} recorded (variance {$variance}).", $movement);

        return back()->with('success', 'Stock opname recorded.');
    }
}
