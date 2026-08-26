<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\WasteContainerInventory;
use App\Models\WasteStorageLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 7/8/9/10/11).
 * Waste Container Inventory -- physical container/equipment stock
 * (drums, IBC tanks, jumbo bags), separate from Waste Records (actual
 * waste material) and Waste Master (Types/Storage Locations config).
 * Same `abort_unless(canManageHse())` + manual tenant-ownership check
 * pattern as WasteMasterController -- deliberately not a Policy class,
 * matching that controller's own established style for this module.
 */
class WasteContainerController extends Controller
{
    public function index(): Response
    {
        $companyIds = Company::query()->pluck('id');

        $containers = WasteContainerInventory::whereIn('company_id', $companyIds)
            ->with('storageLocation:id,name')
            ->orderBy('container_type')
            ->get()
            ->map(fn (WasteContainerInventory $c) => [
                'id' => $c->id,
                'company_id' => $c->company_id,
                'container_type' => $c->container_type,
                'code' => $c->code,
                'unit' => $c->unit,
                'total_quantity' => $c->total_quantity,
                'in_use_quantity' => $c->in_use_quantity,
                'damaged_quantity' => $c->damaged_quantity,
                'available_quantity' => $c->available_quantity,
                'capacity' => $c->capacity,
                'capacity_unit' => $c->capacity_unit,
                'storage_location_id' => $c->storage_location_id,
                'storage_location' => $c->storageLocation?->name,
                'status' => $c->status,
                'notes' => $c->notes,
            ]);

        return Inertia::render('Hse/WasteContainers', [
            'containers' => $containers,
            'summary' => [
                'total' => (int) $containers->sum('total_quantity'),
                'available' => (int) $containers->sum('available_quantity'),
                'in_use' => (int) $containers->sum('in_use_quantity'),
                'damaged' => (int) $containers->sum('damaged_quantity'),
            ],
            'storageLocations' => WasteStorageLocation::whereIn('company_id', $companyIds)
                ->active()->get(['id', 'name', 'company_id']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => request()->user()->canManageHse()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $companyIds = Company::query()->pluck('id');

        $data = $this->validated($request, $companyIds);
        $data['created_by'] = $request->user()->id;

        $container = WasteContainerInventory::create($data);
        ActivityLog::record('created', "Waste container inventory \"{$container->container_type}\" was added.", $container);

        return back()->with('success', 'Container inventory added.');
    }

    public function update(Request $request, WasteContainerInventory $wasteContainer): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $companyIds = Company::query()->pluck('id');
        abort_unless($companyIds->contains($wasteContainer->company_id), 404);

        $data = $this->validated($request, $companyIds, forUpdate: true);

        $wasteContainer->update($data);
        ActivityLog::record('updated', "Waste container inventory \"{$wasteContainer->container_type}\" was updated.", $wasteContainer);

        return back()->with('success', 'Container inventory updated.');
    }

    public function destroy(Request $request, WasteContainerInventory $wasteContainer): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $companyIds = Company::query()->pluck('id');
        abort_unless($companyIds->contains($wasteContainer->company_id), 404);

        $type = $wasteContainer->container_type;
        $wasteContainer->delete();
        ActivityLog::record('deleted', "Waste container inventory \"{$type}\" was removed.");

        return back()->with('success', 'Container inventory removed.');
    }

    /**
     * Shared validation for store/update. `in_use + damaged <= total` is
     * enforced here (not at the database level -- no CHECK constraint
     * introduced, matching this codebase's existing convention of
     * validating invariants in the FormRequest/controller layer rather
     * than the schema) so `available_quantity` (computed as
     * `total - in_use - damaged`) can never be asked to represent an
     * impossible state from valid input.
     */
    private function validated(Request $request, $companyIds, bool $forUpdate = false): array
    {
        $rules = [
            'container_type' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:50'],
            'total_quantity' => ['required', 'integer', 'min:0'],
            'in_use_quantity' => ['required', 'integer', 'min:0'],
            'damaged_quantity' => ['required', 'integer', 'min:0'],
            'capacity' => ['nullable', 'numeric', 'min:0'],
            'capacity_unit' => ['nullable', 'string', 'max:50'],
            'storage_location_id' => ['nullable', Rule::exists('waste_storage_locations', 'id')->whereIn('company_id', $companyIds)],
            'status' => ['required', Rule::in(WasteContainerInventory::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        if (! $forUpdate) {
            $rules['company_id'] = ['required', Rule::in($companyIds)];
        }

        $data = $request->validate($rules);

        if (($data['in_use_quantity'] + $data['damaged_quantity']) > $data['total_quantity']) {
            abort(422, 'In Use + Damaged cannot exceed Total quantity.');
        }

        return $data;
    }
}
