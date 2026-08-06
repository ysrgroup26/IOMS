<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\NumberGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestones (v1.10.0). Deliberately a single Index page with inline
 * Add/Edit dialogs (matching Settings' Companies/Departments tabs), not
 * separate Create/Show pages -- a milestone is a few fields against an
 * already-existing Project, not substantial enough content to warrant
 * its own dedicated pages.
 */
class MilestoneController extends Controller
{
    public function index(Request $request): Response
    {
        $milestones = Milestone::query()
            ->with('project:id,name', 'creator:id,name')
            ->when($request->input('project_id'), fn ($q, $v) => $q->where('project_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('target_date')
            ->get();

        return Inertia::render('Milestones/Index', [
            'milestones' => $milestones,
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('project_id', 'status'),
            'can' => ['manage' => $request->user()->canManageMilestones()],
        ]);
    }

    public function store(Request $request, NumberGeneratorService $numberGenerator): RedirectResponse
    {
        abort_unless($request->user()->canManageMilestones(), 403);

        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
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

        $milestone->delete();

        return back()->with('flash', ['success' => 'Milestone removed.']);
    }
}
