<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShiftRequest;
use App\Http\Requests\UpdateShiftRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\RosterPattern;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream A3 (Shift & Roster Management). Shift + Roster
 * Pattern master data -- one setup page, mirrors Competency Master's
 * shape (CompetencyTypeController + CompetencyController::master()).
 * Every query scoped through Company::query() (TenantScope-safe), never
 * a raw company_id-or-nothing filter.
 */
class ShiftController extends Controller
{
    public function master(): Response
    {
        $companyIds = Company::query()->pluck('id');

        return Inertia::render('Shifts/Master', [
            'shifts' => Shift::whereIn('company_id', $companyIds)
                ->withCount('shiftAssignments')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'rosterPatterns' => RosterPattern::whereIn('company_id', $companyIds)
                ->withCount('rosters')
                ->orderBy('name')
                ->get(),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => request()->user()->isAdmin()],
        ]);
    }

    public function store(StoreShiftRequest $request): RedirectResponse
    {
        $shift = Shift::create($request->validated());

        ActivityLog::record('created', "Shift \"{$shift->name}\" was created.", $shift);

        return back()->with('success', 'Shift added.');
    }

    public function update(UpdateShiftRequest $request, Shift $shift): RedirectResponse
    {
        $this->assertInCurrentTenant($shift);

        $shift->update($request->validated());

        ActivityLog::record('updated', "Shift \"{$shift->name}\" was updated.", $shift);

        return back()->with('success', 'Shift updated.');
    }

    public function destroy(Shift $shift): RedirectResponse
    {
        $this->authorize('delete', $shift);
        $this->assertInCurrentTenant($shift);

        if ($shift->shiftAssignments()->exists() || $shift->rosters()->exists()) {
            return back()->with('error', 'Cannot delete a shift that is assigned to employees or rosters.');
        }

        $name = $shift->name;
        $shift->delete();

        ActivityLog::record('deleted', "Shift \"{$name}\" was removed.");

        return back()->with('success', 'Shift removed.');
    }

    /** v1.10.5 security fix -- same gap and same fix as CompetencyTypeController::assertInCurrentTenant(), see its doc comment. */
    private function assertInCurrentTenant(Shift $shift): void
    {
        abort_unless(Company::query()->pluck('id')->contains($shift->company_id), 404);
    }
}
