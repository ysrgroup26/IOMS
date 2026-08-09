<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeShiftAssignmentRequest;
use App\Http\Requests\UpdateEmployeeShiftAssignmentRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Http\RedirectResponse;

/**
 * Milestone 4, Workstream A3. Per-employee shift assignment history --
 * surfaced on the Employee Profile page, same shape as
 * EmployeeCompetencyController.
 *
 * IDOR guard: same as EmployeeCompetencyController -- see that class's
 * own doc comment for why every method here asserts tenant ownership
 * explicitly rather than trusting Employee route-model-binding alone
 * (which has no tenant check anywhere in this codebase, a separately
 * flagged, broader pre-existing gap).
 */
class EmployeeShiftAssignmentController extends Controller
{
    public function store(StoreEmployeeShiftAssignmentRequest $request, Employee $employee): RedirectResponse
    {
        $this->assertEmployeeInCurrentTenant($employee);

        $data = $request->validated();
        $data['employee_id'] = $employee->id;
        $data['created_by'] = $request->user()->id;

        $assignment = EmployeeShiftAssignment::create($data);

        ActivityLog::record('created', "Shift \"{$assignment->shift->name}\" assigned to {$employee->full_name}.", $assignment);

        return back()->with('success', 'Shift assignment added.');
    }

    public function update(UpdateEmployeeShiftAssignmentRequest $request, EmployeeShiftAssignment $employeeShiftAssignment): RedirectResponse
    {
        $this->assertEmployeeInCurrentTenant($employeeShiftAssignment->employee);

        $employeeShiftAssignment->update($request->validated());

        ActivityLog::record('updated', "Shift assignment for {$employeeShiftAssignment->employee->full_name} was updated.", $employeeShiftAssignment);

        return back()->with('success', 'Shift assignment updated.');
    }

    public function destroy(EmployeeShiftAssignment $employeeShiftAssignment): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->assertEmployeeInCurrentTenant($employeeShiftAssignment->employee);

        $employeeName = $employeeShiftAssignment->employee->full_name;
        $employeeShiftAssignment->delete();

        ActivityLog::record('deleted', "Shift assignment removed from {$employeeName}.");

        return back()->with('success', 'Shift assignment removed.');
    }

    private function assertEmployeeInCurrentTenant(Employee $employee): void
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        abort_unless($tenantCompanyIds->contains($employee->company_id), 404);
    }
}
