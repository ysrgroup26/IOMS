<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\WasteRecord;
use App\Models\WasteStorageLocation;
use App\Models\WasteType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v1.11.4 (HSE Waste Management, Part 12/15). Waste Types + Waste
 * Storage/TPS master CRUD, one shared setup page -- mirrors
 * HazardCategoryController/WarehouseController's own multi-section
 * master-data pattern exactly, not a new page-per-master convention.
 */
class WasteMasterController extends Controller
{
    public function master(): Response
    {
        $companyIds = Company::query()->pluck('id');

        return Inertia::render('Hse/WasteMaster', [
            'wasteTypes' => WasteType::whereIn('company_id', $companyIds)
                ->orderBy('sort_order')->orderBy('name')->get(),
            'storageLocations' => WasteStorageLocation::whereIn('company_id', $companyIds)
                ->withCount(['wasteRecords' => fn ($q) => $q->whereIn('status', WasteRecord::STORED_STATUSES)])
                ->orderBy('name')->get(),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => request()->user()->canManageHse()],
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $companyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($companyIds)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash'],
            'category' => ['required', Rule::in(WasteType::CATEGORIES)],
            'waste_code' => ['nullable', 'string', 'max:100'],
            'characteristics' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:50'],
            'storage_limit_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $type = WasteType::create($data);
        ActivityLog::record('created', "Waste type \"{$type->name}\" was added.", $type);

        return back()->with('success', 'Waste type added.');
    }

    public function updateType(Request $request, WasteType $wasteType): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($wasteType->company_id), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(WasteType::CATEGORIES)],
            'waste_code' => ['nullable', 'string', 'max:100'],
            'characteristics' => ['nullable', 'string', 'max:1000'],
            'unit' => ['required', 'string', 'max:50'],
            'storage_limit_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $wasteType->update($data);
        ActivityLog::record('updated', "Waste type \"{$wasteType->name}\" was updated.", $wasteType);

        return back()->with('success', 'Waste type updated.');
    }

    public function destroyType(Request $request, WasteType $wasteType): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($wasteType->company_id), 404);

        if (WasteRecord::where('waste_type_id', $wasteType->id)->exists()) {
            return back()->with('error', 'Cannot delete a waste type that has records against it -- deactivate instead.');
        }

        $name = $wasteType->name;
        $wasteType->delete();
        ActivityLog::record('deleted', "Waste type \"{$name}\" was removed.");

        return back()->with('success', 'Waste type removed.');
    }

    public function storeStorageLocation(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $companyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($companyIds)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash'],
            'location' => ['nullable', 'string', 'max:255'],
            'container_type' => ['nullable', 'string', 'max:100'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_unit' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(WasteStorageLocation::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $location = WasteStorageLocation::create($data);
        ActivityLog::record('created', "Waste storage location \"{$location->name}\" was added.", $location);

        return back()->with('success', 'Storage location added.');
    }

    public function updateStorageLocation(Request $request, WasteStorageLocation $wasteStorageLocation): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($wasteStorageLocation->company_id), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'container_type' => ['nullable', 'string', 'max:100'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_unit' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(WasteStorageLocation::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $wasteStorageLocation->update($data);
        ActivityLog::record('updated', "Waste storage location \"{$wasteStorageLocation->name}\" was updated.", $wasteStorageLocation);

        return back()->with('success', 'Storage location updated.');
    }

    public function destroyStorageLocation(Request $request, WasteStorageLocation $wasteStorageLocation): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($wasteStorageLocation->company_id), 404);

        if (WasteRecord::where('storage_location_id', $wasteStorageLocation->id)->exists()) {
            return back()->with('error', 'Cannot delete a storage location that has waste records against it.');
        }

        $name = $wasteStorageLocation->name;
        $wasteStorageLocation->delete();
        ActivityLog::record('deleted', "Waste storage location \"{$name}\" was removed.");

        return back()->with('success', 'Storage location removed.');
    }
}
