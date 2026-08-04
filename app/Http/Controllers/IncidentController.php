<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Incident;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(Request $request): Response
    {
        $incidents = Incident::query()
            ->with('project:id,name', 'reporter:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('incident_number', 'like', "%{$v}%")->orWhere('title', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->latest('incident_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Incidents/Index', [
            'incidents' => $incidents,
            'filters' => $request->only('search', 'status', 'severity'),
            'can' => ['manage' => $request->user()->canManageIncidents()],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Incidents/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'incidentNumber' => Incident::generateIncidentNumber(),
            'severities' => Incident::SEVERITIES,
            'categories' => Incident::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'incident_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'in:'.implode(',', Incident::SEVERITIES)],
            'category' => ['required', 'in:'.implode(',', Incident::CATEGORIES)],
            'company_id' => ['nullable', 'exists:companies,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
        ]);

        $incident = Incident::create([
            ...$data,
            'incident_number' => Incident::generateIncidentNumber(),
            'status' => Incident::STATUS_REPORTED,
            'reported_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Reported Incident {$incident->incident_number}.", $incident);

        return redirect()->route('incidents.show', $incident)->with('flash', ['success' => 'Incident reported.']);
    }

    public function show(Incident $incident, Request $request): Response
    {
        $incident->load('company:id,name', 'project:id,name', 'reporter:id,name');

        $activities = ActivityLog::where('subject_type', Incident::class)
            ->where('subject_id', $incident->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'activities' => $activities,
            'canManage' => $request->user()->canManageIncidents(),
        ]);
    }

    public function transition(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);

        $data = $request->validate([
            'status' => ['required', 'in:investigating,closed'],
        ]);

        try {
            $incident->transitionTo($data['status'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Incident '.$data['status'].'.']);
    }
}
