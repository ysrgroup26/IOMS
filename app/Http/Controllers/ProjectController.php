<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddManpowerRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectManpower;
use App\Models\ProjectTimelineEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project is intentionally a SIMPLE grouping container (spec: "This is NOT
 * project management software"). CRUD is Super Admin + HSE (see
 * ProjectPolicy / User::canManageProjects()); viewing is open to all four
 * roles. Every future V2 module (Inspection, Gas Test, Permit, Daily
 * Report, Waste, etc.) can optionally attach to a project and write a
 * ProjectTimelineEvent row -- none of that is built yet, this controller
 * only writes a 'project_created' timeline event today.
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::query()
            ->with('company:id,name', 'creator:id,name')
            ->withCount('manpower')
            ->inCompany($request->input('company_id') ? (int) $request->input('company_id') : null)
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->search($request->input('search'))
            ->latest('created_at')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search', 'company_id', 'status'),
            'can' => ['manage' => $request->user()->canManageProjects()],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Project::class);

        return Inertia::render('Projects/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'project' => null,
        ]);
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $project = Project::create($data);

        ProjectTimelineEvent::record(
            $project->id,
            'project_created',
            'Project Created',
            "Project \"{$project->name}\" was created.",
            now(),
        );

        ActivityLog::record('created', "Project {$project->name} was created.", $project);

        return redirect()->route('projects.show', $project)->with('success', 'Project created successfully.');
    }

    public function show(Project $project): Response
    {
        // v1.11.7 tenant-isolation fix (Production Readiness Follow-Up,
        // Part 5): this had no authorization call at all -- ProjectPolicy
        // already defined view(), it just wasn't invoked here, so any
        // authenticated user could load ANY tenant's project (manpower,
        // timeline, company) by guessing an id.
        $this->authorize('view', $project);

        $project->load('company', 'creator:id,name');

        $manpowerGrouped = $project->manpowerGroupedByDepartment()
            ->map(fn ($employees, $deptName) => [
                'department' => $deptName,
                'employees' => $employees->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'employee_id' => $e->employee_id,
                    'full_name' => $e->full_name,
                ])->values(),
            ])
            ->values();

        $timeline = $project->timelineEvents()->with('creator:id,name')->get()->map(fn (ProjectTimelineEvent $e) => [
            'id' => $e->id,
            'event_type' => $e->event_type,
            'title' => $e->title,
            'description' => $e->description,
            'event_date' => $e->event_date->format('d M'),
            'event_date_full' => $e->event_date->format('d M Y'),
            'created_by' => $e->creator?->name,
        ]);

        // Employees not yet assigned to this project, for the "add manpower" picker,
        // scoped to the project's own company and grouped by department.
        // Columns fully-qualified: orderedForDisplay() joins departments
        // (has company_id) and positions (has its own id) -- unqualified
        // references here would be ambiguous once combined with that join.
        $availableEmployees = Employee::query()
            ->active()
            ->where('employees.company_id', $project->company_id)
            ->whereNotIn('employees.id', $project->employees()->pluck('employees.id'))
            ->with('department:id,name')
            ->orderedForDisplay()
            ->get(['employees.id', 'employees.employee_id', 'employees.full_name', 'employees.department_id']);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'manpowerGrouped' => $manpowerGrouped,
            'timeline' => $timeline,
            'availableEmployees' => $availableEmployees,
            'can' => ['manage' => request()->user()->canManageProjects()],
        ]);
    }

    public function edit(Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('Projects/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'project' => $project,
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        ActivityLog::record('updated', "Project {$project->name} was updated.", $project);

        return redirect()->route('projects.show', $project)->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $name = $project->name;
        $project->delete();

        ActivityLog::record('deleted', "Project {$name} was removed.", $project);

        return redirect()->route('projects.index')->with('success', 'Project removed.');
    }

    /**
     * Adds one or more employees (chosen from Employee Master, never typed)
     * to a project's manpower list, and writes a timeline event.
     */
    public function addManpower(AddManpowerRequest $request, Project $project): RedirectResponse
    {
        $this->authorize('manageManpower', $project);

        $employeeIds = $request->validated()['employee_ids'];
        $added = 0;

        foreach ($employeeIds as $employeeId) {
            $manpower = ProjectManpower::firstOrCreate(
                ['project_id' => $project->id, 'employee_id' => $employeeId],
                ['assigned_date' => now(), 'added_by' => $request->user()->id]
            );
            if ($manpower->wasRecentlyCreated) {
                $added++;
            }
        }

        if ($added > 0) {
            ProjectTimelineEvent::record(
                $project->id,
                'manpower_assigned',
                'Manpower Assigned',
                "{$added} employee(s) added to project manpower.",
                now(),
            );
        }

        return back()->with('success', "{$added} employee(s) added to the project.");
    }

    public function removeManpower(Project $project, Employee $employee): RedirectResponse
    {
        $this->authorize('manageManpower', $project);

        ProjectManpower::where('project_id', $project->id)->where('employee_id', $employee->id)->delete();

        return back()->with('success', "{$employee->full_name} removed from the project.");
    }

    /**
     * Milestone 4, Acceleration Part 3 -- Project Activities. A dedicated
     * page rather than a card bolted onto the existing (already dense)
     * Show.jsx -- keeps that page's own contract untouched.
     */
    public function activities(Project $project): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        abort_unless($tenantCompanyIds->contains($project->company_id), 404);

        $project->load(['activities.assignedEmployee:id,full_name']);

        return Inertia::render('Projects/Activities', [
            'project' => $project->only('id', 'name', 'project_code'),
            'activities' => $project->activities,
            'employees' => Employee::where('company_id', $project->company_id)->active()->orderBy('full_name')->get(['id', 'full_name']),
            'statuses' => \App\Models\ProjectActivity::STATUSES,
            'can' => ['manage' => request()->user()->canManageProjects()],
        ]);
    }
}
