<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Vendor;
use App\Models\WasteMovement;
use App\Models\WasteMovementDocument;
use App\Models\WasteRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * v1.11.4 (HSE Waste Management, Part 16/17). Mirrors
 * SafetyEquipmentController::recordInspection()'s own "child log row +
 * keep parent's denormalized status in sync" pattern exactly. Reuses the
 * existing Vendor table (waste-flagged) and the existing document-upload
 * convention (VendorController's own `store('uploads/...', 'public')`
 * call) -- no new vendor table, no new file-storage mechanism.
 */
class WasteMovementController extends Controller
{
    public function store(Request $request, WasteRecord $wasteRecord): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($wasteRecord->company_id), 404);

        $tenantVendorIds = Vendor::whereIn('company_id', Company::query()->pluck('id'))->pluck('id');

        $data = $request->validate([
            'vendor_id' => ['nullable', Rule::in($tenantVendorIds)],
            'manifest_number' => ['nullable', 'string', 'max:100'],
            'pickup_date' => ['nullable', 'date'],
            'destination' => ['nullable', 'string', 'max:255'],
            'disposal_date' => ['nullable', 'date', 'after_or_equal:pickup_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'documents' => ['nullable', 'array'],
            'documents.*.file' => ['nullable', 'file', 'max:10240'],
            'documents.*.document_type' => ['nullable', Rule::in(WasteMovementDocument::TYPES)],
        ]);

        $status = $data['disposal_date'] ?? null
            ? WasteMovement::STATUS_DISPOSED
            : ($data['pickup_date'] ?? null ? WasteMovement::STATUS_PICKED_UP : WasteMovement::STATUS_SCHEDULED);

        $movement = $wasteRecord->movements()->create([
            'company_id' => $wasteRecord->company_id,
            'vendor_id' => $data['vendor_id'] ?? null,
            'manifest_number' => $data['manifest_number'] ?? null,
            'pickup_date' => $data['pickup_date'] ?? null,
            'destination' => $data['destination'] ?? null,
            'disposal_date' => $data['disposal_date'] ?? null,
            'status' => $status,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        foreach ($request->file('documents', []) as $index => $doc) {
            if (! isset($doc['file'])) {
                continue;
            }
            $path = $doc['file']->store('uploads/waste-movements', 'public');
            $movement->documents()->create([
                'document_type' => $request->input("documents.{$index}.document_type", 'other'),
                'file_path' => $path,
                'original_name' => $doc['file']->getClientOriginalName(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        // Keep the parent WasteRecord's lifecycle status in sync -- same
        // "denormalized field, kept current by the child action" pattern
        // as SafetyEquipmentController::recordInspection().
        $recordStatus = match ($status) {
            WasteMovement::STATUS_DISPOSED => WasteRecord::STATUS_DISPOSED,
            WasteMovement::STATUS_PICKED_UP => WasteRecord::STATUS_IN_TRANSIT,
            default => WasteRecord::STATUS_SCHEDULED_PICKUP,
        };
        if ($wasteRecord->canTransitionTo($recordStatus)) {
            $wasteRecord->update(['status' => $recordStatus]);
        }

        ActivityLog::record('created', "Movement recorded for waste record {$wasteRecord->record_number} ({$status}).", $movement);

        return back()->with('success', 'Movement recorded.');
    }
}
