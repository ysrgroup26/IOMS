<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\IncidentInvestigation;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(Request $request): Response
    {
        // Tenant-isolation fix (Milestone 4, Workstream B14 -- found and
        // fixed while extending this controller for Investigation/CAPA;
        // previously flagged separately as a background task, now
        // resolved here since this file is directly in scope): every
        // query below had no company scoping at all. `company_id` is
        // nullable on `incidents` (that table's own older migration
        // convention -- see 2026_08_12_100038's doc comment), so a
        // null-company incident stays visible to every tenant (nothing to
        // leak), while a company-scoped incident now only shows to its
        // own tenant. Same reasoning already applied to
        // HseDashboardController's own fix.
        $tenantCompanyIds = Company::query()->pluck('id');

        $incidents = Incident::query()
            ->where(fn ($q) => $q->whereIn('company_id', $tenantCompanyIds)->orWhereNull('company_id'))
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
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('Incidents/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'incidentNumber' => Incident::generateIncidentNumber(),
            'severities' => Incident::SEVERITIES,
            'categories' => Incident::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);

        // IDOR fix (found alongside the tenant-scoping fix above): raw
        // `exists:companies,id`/`exists:projects,id` bypass Company's own
        // TenantScope entirely -- same principle as
        // StoreCompetencyTypeRequest's own doc comment. Replaced with
        // Rule::in() over tenant-scoped id collections.
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'incident_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'in:'.implode(',', Incident::SEVERITIES)],
            'category' => ['required', 'in:'.implode(',', Incident::CATEGORIES)],
            'company_id' => ['nullable', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
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
        $this->assertInCurrentTenant($incident);
        $incident->load('company:id,name', 'project:id,name', 'reporter:id,name', 'investigation.investigator:id,name', 'correctiveActions.assignee:id,name');

        $activities = ActivityLog::where('subject_type', Incident::class)
            ->where('subject_id', $incident->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        $tenantId = app(CurrentTenant::class)->id();

        return Inertia::render('Incidents/Show', [
            'incident' => $incident,
            'activities' => $activities,
            'canManage' => $request->user()->canManageIncidents(),
            'users' => User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->orderBy('name')->get(['id', 'name']),
            'investigationMethods' => IncidentInvestigation::METHODS,
        ]);
    }

    public function transition(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($incident);

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

    /** Milestone 4, Workstream B14 -- Investigation. Create-or-update, single record per incident. */
    public function storeInvestigation(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($incident);

        $data = $request->validate([
            'method' => ['required', Rule::in(IncidentInvestigation::METHODS)],
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'findings' => ['nullable', 'string', 'max:2000'],
            'recommendations' => ['nullable', 'string', 'max:2000'],
            'investigated_at' => ['nullable', 'date'],
        ]);

        $incident->investigation()->updateOrCreate(
            ['incident_id' => $incident->id],
            [...$data, 'company_id' => $incident->company_id, 'investigator_id' => $request->user()->id]
        );

        ActivityLog::record('updated', "Investigation recorded for {$incident->incident_number}.", $incident);

        return back()->with('success', 'Investigation saved.');
    }

    /** Milestone 4, Workstream B14/B15 -- reuses the existing polymorphic CorrectiveAction entity, same as HseInspection::raiseFinding(). */
    public function raiseFinding(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($incident);

        $tenantId = app(CurrentTenant::class)->id();
        $tenantUserIds = User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');

        $data = $request->validate([
            'action' => ['required', 'string', 'max:500'],
            'assigned_to' => ['nullable', Rule::in($tenantUserIds)],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(CorrectiveAction::PRIORITIES)],
        ]);

        $incident->correctiveActions()->create([
            ...$data,
            'company_id' => $incident->company_id,
            'status' => CorrectiveAction::STATUS_OPEN,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Raised a corrective action from {$incident->incident_number}.", $incident);

        return back()->with('success', 'Corrective action raised.');
    }

    /** Same 404-not-403 pattern used throughout HSE this workstream -- see SafetyObservationController's own doc comment. Incident.company_id is nullable, so a null-company incident is visible to every tenant (nothing to leak). */
    private function assertInCurrentTenant(Incident $incident): void
    {
        if ($incident->company_id === null) {
            return;
        }
        abort_unless(Company::query()->pluck('id')->contains($incident->company_id), 404);
    }
}
