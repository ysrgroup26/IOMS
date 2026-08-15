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
    public function master(): Response
    {
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
            'safetyEquipment' => SafetyEquipment::whereIn('company_id', $companyIds)->with('inspections.inspector:id,name')->orderBy('name')->get(),
            // v1.11.1 (HSE Domain Hardening II, Part 7) -- configurable
            // equipment type master, replacing the old hardcoded
            // SafetyEquipment::TYPES array. See the owning migration's
            // own doc comment.
            'equipmentTypes' => HseEquipmentType::whereIn('company_id', $companyIds)->active()->get(),
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
