<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompetencyTypeRequest;
use App\Http\Requests\UpdateCompetencyTypeRequest;
use App\Models\ActivityLog;
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

        if ($competencyType->employeeCompetencies()->exists()) {
            return back()->with('error', 'Cannot delete a competency type that has employee records against it.');
        }

        $name = $competencyType->name;
        $competencyType->requiredByPositions()->detach();
        $competencyType->delete();

        ActivityLog::record('deleted', "Competency type \"{$name}\" was removed.");

        return back()->with('success', 'Competency type removed.');
    }
}
