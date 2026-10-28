<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Vendor;
use App\Models\WasteContainerInventory;
use App\Models\WasteMovement;
use App\Models\WasteRecord;
use App\Models\WasteStorageLocation;
use App\Models\WasteType;
use App\Services\NumberGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v1.11.4 (HSE Waste Management, Part 13/14/16). Waste Records --
 * generation through disposal. Number generated via the existing
 * NumberGeneratorService ('waste_record' module key), same as every
 * other numbered document in this codebase, not a new numbering engine.
 * Source reuses the EXISTING Project/ProjectActivity tables (both
 * optional), per explicit instruction not to duplicate that concept.
 */
class WasteRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $companyIds = Company::query()->pluck('id');
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $records = WasteRecord::whereIn('company_id', $companyIds)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('waste_type_id'), fn ($q, $v) => $q->where('waste_type_id', $v))
            ->with('wasteType:id,name,category', 'storageLocation:id,name', 'project:id,name')
            ->latest('generated_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Hse/WasteManagement/Index', [
            'records' => $records,
            'wasteTypes' => WasteType::whereIn('company_id', $companyIds)->active()->get(),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('company_id', 'status', 'waste_type_id'),
            'statuses' => WasteRecord::STATUSES,
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(): Response
    {
        $companyIds = Company::query()->pluck('id');

        return Inertia::render('Hse/WasteManagement/Form', [
            'wasteTypes' => WasteType::whereIn('company_id', $companyIds)->active()->get(),
            'storageLocations' => WasteStorageLocation::whereIn('company_id', $companyIds)->active()->get(),
            'projects' => Project::whereIn('company_id', $companyIds)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            // v2.135.0: the units a quantity can be recorded in, and the
            // company's own container types as suggestions (the container
            // register counts drums; a record only names what holds it).
            'units' => collect(WasteRecord::UNITS)->map(fn ($u, $key) => ['value' => $key, ...$u])->values(),
            'containerTypes' => WasteContainerInventory::whereIn('company_id', $companyIds)->distinct()->orderBy('container_type')->pluck('container_type')->filter()->values(),
        ]);
    }

    public function store(Request $request, NumberGeneratorService $numberGenerator): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $companyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($companyIds)],
            'waste_type_id' => ['required', Rule::in(WasteType::whereIn('company_id', $companyIds)->pluck('id'))],
            'project_id' => ['nullable', Rule::in(Project::whereIn('company_id', $companyIds)->pluck('id'))],
            'project_activity_id' => ['nullable', Rule::in(ProjectActivity::whereHas('project', fn ($q) => $q->whereIn('company_id', $companyIds))->pluck('id'))],
            'location' => ['nullable', 'string', 'max:255'],
            'storage_location_id' => ['nullable', Rule::in(WasteStorageLocation::whereIn('company_id', $companyIds)->pluck('id'))],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'unit' => ['required', 'string', 'max:50'],
            'container' => ['nullable', 'string', 'max:100'],
            'generated_date' => ['required', 'date', 'before_or_equal:today'],
            'received_date' => ['nullable', 'date', 'after_or_equal:generated_date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'received_date.after_or_equal' => 'The received date cannot be before the waste was generated.',
        ]);

        // v2.135.0: a record is stored in a TPS of ITS OWN operating unit.
        if (! empty($data['storage_location_id'])
            && ! WasteStorageLocation::where('company_id', $data['company_id'])->active()->whereKey($data['storage_location_id'])->exists()) {
            throw ValidationException::withMessages(['storage_location_id' => 'Choose an active TPS of this operating unit.']);
        }

        // v2.135.0: the unit must be one the system can reason about -- a
        // standard unit, or the waste type's own -- and a count is whole.
        $type = WasteType::whereIn('company_id', $companyIds)->find($data['waste_type_id']);
        $data['unit'] = WasteRecord::normalizeUnit($data['unit']);
        $typeUnit = WasteRecord::normalizeUnit($type?->unit);
        if (! array_key_exists($data['unit'], WasteRecord::UNITS) && $data['unit'] !== $typeUnit) {
            throw ValidationException::withMessages(['unit' => 'Choose one of the listed units.']);
        }
        if ((WasteRecord::UNITS[$data['unit']]['kind'] ?? null) === 'count' && floor((float) $data['quantity']) != (float) $data['quantity']) {
            throw ValidationException::withMessages(['quantity' => 'A count must be a whole number.']);
        }

        $data['record_number'] = $numberGenerator->generate('waste_record', $data['company_id']);
        $data['status'] = $data['storage_location_id'] ?? null ? WasteRecord::STATUS_STORED : WasteRecord::STATUS_GENERATED;
        $data['created_by'] = $request->user()->id;

        $record = WasteRecord::create($data);
        ActivityLog::record('created', "Waste record {$record->record_number} was created.", $record);

        return redirect()->route('waste-records.show', $record->id)->with('success', 'Waste record created.');
    }

    public function show(WasteRecord $wasteRecord): Response
    {
        abort_unless(Company::query()->pluck('id')->contains($wasteRecord->company_id), 404);

        $wasteRecord->load(
            'wasteType', 'storageLocation', 'project:id,name', 'projectActivity:id,name',
            'creator:id,name', 'movements.vendor:id,name', 'movements.creator:id,name', 'movements.documents'
        );

        return Inertia::render('Hse/WasteManagement/Show', [
            'record' => $wasteRecord,
            'wasteVendors' => Vendor::whereIn('company_id', Company::query()->pluck('id'))->active()->wasteVendors()->get(['id', 'name']),
            // v2.134.0: the TPS a record can be placed in -- its own operating unit's active ones.
            'storageLocations' => WasteStorageLocation::where('company_id', $wasteRecord->company_id)->active()->get(['id', 'name', 'code']),
            'can' => ['manage' => request()->user()->canManageHse()],
        ]);
    }

    /**
     * Manual lifecycle transition only, guarded by
     * WasteRecord::ALLOWED_TRANSITIONS -- mirrors WorkOrder/
     * MaintenanceRequest's own transition() controller pattern. Recording
     * a WasteMovement (see WasteMovementController) also drives this
     * same status forward automatically for the pickup/disposal steps;
     * this endpoint covers the remaining manual steps (generated->stored,
     * disposed->closed).
     */
    public function transition(Request $request, WasteRecord $wasteRecord): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($wasteRecord->company_id), 404);

        $data = $request->validate(['status' => ['required', Rule::in(WasteRecord::STATUSES)]]);

        abort_unless($wasteRecord->canTransitionTo($data['status']), 422, "Cannot move from {$wasteRecord->status} to {$data['status']}.");

        $wasteRecord->update(['status' => $data['status']]);
        ActivityLog::record('updated', "Waste record {$wasteRecord->record_number} moved to {$data['status']}.", $wasteRecord);

        return back()->with('success', 'Status updated.');
    }

    /**
     * v2.134.0 -- put a record in (or move it to another) TPS.
     *
     * The storage location could be chosen only when the record was
     * created, so a record generated before it reached a TPS had no way to
     * get one -- and the dashboard's "stored without a location" warning
     * had no action behind it. A `generated` record that receives a
     * location becomes `stored` through the same ALLOWED_TRANSITIONS guard
     * transition() uses; nothing else about its lifecycle changes.
     */
    public function assignStorage(Request $request, WasteRecord $wasteRecord): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($wasteRecord->company_id), 404);
        abort_unless(in_array($wasteRecord->status, WasteRecord::STORED_STATUSES, true), 422, 'Only waste that is still on site can be placed in a storage location.');

        $data = $request->validate([
            'storage_location_id' => ['required', Rule::in(
                WasteStorageLocation::where('company_id', $wasteRecord->company_id)->active()->pluck('id')
            )],
            'received_date' => ['nullable', 'date', 'after_or_equal:'.$wasteRecord->generated_date?->toDateString(), 'before_or_equal:today'],
        ], [
            'storage_location_id.in' => 'Choose an active storage location of this operating unit.',
        ]);

        $from = $wasteRecord->storageLocation?->name;
        $update = ['storage_location_id' => $data['storage_location_id']];
        if (! $wasteRecord->received_date) {
            $update['received_date'] = $data['received_date'] ?? now()->toDateString();
        }
        if ($wasteRecord->status === WasteRecord::STATUS_GENERATED && $wasteRecord->canTransitionTo(WasteRecord::STATUS_STORED)) {
            $update['status'] = WasteRecord::STATUS_STORED;
        }
        $wasteRecord->update($update);
        $to = $wasteRecord->fresh('storageLocation')->storageLocation?->name;

        ActivityLog::record('updated', $from
            ? "Waste record {$wasteRecord->record_number} moved from {$from} to {$to}."
            : "Waste record {$wasteRecord->record_number} placed in {$to}.", $wasteRecord);

        return back()->with('success', 'Storage location updated.');
    }
}
