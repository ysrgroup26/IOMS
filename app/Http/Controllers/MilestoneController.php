<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\NumberGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestones (v1.10.0). Deliberately a single Index page with inline
 * Add/Edit dialogs (matching Settings' Companies/Departments tabs), not
 * separate Create/Show pages -- a milestone is a few fields against an
 * already-existing Project, not substantial enough content to warrant
 * its own dedicated pages.
 *
 * v1.10.7 security fix (found during the cross-module integration audit,
 * not previously caught): every method here was completely unscoped by
 * tenant -- index() listed every tenant's projects/milestones, store()
 * validated project_id with a raw `exists:projects,id` (an IDOR: any
 * tenant could attach a milestone to another tenant's project), and
 * update()/destroy() had no ownership check on the route-bound Milestone
 * at all. Same tenant-scoped Rule::in() + assertInCurrentTenant() pattern
 * used everywhere else in this codebase, applied here for the first time.
 */
class MilestoneController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $milestones = Milestone::query()
            ->whereIn('project_id', $tenantProjectIds)
            ->with('project:id,name', 'creator:id,name')
            ->when($request->input('project_id'), fn ($q, $v) => $q->where('project_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('target_date')
            ->get();

        return Inertia::render('Milestones/Index', [
            'milestones' => $milestones,
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('project_id', 'status'),
            'can' => ['manage' => $request->user()->canManageMilestones()],
        ]);
    }

    public function store(Request $request, NumberGeneratorService $numberGenerator): RedirectResponse
    {
        abort_unless($request->user()->canManageMilestones(), 403);

        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'project_id' => ['required', Rule::in($tenantProjectIds)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'target_date' => ['required', 'date'],
            'status' => ['required', 'in:'.implode(',', Milestone::STATUSES)],
        ]);

        $milestone = Milestone::create([
            ...$data,
            'milestone_number' => $numberGenerator->generate('milestone'),
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Created milestone \"{$milestone->title}\".", $milestone);

        return back()->with('flash', ['success' => 'Milestone added.']);
    }

    public function update(Request $request, Milestone $milestone): RedirectResponse
    {
        abort_unless($request->user()->canManageMilestones(), 403);
        $this->assertInCurrentTenant($milestone);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'target_date' => ['required', 'date'],
            'status' => ['required', 'in:'.implode(',', Milestone::STATUSES)],
        ]);

        $milestone->update($data);

        ActivityLog::record('updated', "Updated milestone \"{$milestone->title}\".", $milestone);

        return back()->with('flash', ['success' => 'Milestone updated.']);
    }

    public function destroy(Request $request, Milestone $milestone): RedirectResponse
    {
        abort_unless($request->user()->canManageMilestones(), 403);
        $this->assertInCurrentTenant($milestone);

        $milestone->delete();

        return back()->with('flash', ['success' => 'Milestone removed.']);
    }

    private function assertInCurrentTenant(Milestone $milestone): void
    {
        $tenantProjectIds = Project::whereIn('company_id', Company::query()->pluck('id'))->pluck('id');
        abort_unless($tenantProjectIds->contains($milestone->project_id), 404);
    }
}
