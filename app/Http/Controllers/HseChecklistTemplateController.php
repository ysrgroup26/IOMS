<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\HseChecklistTemplate;
use App\Models\HseInspection;
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

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'category' => ['required', Rule::in(HseInspection::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $template = HseChecklistTemplate::create($data);
        ActivityLog::record('created', "Checklist template \"{$template->name}\" was added.", $template);

        return back()->with('success', 'Checklist template added.');
    }

    public function update(Request $request, HseChecklistTemplate $hseChecklistTemplate): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseChecklistTemplate->company_id), 404);

        $data = $request->validate([
            'category' => ['required', Rule::in(HseInspection::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $hseChecklistTemplate->update($data);
        ActivityLog::record('updated', "Checklist template \"{$hseChecklistTemplate->name}\" was updated.", $hseChecklistTemplate);

        return back()->with('success', 'Checklist template updated.');
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
