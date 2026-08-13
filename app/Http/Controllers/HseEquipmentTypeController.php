<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\HseEquipmentType;
use App\Models\SafetyEquipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** v1.11.1 (HSE Domain Hardening II, Part 7). Configurable HSE equipment type master CRUD -- mirrors HazardCategoryController exactly. */
class HseEquipmentTypeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $type = HseEquipmentType::create($data);
        ActivityLog::record('created', "HSE equipment type \"{$type->name}\" was added.", $type);

        return back()->with('success', 'Equipment type added.');
    }

    public function update(Request $request, HseEquipmentType $hseEquipmentType): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseEquipmentType->company_id), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $hseEquipmentType->update($data);
        ActivityLog::record('updated', "HSE equipment type \"{$hseEquipmentType->name}\" was updated.", $hseEquipmentType);

        return back()->with('success', 'Equipment type updated.');
    }

    public function destroy(Request $request, HseEquipmentType $hseEquipmentType): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseEquipmentType->company_id), 404);

        if (SafetyEquipment::where('company_id', $hseEquipmentType->company_id)->where('type', $hseEquipmentType->code)->exists()) {
            return back()->with('error', 'Cannot delete a type that has equipment against it -- deactivate instead.');
        }

        $name = $hseEquipmentType->name;
        $hseEquipmentType->delete();
        ActivityLog::record('deleted', "HSE equipment type \"{$name}\" was removed.");

        return back()->with('success', 'Equipment type removed.');
    }
}
