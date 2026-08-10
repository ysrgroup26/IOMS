<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\HseMaterial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Milestone 4, Workstream B11. Master CRUD, rendered on the shared Hse/Master.jsx page. */
class HseMaterialController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(HseMaterial::CATEGORIES)],
            'unit' => ['required', 'string', 'max:20'],
            'current_stock' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $material = HseMaterial::create($data);
        ActivityLog::record('created', "HSE material \"{$material->name}\" was added.", $material);

        return back()->with('success', 'HSE material added.');
    }

    public function update(Request $request, HseMaterial $hseMaterial): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseMaterial->company_id), 404);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(HseMaterial::CATEGORIES)],
            'unit' => ['required', 'string', 'max:20'],
            'current_stock' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $hseMaterial->update($data);
        ActivityLog::record('updated', "HSE material \"{$hseMaterial->name}\" was updated.", $hseMaterial);

        return back()->with('success', 'HSE material updated.');
    }

    public function destroy(Request $request, HseMaterial $hseMaterial): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseMaterial->company_id), 404);

        $name = $hseMaterial->name;
        $hseMaterial->delete();
        ActivityLog::record('deleted', "HSE material \"{$name}\" was removed.");

        return back()->with('success', 'HSE material removed.');
    }
}
