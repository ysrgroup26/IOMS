<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Item;
use App\Models\MaintenanceRequest;
use App\Models\Warehouse;
use App\Models\WorkOrder;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Acceleration Part 2 (Maintenance CMMS Foundation).
 * Request -> Approved -> Work Order -> Execution -> Completed ->
 * Maintenance History (Asset::transactions() + this WO's own row is the
 * history -- no separate "history" table).
 */
class WorkOrderController extends Controller
{
    public function __construct(private readonly StockService $stockService) {}

    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $workOrders = WorkOrder::whereIn('company_id', $tenantCompanyIds)
            ->with('asset:id,name,asset_code', 'technician:id,full_name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('wo_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('planned_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('WorkOrders/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageAssets()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $preselectedMr = null;
        if ($request->filled('mr')) {
            $mr = MaintenanceRequest::whereIn('company_id', $tenantCompanyIds)->where('status', MaintenanceRequest::STATUS_APPROVED)->find($request->integer('mr'));
            if ($mr) {
                $preselectedMr = ['id' => $mr->id, 'request_number' => $mr->request_number, 'asset_id' => $mr->asset_id, 'problem' => $mr->problem];
            }
        }

        return Inertia::render('WorkOrders/Form', [
            'assets' => Asset::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'asset_code', 'company_id']),
            'employees' => Employee::whereIn('company_id', $tenantCompanyIds)->active()->orderBy('full_name')->get(['id', 'full_name', 'company_id']),
            'woNumber' => WorkOrder::generateNumber(),
            'types' => WorkOrder::TYPES,
            'preselectedMr' => $preselectedMr,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantAssetIds = Asset::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantEmployeeIds = Employee::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantMrIds = MaintenanceRequest::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'asset_id' => ['required', Rule::in($tenantAssetIds)],
            'maintenance_request_id' => ['nullable', Rule::in($tenantMrIds)],
            'maintenance_type' => ['required', Rule::in(WorkOrder::TYPES)],
            'technician_id' => ['nullable', Rule::in($tenantEmployeeIds)],
            'planned_date' => ['required', 'date'],
            'work_description' => ['nullable', 'string', 'max:2000'],
        ]);

        $asset = Asset::find($data['asset_id']);

        $wo = WorkOrder::create([
            ...$data,
            'wo_number' => WorkOrder::generateNumber(),
            'company_id' => $asset->company_id,
            'status' => WorkOrder::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        if (! empty($data['maintenance_request_id'])) {
            $mr = MaintenanceRequest::find($data['maintenance_request_id']);
            if ($mr && $mr->status === MaintenanceRequest::STATUS_APPROVED) {
                $mr->transitionTo(MaintenanceRequest::STATUS_CONVERTED_TO_WO, $request->user());
            }
        }

        ActivityLog::record('created', "Created Work Order {$wo->wo_number} for \"{$asset->name}\".", $wo);

        return redirect()->route('work-orders.show', $wo)->with('flash', ['success' => 'Work Order created.']);
    }

    public function show(WorkOrder $workOrder, Request $request): Response
    {
        $this->assertInCurrentTenant($workOrder);
        $workOrder->load('asset:id,name,asset_code', 'maintenanceRequest:id,request_number', 'technician:id,full_name', 'creator:id,name', 'spareParts.item:id,name,item_code,unit', 'spareParts.warehouse:id,name');

        $activities = ActivityLog::where('subject_type', WorkOrder::class)
            ->where('subject_id', $workOrder->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('WorkOrders/Show', [
            'workOrder' => $workOrder,
            'activities' => $activities,
            'canManage' => $request->user()->canManageAssets(),
            'warehouses' => Warehouse::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'code']),
            'items' => Item::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'item_code', 'unit']),
        ]);
    }

    public function transition(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $this->assertInCurrentTenant($workOrder);

        $data = $request->validate([
            'status' => ['required', Rule::in([WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_IN_PROGRESS, WorkOrder::STATUS_COMPLETED, WorkOrder::STATUS_CANCELLED])],
            'completion_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            if ($data['status'] === WorkOrder::STATUS_COMPLETED) {
                $workOrder->actual_date = now()->toDateString();
                $workOrder->completion_notes = $data['completion_notes'] ?? null;
                $workOrder->save();
            }
            $workOrder->transitionTo($data['status'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Work Order '.$data['status'].'.']);
    }

    /** Spare part usage -- posts a real StockMovement via the SAME StockService the Warehouse module itself uses. */
    public function addSparePart(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $this->assertInCurrentTenant($workOrder);

        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantItemIds = Item::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantWarehouseIds = Warehouse::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'item_id' => ['required', Rule::in($tenantItemIds)],
            'warehouse_id' => ['required', Rule::in($tenantWarehouseIds)],
            'quantity_used' => ['required', 'numeric', 'min:0.01'],
        ]);

        $stock = \App\Models\Stock::where('item_id', $data['item_id'])->where('warehouse_id', $data['warehouse_id'])->first();
        if (! $stock || $stock->available_quantity < $data['quantity_used']) {
            return back()->with('error', 'Insufficient available stock for this spare part.');
        }

        $movement = $this->stockService->recordMovement(
            companyId: $workOrder->company_id,
            itemId: $data['item_id'],
            warehouseId: $data['warehouse_id'],
            type: \App\Models\StockMovement::TYPE_ISSUE,
            quantity: $data['quantity_used'],
            performedBy: $request->user(),
            referenceType: WorkOrder::class,
            referenceId: $workOrder->id,
        );

        $workOrder->spareParts()->create([...$data, 'stock_movement_id' => $movement->id]);

        ActivityLog::record('created', "Spare part used on Work Order {$workOrder->wo_number}.", $workOrder);

        return back()->with('success', 'Spare part usage recorded.');
    }

    private function assertInCurrentTenant(WorkOrder $wo): void
    {
        abort_unless(Company::query()->pluck('id')->contains($wo->company_id), 404);
    }
}
