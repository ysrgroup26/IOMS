<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRosterPatternRequest;
use App\Http\Requests\UpdateRosterPatternRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\RosterPattern;
use Illuminate\Http\RedirectResponse;

/**
 * Milestone 4, Workstream A3. Rotation Pattern master CRUD -- surfaced
 * on Shifts/Master.jsx alongside Shift master data (ShiftController::master()).
 */
class RosterPatternController extends Controller
{
    public function store(StoreRosterPatternRequest $request): RedirectResponse
    {
        $pattern = RosterPattern::create($request->validated());

        ActivityLog::record('created', "Roster pattern \"{$pattern->name}\" was created.", $pattern);

        return back()->with('success', 'Roster pattern added.');
    }

    public function update(UpdateRosterPatternRequest $request, RosterPattern $rosterPattern): RedirectResponse
    {
        $this->assertInCurrentTenant($rosterPattern);

        $rosterPattern->update($request->validated());

        ActivityLog::record('updated', "Roster pattern \"{$rosterPattern->name}\" was updated.", $rosterPattern);

        return back()->with('success', 'Roster pattern updated.');
    }

    public function destroy(RosterPattern $rosterPattern): RedirectResponse
    {
        $this->authorize('delete', $rosterPattern);
        $this->assertInCurrentTenant($rosterPattern);

        if ($rosterPattern->rosters()->exists()) {
            return back()->with('error', 'Cannot delete a roster pattern that is in use by an employee roster.');
        }

        $name = $rosterPattern->name;
        $rosterPattern->delete();

        ActivityLog::record('deleted', "Roster pattern \"{$name}\" was removed.");

        return back()->with('success', 'Roster pattern removed.');
    }

    /** v1.10.5 security fix -- same gap and same fix as CompetencyTypeController::assertInCurrentTenant(), see its doc comment. */
    private function assertInCurrentTenant(RosterPattern $rosterPattern): void
    {
        abort_unless(Company::query()->pluck('id')->contains($rosterPattern->company_id), 404);
    }
}
