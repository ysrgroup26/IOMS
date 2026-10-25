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
            // v2.133.0 -- the lifecycle declaration v2.53.0 added to the
            // table and no form could set. The register now asks only for
            // the dates a type declares here (ADR 074).
            'code_prefix' => ['nullable', 'string', 'max:12', 'alpha_num'],
            'tracks_inspection' => ['boolean'],
            'tracks_calibration' => ['boolean'],
            'tracks_service' => ['boolean'],
            'tracks_expiry' => ['boolean'],
            'inspection_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $type = HseEquipmentType::create($this->normalise($data));
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
            // v2.133.0 -- the lifecycle declaration v2.53.0 added to the
            // table and no form could set. The register now asks only for
            // the dates a type declares here (ADR 074).
            'code_prefix' => ['nullable', 'string', 'max:12', 'alpha_num'],
            'tracks_inspection' => ['boolean'],
            'tracks_calibration' => ['boolean'],
            'tracks_service' => ['boolean'],
            'tracks_expiry' => ['boolean'],
            'inspection_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $hseEquipmentType->update($this->normalise($data));
        ActivityLog::record('updated', "HSE equipment type \"{$hseEquipmentType->name}\" was updated.", $hseEquipmentType);

        return back()->with('success', 'Equipment type updated.');
    }

    public function destroy(Request $request, HseEquipmentType $hseEquipmentType): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseEquipmentType->company_id), 404);

        $inUse = SafetyEquipment::where('company_id', $hseEquipmentType->company_id)
            ->where(fn ($q) => $q->where('equipment_type_id', $hseEquipmentType->id)->orWhere('type', $hseEquipmentType->code))
            ->exists();
        if ($inUse) {
            return back()->with('error', 'This type still has equipment registered against it. Deactivate it instead.');
        }

        $name = $hseEquipmentType->name;
        $hseEquipmentType->delete();
        ActivityLog::record('deleted', "HSE equipment type \"{$name}\" was removed.");

        return back()->with('success', 'Equipment type removed.');
    }

    /** A prefix is stored upper case ("fe" and "FE" are one prefix), and an empty one is null, not "". */
    private function normalise(array $data): array
    {
        if (array_key_exists('code_prefix', $data)) {
            $data['code_prefix'] = $data['code_prefix'] ? strtoupper($data['code_prefix']) : null;
        }

        return $data;
    }
}
