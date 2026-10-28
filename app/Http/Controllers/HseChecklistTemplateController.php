<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\HseChecklistTemplate;
use App\Models\HseEquipmentType;
use App\Models\HseInspection;
use App\Models\SafetyEquipmentInspection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * v1.11.2 (Final Completion Pass, Part 9). Checklist Template CRUD --
 * mirrors HazardCategoryController/HseEquipmentTypeController's own
 * tenant-safe pattern exactly (Rule::in() on create, abort_unless(...
 * ->contains(...)) ownership check on update/destroy). `category` is
 * validated against HseInspection::TYPES, the same source of truth the
 * Inspection form's own type dropdown already uses -- not a duplicated
 * enum.
 */
class HseChecklistTemplateController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $this->validated($request, ['company_id' => ['required', Rule::in($tenantCompanyIds)]]);

        $template = HseChecklistTemplate::create($data);
        ActivityLog::record('created', "Checklist template \"{$template->name}\" was added.", $template);

        return back()->with('success', 'Checklist template added.');
    }

    public function update(Request $request, HseChecklistTemplate $hseChecklistTemplate): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseChecklistTemplate->company_id), 404);

        $data = $this->validated($request);

        $hseChecklistTemplate->update($data);
        ActivityLog::record('updated', "Checklist template \"{$hseChecklistTemplate->name}\" was updated.", $hseChecklistTemplate);

        return back()->with('success', 'Checklist template updated.');
    }

    /**
     * v2.135.0 -- A TEMPLATE IS EITHER FOR AN AREA INSPECTION OR FOR AN
     * EQUIPMENT TYPE. An area template names one of HseInspection::TYPES; an
     * equipment template names one of this tenant's equipment types and is
     * filed under the `equipment` category, so area inspections never offer
     * it. Items say what KIND of check they are, whether a Fail is critical,
     * and whether a Fail needs a photo -- the one generic label column could
     * not tell an APAR's pressure gauge from a first-aid box's contents.
     */
    private function validated(Request $request, array $extra = []): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $typeIds = HseEquipmentType::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            ...$extra,
            'equipment_type_id' => ['nullable', Rule::in($typeIds)],
            'category' => ['required_without:equipment_type_id', 'nullable', Rule::in(HseInspection::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.check' => ['nullable', Rule::in(SafetyEquipmentInspection::CHECKS)],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.min' => ['nullable', 'numeric'],
            'items.*.max' => ['nullable', 'numeric'],
            'items.*.critical' => ['nullable', 'boolean'],
            'items.*.photo_on_fail' => ['nullable', 'boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'category.required_without' => 'Choose an inspection category or an equipment type.',
        ]);

        if (! empty($data['equipment_type_id'])) {
            $data['category'] = HseChecklistTemplate::EQUIPMENT_CATEGORY;
        } else {
            $data['equipment_type_id'] = null;
        }
        // Store only the fields a check uses, so a filed item reads cleanly.
        $data['items'] = array_values(array_map(fn ($item) => array_filter([
            'label' => trim($item['label']),
            'description' => $item['description'] ?? null,
            'check' => $item['check'] ?? 'pass_fail',
            'unit' => ($item['check'] ?? null) === 'reading' ? ($item['unit'] ?? null) : null,
            'min' => ($item['check'] ?? null) === 'reading' ? ($item['min'] ?? null) : null,
            'max' => ($item['check'] ?? null) === 'reading' ? ($item['max'] ?? null) : null,
            'critical' => (bool) ($item['critical'] ?? false),
            'photo_on_fail' => (bool) ($item['photo_on_fail'] ?? false),
        ], fn ($v) => $v !== null && $v !== ''), $data['items']));

        return $data;
    }

    public function destroy(Request $request, HseChecklistTemplate $hseChecklistTemplate): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseChecklistTemplate->company_id), 404);

        $name = $hseChecklistTemplate->name;
        $hseChecklistTemplate->delete();
        ActivityLog::record('deleted', "Checklist template \"{$name}\" was removed.");

        return back()->with('success', 'Checklist template removed.');
    }
}
