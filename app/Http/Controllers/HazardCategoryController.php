<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHazardCategoryRequest;
use App\Http\Requests\UpdateHazardCategoryRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\HazardCategory;
use App\Models\HseChecklistTemplate;
use App\Models\HseEquipmentType;
use App\Models\HseInspection;
use App\Models\HseMaterial;
use App\Models\P3kBox;
use App\Models\SafetyEquipment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B0. Hazard Category Master -- mirrors
 * ShiftController/CompetencyTypeController exactly (table-driven catalog,
 * one setup page, TenantScope-safe via Company::query()).
 */
class HazardCategoryController extends Controller
{
    public function master(): Response|RedirectResponse
    {
        // v2.133.0 (ADR 074) -- the register left this page for its own.
        // A bookmark to the old tab lands on the register, not on a tab
        // that no longer exists. So does a BARE /hse/master: until v2.133.0
        // that URL WAS "Safety Equipment & Compliance" (the sidebar item,
        // the HSE Overview KPI, every bookmark and installed-app shortcut),
        // and without a tab it opened Supplies -- two plain tables under the
        // same title, which read exactly like the old register. Reference
        // data is always reached with an explicit ?tab= (AreaNav).
        if (in_array(request()->query('tab'), [null, '', 'register'], true)) {
            return redirect()->route('safety-equipment.index');
        }

        $companyIds = Company::query()->pluck('id');

        return Inertia::render('Hse/Master', [
            'hazardCategories' => HazardCategory::whereIn('company_id', $companyIds)
                ->withCount('safetyObservations')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            // Milestone 4, Workstream B10/B11/B12 -- Safety Equipment,
            // HSE Materials, and P3K masters live on this same shared setup
            // page rather than three more standalone routes.
            // v2.133.0: the register itself is SafetyEquipmentController::index.
            // Only the per-type unit count is needed here, so a type can say
            // how many units depend on it before somebody deactivates it.
            'equipmentCounts' => SafetyEquipment::whereIn('company_id', $companyIds)
                ->whereNotNull('equipment_type_id')
                ->selectRaw('equipment_type_id, COUNT(*) as aggregate')
                ->groupBy('equipment_type_id')
                ->pluck('aggregate', 'equipment_type_id'),
            // v1.11.1 (HSE Domain Hardening II, Part 7) -- configurable
            // equipment type master, replacing the old hardcoded
            // SafetyEquipment::TYPES array. See the owning migration's
            // own doc comment.
            // v2.133.0: inactive types too -- they are reference data that
            // can be reactivated, and hiding them made "deactivate" look
            // like "delete".
            'equipmentTypes' => HseEquipmentType::whereIn('company_id', $companyIds)->orderBy('sort_order')->orderBy('name')->get(),
            'hseMaterials' => HseMaterial::whereIn('company_id', $companyIds)->orderBy('name')->get(),
            'p3kBoxes' => P3kBox::whereIn('company_id', $companyIds)->with('inspector:id,name')->orderBy('location')->get(),
            // v1.11.2 (Final Completion Pass, Part 9) -- LSA/FFA/PPE
            // checklist templates, same shared setup page.
            'checklistTemplates' => HseChecklistTemplate::whereIn('company_id', $companyIds)
                ->orderBy('category')->orderBy('sort_order')->get(),
            'inspectionTypes' => HseInspection::TYPES,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => request()->user()->isAdmin()],
        ]);
    }

    public function store(StoreHazardCategoryRequest $request): RedirectResponse
    {
        $hazardCategory = HazardCategory::create($request->validated());

        ActivityLog::record('created', "Hazard category \"{$hazardCategory->name}\" was created.", $hazardCategory);

        return back()->with('success', 'Hazard category added.');
    }

    public function update(UpdateHazardCategoryRequest $request, HazardCategory $hazardCategory): RedirectResponse
    {
        $this->assertInCurrentTenant($hazardCategory);

        $hazardCategory->update($request->validated());

        ActivityLog::record('updated', "Hazard category \"{$hazardCategory->name}\" was updated.", $hazardCategory);

        return back()->with('success', 'Hazard category updated.');
    }

    public function destroy(HazardCategory $hazardCategory): RedirectResponse
    {
        $this->authorize('delete', $hazardCategory);
        $this->assertInCurrentTenant($hazardCategory);

        if ($hazardCategory->safetyObservations()->exists()) {
            return back()->with('error', 'Cannot delete a hazard category that has safety observations against it.');
        }

        $name = $hazardCategory->name;
        $hazardCategory->delete();

        ActivityLog::record('deleted', "Hazard category \"{$name}\" was removed.");

        return back()->with('success', 'Hazard category removed.');
    }

    /**
     * Tenant ownership guard for route-model-bound HazardCategory --
     * HazardCategory has no automatic TenantScope (only Company does), so
     * without this a Tenant B admin could PUT/DELETE a Tenant A hazard
     * category by id (its own submitted `company_id` field validates fine
     * against Tenant B's own companies; the EXISTING row's tenant was
     * never checked). Same 404-not-403 pattern as
     * SafetyObservationController::assertObservationInCurrentTenant().
     */
    private function assertInCurrentTenant(HazardCategory $hazardCategory): void
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        abort_unless($tenantCompanyIds->contains($hazardCategory->company_id), 404);
    }
}
