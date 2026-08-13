<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\HseEquipmentType;
use App\Models\SafetyEquipment;
use App\Models\SafetyEquipmentInspection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Milestone 4, Workstream B10. Master CRUD, rendered on the shared
 * Hse/Master.jsx page.
 *
 * v1.11.1: `type` now validates against the tenant's own configurable
 * `HseEquipmentType` codes (Rule::in over a tenant-scoped collection --
 * same IDOR-safe pattern as every other tenant-owned validation in this
 * codebase) instead of the old hardcoded `SafetyEquipment::TYPES` array.
 * Added recordInspection() -- writes a real SafetyEquipmentInspection
 * history row AND keeps the equipment's own last_inspection_date/
 * next_inspection_due denormalized fields in sync (existing dashboard
 * widgets/overdue queries already read those two columns directly; kept
 * in sync here rather than rewriting every consumer to join the new
 * child table).
 */
class SafetyEquipmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantTypeCodes = HseEquipmentType::whereIn('company_id', $tenantCompanyIds)->active()->pluck('code');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in($tenantTypeCodes)],
            'location' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'last_inspection_date' => ['nullable', 'date'],
            'next_inspection_due' => ['nullable', 'date'],
            'status' => ['required', Rule::in(SafetyEquipment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $equipment = SafetyEquipment::create($data);
        ActivityLog::record('created', "Safety equipment \"{$equipment->name}\" was added.", $equipment);

        return back()->with('success', 'Safety equipment added.');
    }

    public function update(Request $request, SafetyEquipment $safetyEquipment): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($safetyEquipment->company_id), 404);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantTypeCodes = HseEquipmentType::whereIn('company_id', $tenantCompanyIds)->active()->pluck('code');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in($tenantTypeCodes)],
            'location' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'last_inspection_date' => ['nullable', 'date'],
            'next_inspection_due' => ['nullable', 'date'],
            'status' => ['required', Rule::in(SafetyEquipment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $safetyEquipment->update($data);
        ActivityLog::record('updated', "Safety equipment \"{$safetyEquipment->name}\" was updated.", $safetyEquipment);

        return back()->with('success', 'Safety equipment updated.');
    }

    public function destroy(Request $request, SafetyEquipment $safetyEquipment): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($safetyEquipment->company_id), 404);

        $name = $safetyEquipment->name;
        $safetyEquipment->delete();
        ActivityLog::record('deleted', "Safety equipment \"{$name}\" was removed.");

        return back()->with('success', 'Safety equipment removed.');
    }

    public function recordInspection(Request $request, SafetyEquipment $safetyEquipment): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($safetyEquipment->company_id), 404);

        $data = $request->validate([
            'inspection_date' => ['required', 'date'],
            'condition' => ['required', Rule::in(SafetyEquipmentInspection::CONDITIONS)],
            'result' => ['required', Rule::in(SafetyEquipmentInspection::RESULTS)],
            'findings' => ['nullable', 'string', 'max:1000'],
            'next_inspection_due' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $safetyEquipment->inspections()->create([
            ...$data,
            'company_id' => $safetyEquipment->company_id,
            'inspector_id' => $request->user()->id,
        ]);

        // Keep the equipment's own denormalized fields in sync -- see
        // this controller's own doc comment for why they're not dropped.
        $safetyEquipment->update([
            'last_inspection_date' => $data['inspection_date'],
            'next_inspection_due' => $data['next_inspection_due'] ?? $safetyEquipment->next_inspection_due,
        ]);

        ActivityLog::record('created', "Inspection recorded for \"{$safetyEquipment->name}\" ({$data['result']}).", $safetyEquipment);

        return back()->with('success', 'Inspection recorded.');
    }
}
