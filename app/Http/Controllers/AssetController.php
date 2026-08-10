<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetTransaction;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Acceleration Part 1C (Asset Management). Full lifecycle:
 * Purchase -> Receive -> Register (this controller's store(), optionally
 * pre-filled from an issued PO via ?po=) -> Assign -> Operate -> Inspect
 * -> Maintain (Part 2) -> Retire. Every lifecycle step past registration
 * writes a real AssetTransaction row, never a silent field update.
 */
class AssetController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $assets = Asset::whereIn('company_id', $tenantCompanyIds)
            ->with('responsibleEmployee:id,full_name', 'vendor:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('asset_code', 'like', "%{$v}%")->orWhere('serial_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('category'), fn ($q, $v) => $q->where('category', $v))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Assets/Index', [
            'assets' => $assets,
            'filters' => $request->only('search', 'status', 'category'),
            'categories' => Asset::CATEGORIES,
            'can' => ['manage' => $request->user()->canManageAssets()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $prefill = null;
        if ($request->filled('po')) {
            $po = PurchaseOrder::whereIn('company_id', $tenantCompanyIds)->with('vendor')->find($request->integer('po'));
            if ($po) {
                $prefill = ['company_id' => $po->company_id, 'vendor_id' => $po->vendor_id, 'purchase_order_id' => $po->id, 'purchase_date' => $po->po_date?->toDateString()];
            }
        }

        return Inertia::render('Assets/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name']),
            'purchaseOrders' => PurchaseOrder::whereIn('company_id', $tenantCompanyIds)->get(['id', 'po_number', 'company_id']),
            'employees' => Employee::whereIn('company_id', $tenantCompanyIds)->active()->orderBy('full_name')->get(['id', 'full_name', 'company_id']),
            'categories' => Asset::CATEGORIES,
            'assetCode' => Asset::generateCode(),
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantVendorIds = Vendor::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantPoIds = PurchaseOrder::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantEmployeeIds = Employee::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date'],
            'vendor_id' => ['nullable', Rule::in($tenantVendorIds)],
            'purchase_order_id' => ['nullable', Rule::in($tenantPoIds)],
            'location' => ['nullable', 'string', 'max:255'],
            'responsible_employee_id' => ['nullable', Rule::in($tenantEmployeeIds)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('uploads/assets', 'public');
        }
        unset($data['attachment']);

        $asset = Asset::create([
            ...$data,
            'asset_code' => Asset::generateCode(),
            'status' => $data['responsible_employee_id'] ?? null ? Asset::STATUSES[1] : Asset::STATUSES[0],
        ]);

        ActivityLog::record('created', "Registered asset \"{$asset->name}\" ({$asset->asset_code}).", $asset);

        return redirect()->route('assets.show', $asset)->with('flash', ['success' => 'Asset registered.']);
    }

    public function show(Asset $asset, Request $request): Response
    {
        $this->assertInCurrentTenant($asset);
        $asset->load('vendor:id,name', 'purchaseOrder:id,po_number', 'responsibleEmployee:id,full_name', 'transactions.performer:id,name', 'transactions.fromEmployee:id,full_name', 'transactions.toEmployee:id,full_name');

        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('Assets/Show', [
            'asset' => $asset,
            'employees' => Employee::where('company_id', $asset->company_id)->active()->orderBy('full_name')->get(['id', 'full_name']),
            'canManage' => $request->user()->canManageAssets(),
        ]);
    }

    public function assign(Request $request, Asset $asset): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $this->assertInCurrentTenant($asset);

        $tenantEmployeeIds = Employee::where('company_id', $asset->company_id)->pluck('id');
        $data = $request->validate([
            'to_employee_id' => ['required', Rule::in($tenantEmployeeIds)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fromEmployeeId = $asset->responsible_employee_id;

        $asset->update(['responsible_employee_id' => $data['to_employee_id'], 'status' => 'assigned']);
        $asset->transactions()->create([
            'type' => AssetTransaction::TYPE_ASSIGNMENT,
            'from_employee_id' => $fromEmployeeId,
            'to_employee_id' => $data['to_employee_id'],
            'performed_by' => $request->user()->id,
            'transaction_date' => now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);

        ActivityLog::record('updated', "Asset \"{$asset->name}\" assigned.", $asset);

        return back()->with('success', 'Asset assigned.');
    }

    public function transfer(Request $request, Asset $asset): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $this->assertInCurrentTenant($asset);

        $data = $request->validate([
            'to_location' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fromLocation = $asset->location;
        $asset->update(['location' => $data['to_location']]);
        $asset->transactions()->create([
            'type' => AssetTransaction::TYPE_TRANSFER,
            'from_location' => $fromLocation,
            'to_location' => $data['to_location'],
            'performed_by' => $request->user()->id,
            'transaction_date' => now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);

        ActivityLog::record('updated', "Asset \"{$asset->name}\" transferred to {$data['to_location']}.", $asset);

        return back()->with('success', 'Asset transferred.');
    }

    public function inspect(Request $request, Asset $asset): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $this->assertInCurrentTenant($asset);

        $data = $request->validate([
            'inspection_result' => ['required', Rule::in(['pass', 'fail', 'needs_attention'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset->transactions()->create([
            'type' => AssetTransaction::TYPE_INSPECTION,
            'inspection_result' => $data['inspection_result'],
            'performed_by' => $request->user()->id,
            'transaction_date' => now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);

        ActivityLog::record('created', "Asset \"{$asset->name}\" inspected ({$data['inspection_result']}).", $asset);

        return back()->with('success', 'Inspection recorded.');
    }

    public function changeStatus(Request $request, Asset $asset): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $this->assertInCurrentTenant($asset);

        $data = $request->validate([
            'status' => ['required', Rule::in(Asset::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $previous = $asset->status;
        $asset->update(['status' => $data['status']]);
        $asset->transactions()->create([
            'type' => AssetTransaction::TYPE_STATUS_CHANGE,
            'previous_status' => $previous,
            'new_status' => $data['status'],
            'performed_by' => $request->user()->id,
            'transaction_date' => now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);

        ActivityLog::record('updated', "Asset \"{$asset->name}\" status changed from {$previous} to {$data['status']}.", $asset);

        return back()->with('success', 'Status updated.');
    }

    private function assertInCurrentTenant(Asset $asset): void
    {
        abort_unless(Company::query()->pluck('id')->contains($asset->company_id), 404);
    }
}
