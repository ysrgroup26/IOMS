<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\StorageLocation;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 1B. Warehouse + Storage Location master, one setup page (mirrors Hse/Master.jsx's multi-section pattern). */
class WarehouseController extends Controller
{
    public function master(): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('Warehouses/Master', [
            'warehouses' => Warehouse::whereIn('company_id', $tenantCompanyIds)
                ->withCount('storageLocations', 'stocks')
                ->with('pic:id,name')
                ->orderBy('name')
                ->get(),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => request()->user()->canManageWarehouse()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'code' => ['required', 'string', 'max:20', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $warehouse = Warehouse::create($data);
        ActivityLog::record('created', "Warehouse \"{$warehouse->name}\" was added.", $warehouse);

        return back()->with('success', 'Warehouse added.');
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $this->assertInCurrentTenant($warehouse);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'code' => ['required', 'string', 'max:20', Rule::unique('warehouses', 'code')->ignore($warehouse->id)],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $warehouse->update($data);
        ActivityLog::record('updated', "Warehouse \"{$warehouse->name}\" was updated.", $warehouse);

        return back()->with('success', 'Warehouse updated.');
    }

    public function destroy(Warehouse $warehouse, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $this->assertInCurrentTenant($warehouse);

        if ($warehouse->stocks()->where('quantity', '>', 0)->exists()) {
            return back()->with('error', 'Cannot delete a warehouse that still holds stock.');
        }

        $name = $warehouse->name;
        $warehouse->delete();
        ActivityLog::record('deleted', "Warehouse \"{$name}\" was removed.");

        return back()->with('success', 'Warehouse removed.');
    }

    public function storeLocation(Request $request, Warehouse $warehouse): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        $this->assertInCurrentTenant($warehouse);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'area' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $warehouse->storageLocations()->create($data);

        return back()->with('success', 'Storage location added.');
    }

    public function destroyLocation(Warehouse $warehouse, StorageLocation $location, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageWarehouse(), 403);
        abort_unless($location->warehouse_id === $warehouse->id, 404);
        $this->assertInCurrentTenant($warehouse);

        $location->delete();

        return back()->with('success', 'Storage location removed.');
    }

    private function assertInCurrentTenant(Warehouse $warehouse): void
    {
        abort_unless(Company::query()->pluck('id')->contains($warehouse->company_id), 404);
    }
}
