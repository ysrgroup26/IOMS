<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Milestone 4, Acceleration Part 3 (Project Management). See ProjectActivity's own doc comment for how this differs from DailyReportActivity. */
class ProjectActivityController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->assertInCurrentTenant($project);

        $tenantEmployeeIds = Employee::where('company_id', $project->company_id)->pluck('id');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'assigned_employee_id' => ['nullable', Rule::in($tenantEmployeeIds)],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(ProjectActivity::STATUSES)],
        ]);

        $activity = $project->activities()->create($data);
        ActivityLog::record('created', "Project activity \"{$activity->name}\" was added.", $project);

        return back()->with('success', 'Activity added.');
    }

    public function update(Request $request, Project $project, ProjectActivity $activity): RedirectResponse
    {
        $this->assertInCurrentTenant($project);
        abort_unless($activity->project_id === $project->id, 404);

        $tenantEmployeeIds = Employee::where('company_id', $project->company_id)->pluck('id');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'assigned_employee_id' => ['nullable', Rule::in($tenantEmployeeIds)],
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'status' => ['required', Rule::in(ProjectActivity::STATUSES)],
        ]);

        $activity->update($data);
        ActivityLog::record('updated', "Project activity \"{$activity->name}\" was updated.", $project);

        return back()->with('success', 'Activity updated.');
    }

    public function destroy(Project $project, ProjectActivity $activity): RedirectResponse
    {
        $this->assertInCurrentTenant($project);
        abort_unless($activity->project_id === $project->id, 404);

        $activity->delete();

        return back()->with('success', 'Activity removed.');
    }

    private function assertInCurrentTenant(Project $project): void
    {
        abort_unless(Company::query()->pluck('id')->contains($project->company_id), 404);
    }
}
