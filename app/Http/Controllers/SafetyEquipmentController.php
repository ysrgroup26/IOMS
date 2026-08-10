<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\SafetyEquipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Milestone 4, Workstream B10. Master CRUD, rendered on the shared Hse/Master.jsx page. */
class SafetyEquipmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(SafetyEquipment::TYPES)],
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

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(SafetyEquipment::TYPES)],
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
}
