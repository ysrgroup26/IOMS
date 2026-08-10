<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompetencyTypeRequest;
use App\Http\Requests\UpdateCompetencyTypeRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompetencyType;
use Illuminate\Http\RedirectResponse;

/**
 * Milestone 4, Workstream A2. Competency (Training/Certification) Master
 * -- mirrors PpeTypeController exactly (table-driven catalog, Admin can
 * add/edit any type without a code change).
 */
class CompetencyTypeController extends Controller
{
    public function store(StoreCompetencyTypeRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $positionIds = $validated['required_position_ids'] ?? [];
        unset($validated['required_position_ids']);

        $competencyType = CompetencyType::create($validated);
        $competencyType->requiredByPositions()->sync($positionIds);

        ActivityLog::record('created', "Competency type \"{$competencyType->name}\" was created.", $competencyType);

        return back()->with('success', 'Competency type added.');
    }

    public function update(UpdateCompetencyTypeRequest $request, CompetencyType $competencyType): RedirectResponse
    {
        $this->assertInCurrentTenant($competencyType);

        $validated = $request->validated();
        $positionIds = $validated['required_position_ids'] ?? [];
        unset($validated['required_position_ids']);

        $competencyType->update($validated);
        $competencyType->requiredByPositions()->sync($positionIds);

        ActivityLog::record('updated', "Competency type \"{$competencyType->name}\" was updated.", $competencyType);

        return back()->with('success', 'Competency type updated.');
    }

    public function destroy(CompetencyType $competencyType): RedirectResponse
    {
        $this->authorize('delete', $competencyType);
        $this->assertInCurrentTenant($competencyType);

        if ($competencyType->employeeCompetencies()->exists()) {
            return back()->with('error', 'Cannot delete a competency type that has employee records against it.');
        }

        $name = $competencyType->name;
        $competencyType->requiredByPositions()->detach();
        $competencyType->delete();

        ActivityLog::record('deleted', "Competency type \"{$name}\" was removed.");

        return back()->with('success', 'Competency type removed.');
    }

    /**
     * v1.10.5 security fix: `update()`/`destroy()` previously relied only
     * on the SUBMITTED `company_id` being tenant-scoped (via
     * Store/UpdateCompetencyTypeRequest's own `Rule::in()` guard) -- the
     * EXISTING route-model-bound record's own tenant was never checked at
     * all. A user could submit their own valid `company_id` while
     * targeting another tenant's `CompetencyType` id in the URL, silently
     * overwriting or deleting cross-tenant master data. Same
     * `assertInCurrentTenant()` 404-not-403 pattern used throughout every
     * Workstream B/C/Acceleration-Mode controller.
     */
    private function assertInCurrentTenant(CompetencyType $competencyType): void
    {
        abort_unless(Company::query()->pluck('id')->contains($competencyType->company_id), 404);
    }
}
