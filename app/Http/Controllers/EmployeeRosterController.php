<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRosterRequest;
use App\Http\Requests\UpdateEmployeeRosterRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeRoster;
use Illuminate\Http\RedirectResponse;

/**
 * Milestone 4, Workstream A3. Per-employee roster entries -- surfaced on
 * the Employee Profile page. Same IDOR-guard discipline as
 * EmployeeCompetencyController/EmployeeShiftAssignmentController.
 */
class EmployeeRosterController extends Controller
{
    public function store(StoreEmployeeRosterRequest $request, Employee $employee): RedirectResponse
    {
        $this->assertEmployeeInCurrentTenant($employee);

        $data = $request->validated();
        $data['employee_id'] = $employee->id;
        $data['created_by'] = $request->user()->id;

        $roster = EmployeeRoster::create($data);

        ActivityLog::record('created', "Roster entry added for {$employee->full_name}.", $roster);

        return back()->with('success', 'Roster entry added.');
    }

    public function update(UpdateEmployeeRosterRequest $request, EmployeeRoster $employeeRoster): RedirectResponse
    {
        $this->assertEmployeeInCurrentTenant($employeeRoster->employee);

        $employeeRoster->update($request->validated());

        ActivityLog::record('updated', "Roster entry for {$employeeRoster->employee->full_name} was updated.", $employeeRoster);

        return back()->with('success', 'Roster entry updated.');
    }

    public function destroy(EmployeeRoster $employeeRoster): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->assertEmployeeInCurrentTenant($employeeRoster->employee);

        $employeeName = $employeeRoster->employee->full_name;
        $employeeRoster->delete();

        ActivityLog::record('deleted', "Roster entry removed from {$employeeName}.");

        return back()->with('success', 'Roster entry removed.');
    }

    private function assertEmployeeInCurrentTenant(Employee $employee): void
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        abort_unless($tenantCompanyIds->contains($employee->company_id), 404);
    }
}
