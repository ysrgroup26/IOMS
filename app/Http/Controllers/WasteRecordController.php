<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Vendor;
use App\Models\WasteMovement;
use App\Models\WasteRecord;
use App\Models\WasteStorageLocation;
use App\Models\WasteType;
use App\Services\NumberGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['required', 'string', 'max:50'],
            'container' => ['nullable', 'string', 'max:100'],
            'generated_date' => ['required', 'date'],
            'received_date' => ['nullable', 'date', 'after_or_equal:generated_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

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
}
