<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Employee;
use App\Models\Incident;
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
            // v2.73.0 -- the initial report's own vocabularies.
            'personTypes' => Incident::PERSON_TYPES,
            'injurySeverities' => Incident::INJURY_SEVERITIES,
            'injurySeverityLabels' => Incident::INJURY_SEVERITY_LABELS,
            // So the reporter can name the injured person from the
            // workforce rather than retyping them, which is both faster
            // and the only way the record links to an employee file.
            'employees' => Employee::whereIn('company_id', $tenantCompanyIds)
                ->active()
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'company_id']),
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

        // v2.73.0: the injured party, where they are on the workforce.
        // Rule::in over this tenant's own employee ids -- IDOR-safe by
        // construction, same technique as the company/project rules above.
        $tenantEmployeeIds = Employee::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        /*
         * WHAT IS REQUIRED HERE IS THE POINT OF THE WHOLE FORM.
         *
         * Only title, date, severity and category are mandatory -- the
         * four things somebody genuinely knows in the first minute. Every
         * 5W1H field below is nullable, deliberately: an initial report is
         * filed while an ambulance is still on site, and a form that
         * refuses to save until the medical facility is known is a form
         * that gets filled in tomorrow from memory, which defeats its
         * entire purpose.
         *
         * The form ASKS for all of it. The schema does not insist.
         */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'incident_date' => ['required', 'date'],
            'incident_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'work_area' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', 'in:'.implode(',', Incident::SEVERITIES)],
            'category' => ['required', 'in:'.implode(',', Incident::CATEGORIES)],
            'company_id' => ['nullable', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],

            // WHO
            'injured_employee_id' => ['nullable', Rule::in($tenantEmployeeIds)],
            'injured_person_name' => ['nullable', 'string', 'max:255'],
            'injured_person_type' => ['nullable', Rule::in(Incident::PERSON_TYPES)],
            'injured_person_job_title' => ['nullable', 'string', 'max:255'],
            'injured_person_id_number' => ['nullable', 'string', 'max:100'],

            // WHAT (the injury)
            'injury_type' => ['nullable', 'string', 'max:255'],
            'body_part' => ['nullable', 'string', 'max:255'],
            'injury_severity' => ['nullable', Rule::in(Incident::INJURY_SEVERITIES)],
            'people_injured' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // The immediate response
            'immediate_treatment' => ['nullable', 'string', 'max:2000'],
            'medical_facility' => ['nullable', 'string', 'max:255'],
            'referred_to_facility' => ['nullable', 'boolean'],

            // HOW / WHY, as known at the time
            'chronology' => ['nullable', 'string', 'max:5000'],
            'initial_circumstances' => ['nullable', 'string', 'max:2000'],
            'immediate_actions' => ['nullable', 'string', 'max:2000'],

            'witnesses' => ['nullable', 'array', 'max:20'],
            'witnesses.*.name' => ['nullable', 'string', 'max:255'],
            'witnesses.*.contact' => ['nullable', 'string', 'max:255'],
            'witnesses.*.note' => ['nullable', 'string', 'max:500'],

            // Employment-injury documentation. A flag and a reference:
            // IOMS records THAT a claim is in play so the incident can be
            // found from it. It does not generate or submit one.
            'work_related' => ['nullable', 'boolean'],
            'reportable_to_authority' => ['nullable', 'boolean'],
            'employment_injury_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $incident = Incident::create([
            ...$data,
            // Dropped rather than persisted when the reporter left them
            // blank: an empty witness row is not a witness.
            'witnesses' => collect($data['witnesses'] ?? [])
                ->filter(fn ($w) => filled($w['name'] ?? null))
                ->values()
                ->all() ?: null,
            'incident_number' => Incident::generateIncidentNumber(),
            'status' => Incident::STATUS_REPORTED,
            'reported_by' => $request->user()->id,
            // WHEN IT WAS REPORTED, which is not when it happened. Taken
            // from the server clock, never from the client: the gap
            // between event and report is itself a reportable fact and
            // must not be something a form can understate.
            'reported_at' => now(),
        ]);

        ActivityLog::record('created', "Reported Incident {$incident->incident_number}.", $incident);

        return redirect()->route('incidents.show', $incident)->with('flash', ['success' => 'Incident reported.']);
    }

    public function show(Incident $incident, Request $request): Response
    {
        $this->assertInCurrentTenant($incident);
        $incident->load(
            'company:id,name', 'project:id,name', 'reporter:id,name',
            'injuredEmployee:id,full_name',
            // Loaded to LINK to, not to edit. Since v2.73.0 the
            // investigation is worked on its own page; this page shows
            // that one exists, what state it is in, and offers a way
            // through to it.
            'investigation:id,incident_id,investigation_number,status,investigator_id,started_at',
            'investigation.investigator:id,name',
            'correctiveActions.assignee:id,name'
        );

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
            'users' => User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'injurySeverityLabels' => Incident::INJURY_SEVERITY_LABELS,
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

    /*
     * v2.73.0 -- `storeInvestigation()` WAS REMOVED, not deprecated.
     *
     * It was a create-or-update that wrote root cause, findings and
     * recommendations straight onto an incident from a card on the
     * incident page. That endpoint IS the architecture this release
     * corrects: it let an investigation be filed as a by-product of
     * reading a report, by whoever happened to be looking at it, with no
     * state, no team, no evidence and no review.
     *
     * Its replacement is IncidentInvestigationController -- a workspace
     * with its own routes. Opening one is
     * POST /incidents/{incident}/investigations; everything after that
     * happens on the investigation's own page.
     *
     * Leaving a redirecting shim here was considered and rejected: the
     * old endpoint's whole payload (a root cause, typed once, with no
     * analysis behind it) has nowhere sensible to land in the new model,
     * and silently accepting it would reintroduce exactly the shortcut.
     */

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
