<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IncidentInvestigation;
use App\Models\InvestigationInterview;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.73.0 -- HSE INVESTIGATION, as its own workspace.
 *
 * Before this release there was no such controller. An investigation was
 * one POST on IncidentController (`storeInvestigation`), reachable only
 * from a card on the incident page, with no index, no show, no state and
 * no way to ask "what are we still investigating". That absence WAS the
 * architecture: investigation was modelled as extra fields on a report.
 *
 * See `docs/ADR/036-incident-report-versus-investigation.md`.
 *
 * WHAT THIS CONTROLLER DOES NOT DO: it does not let anybody edit the
 * initial report. The report is the factual source record, filed by
 * whoever was at the scene, and an investigator revising it would destroy
 * the very thing they are working from. An investigation that disagrees
 * with the report says so in `detailed_chronology` and `findings`, where
 * the disagreement is visible and attributable.
 *
 * AUTHORIZATION reuses `canManageIncidents()` -- the existing HSE-domain
 * gate that already governs incidents, unchanged. This release
 * deliberately introduces no new permission: a separate workspace is not
 * a separate authority, and inventing an `canInvestigate()` here would
 * fork the HSE permission model for no stated reason. If investigator
 * competency needs to be gated separately later, that is a product
 * decision with its own ADR, not a side effect of splitting two screens.
 */
class IncidentInvestigationController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $openStatuses = [
            IncidentInvestigation::STATUS_DRAFT,
            IncidentInvestigation::STATUS_IN_PROGRESS,
            IncidentInvestigation::STATUS_UNDER_REVIEW,
        ];

        // Counted over the tenant, NOT over the current page. A summary
        // that shrinks as you paginate is worse than no summary, and
        // deriving it from the paginator's own collection is the easy way
        // to get exactly that.
        $openCount = IncidentInvestigation::query()
            ->where(fn ($q) => $q->whereIn('company_id', $tenantCompanyIds)->orWhereNull('company_id'))
            ->whereIn('status', $openStatuses)
            ->count();

        $investigations = IncidentInvestigation::query()
            // Same nullable-company reasoning as IncidentController::index():
            // `company_id` is nullable here because the parent incidents
            // table made it nullable first, so a null-company record has
            // nothing to leak and stays visible.
            ->where(fn ($q) => $q->whereIn('company_id', $tenantCompanyIds)->orWhereNull('company_id'))
            ->with([
                'incident:id,incident_number,title,incident_date,severity,injury_severity',
                'investigator:id,name',
            ])
            ->withCount([
                'correctiveActions as open_actions_count' => fn ($q) => $q
                    ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED]),
            ])
            ->when($request->input('search'), fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('investigation_number', 'like', "%{$v}%")
                ->orWhereHas('incident', fn ($i) => $i->where('incident_number', 'like', "%{$v}%")->orWhere('title', 'like', "%{$v}%"))))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('method'), fn ($q, $v) => $q->where('method', $v))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Investigations/Index', [
            'investigations' => $investigations,
            'filters' => $request->only('search', 'status', 'method'),
            'statuses' => [
                IncidentInvestigation::STATUS_DRAFT,
                IncidentInvestigation::STATUS_IN_PROGRESS,
                IncidentInvestigation::STATUS_UNDER_REVIEW,
                IncidentInvestigation::STATUS_COMPLETED,
                IncidentInvestigation::STATUS_CLOSED,
                IncidentInvestigation::STATUS_CANCELLED,
            ],
            'methodLabels' => IncidentInvestigation::METHOD_LABELS,
            // The one number an HSE lead actually opens this page for.
            'summary' => ['open' => $openCount],
            'can' => ['manage' => $request->user()->canManageIncidents()],
        ]);
    }

    /**
     * Opening an investigation is an act, not a form.
     *
     * It takes one decision -- which report, and who leads it -- and
     * everything else is filled in over the following days on the show
     * page. A create form that demanded scope, team and target date up
     * front would be answered with placeholders, because none of them are
     * known in the first hour.
     */
    public function store(Request $request, Incident $incident): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertIncidentInCurrentTenant($incident);

        if ($incident->investigation()->exists()) {
            throw ValidationException::withMessages([
                'incident_id' => 'This incident already has an investigation. Open it instead of starting a second one.',
            ]);
        }

        $tenantUserIds = $this->tenantUserIds();

        $data = $request->validate([
            'investigator_id' => ['required', Rule::in($tenantUserIds)],
            'scope' => ['nullable', 'string', 'max:2000'],
            'target_completion_date' => ['nullable', 'date'],
        ]);

        $investigation = $incident->investigation()->create([
            ...$data,
            'investigation_number' => IncidentInvestigation::generateInvestigationNumber($incident->company_id),
            'company_id' => $incident->company_id,
            'status' => IncidentInvestigation::STATUS_DRAFT,
            'method' => 'none',
            'started_at' => now()->toDateString(),
        ]);

        // The incident's own workflow moves in step, because "an
        // investigation was opened" is exactly what `investigating` has
        // always meant on that record. Guarded, because an incident that
        // was already closed for reporting purposes can still be
        // investigated and must not be dragged backwards.
        if ($incident->status === Incident::STATUS_REPORTED) {
            $incident->transitionTo(Incident::STATUS_INVESTIGATING, $request->user());
        }

        ActivityLog::record(
            'created',
            "Opened investigation {$investigation->investigation_number} for {$incident->incident_number}.",
            $investigation
        );

        return redirect()
            ->route('investigations.show', $investigation)
            ->with('success', "Investigation {$investigation->investigation_number} opened.");
    }

    public function show(IncidentInvestigation $investigation, Request $request): Response
    {
        $this->assertInCurrentTenant($investigation);

        $investigation->load([
            'incident.project:id,name',
            'incident.reporter:id,name',
            'incident.injuredEmployee:id,full_name',
            'incident.company:id,name',
            'investigator:id,name',
            'reviewer:id,name',
            'closer:id,name',
            'interviews.interviewer:id,name',
            'interviews.employee:id,full_name',
            'correctiveActions.assignee:id,name',
        ]);

        return Inertia::render('Investigations/Show', [
            'investigation' => $investigation,
            'activities' => ActivityLog::where('subject_type', IncidentInvestigation::class)
                ->where('subject_id', $investigation->id)
                ->with('user:id,name')
                ->latest()
                ->get(),
            'methodLabels' => IncidentInvestigation::METHOD_LABELS,
            'relationships' => InvestigationInterview::RELATIONSHIPS,
            'priorities' => CorrectiveAction::PRIORITIES,
            'users' => User::query()
                ->when(app(CurrentTenant::class)->id(), fn ($q, $t) => $q->where('tenant_id', $t))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'employees' => Employee::whereIn('company_id', Company::query()->pluck('id'))
                ->active()
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            // Advisory, not enforced -- see IncidentInvestigation::canBeClosedCleanly().
            'openActionCount' => $investigation->openCorrectiveActionCount(),
            'can' => ['manage' => $request->user()->canManageIncidents()],
        ]);
    }

    /**
     * One update endpoint for the whole investigation body.
     *
     * The show page IS the working surface -- an investigator adds to
     * scope, chronology, causes and conclusion over days, in any order --
     * so splitting this into a field-per-endpoint would produce a dozen
     * routes that all do the same thing. Every field is nullable and
     * saving a half-finished analysis is the normal case.
     */
    public function update(Request $request, IncidentInvestigation $investigation): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($investigation);

        $tenantUserIds = $this->tenantUserIds();

        $data = $request->validate([
            'investigator_id' => ['nullable', Rule::in($tenantUserIds)],
            'team' => ['nullable', 'array'],
            'team.*' => [Rule::in($tenantUserIds)],
            'scope' => ['nullable', 'string', 'max:2000'],
            'target_completion_date' => ['nullable', 'date'],
            'investigated_at' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(IncidentInvestigation::METHODS)],
            // Shape is decided by `method`; validated as a structure, not
            // a schema, because a fishbone and a 5-Why chain have nothing
            // in common beyond both being JSON.
            'analysis' => ['nullable', 'array'],
            'detailed_chronology' => ['nullable', 'string', 'max:10000'],
            'immediate_causes' => ['nullable', 'string', 'max:4000'],
            'basic_causes' => ['nullable', 'string', 'max:4000'],
            'root_cause' => ['nullable', 'string', 'max:4000'],
            'contributing_factors' => ['nullable', 'string', 'max:4000'],
            'findings' => ['nullable', 'string', 'max:10000'],
            'recommendations' => ['nullable', 'string', 'max:10000'],
            'conclusion' => ['nullable', 'string', 'max:10000'],
        ]);

        $investigation->update($data);

        ActivityLog::record('updated', "Updated investigation {$investigation->investigation_number}.", $investigation);

        return back()->with('success', 'Investigation saved.');
    }

    /**
     * Workflow transitions, through the same engine every other module
     * uses. The reviewer/closer stamps are written here, server-side, and
     * are not settable from any form -- the same discipline the PTW
     * approval stamp runs on (v2.72.0).
     */
    public function transition(Request $request, IncidentInvestigation $investigation): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($investigation);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                IncidentInvestigation::STATUS_IN_PROGRESS,
                IncidentInvestigation::STATUS_UNDER_REVIEW,
                IncidentInvestigation::STATUS_COMPLETED,
                IncidentInvestigation::STATUS_CLOSED,
                IncidentInvestigation::STATUS_CANCELLED,
            ])],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            if ($data['status'] === IncidentInvestigation::STATUS_COMPLETED) {
                $investigation->reviewed_by = $request->user()->id;
                $investigation->reviewed_at = now();
                $investigation->save();
            }

            if ($data['status'] === IncidentInvestigation::STATUS_CLOSED) {
                $investigation->closed_by = $request->user()->id;
                $investigation->closed_at = now();
                $investigation->save();
            }

            $investigation->transitionTo(
                $data['status'],
                $request->user(),
                meta: ['comments' => $data['comments'] ?? null]
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        // Closing the investigation closes the incident's own reporting
        // lifecycle with it. The incident has no further state of its own
        // once the work it triggered is finished, and leaving it sitting
        // at `investigating` forever is how the incident list stops being
        // believed.
        if ($data['status'] === IncidentInvestigation::STATUS_CLOSED) {
            $incident = $investigation->incident;
            if ($incident && $incident->canTransitionTo(Incident::STATUS_CLOSED)) {
                $incident->transitionTo(Incident::STATUS_CLOSED, $request->user());
            }
        }

        return back()->with('success', 'Investigation status updated.');
    }

    /** Add an interview record. See InvestigationInterview for why these are rows. */
    public function storeInterview(Request $request, IncidentInvestigation $investigation): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($investigation);

        $tenantEmployeeIds = Employee::whereIn('company_id', Company::query()->pluck('id'))->pluck('id');

        $data = $request->validate([
            // IDOR-safe by construction: Rule::in over this tenant's own
            // employee ids rejects anything else regardless of what is
            // posted. Same technique StorePermitToWorkRequest uses.
            'employee_id' => ['nullable', Rule::in($tenantEmployeeIds)],
            'person_name' => ['required', 'string', 'max:255'],
            'person_role' => ['nullable', 'string', 'max:255'],
            'relationship' => ['nullable', Rule::in(InvestigationInterview::RELATIONSHIPS)],
            'interviewed_on' => ['nullable', 'date'],
            'statement' => ['nullable', 'string', 'max:10000'],
            'investigator_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $investigation->interviews()->create([
            ...$data,
            'company_id' => $investigation->company_id,
            'interviewed_by' => $request->user()->id,
        ]);

        ActivityLog::record(
            'created',
            "Recorded an interview with {$data['person_name']} on {$investigation->investigation_number}.",
            $investigation
        );

        return back()->with('success', 'Interview recorded.');
    }

    public function destroyInterview(Request $request, IncidentInvestigation $investigation, InvestigationInterview $interview): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($investigation);

        // Nested-resource guard: an interview id from another
        // investigation must not be removable through this one's URL.
        abort_unless($interview->incident_investigation_id === $investigation->id, 404);

        $name = $interview->person_name;
        $interview->delete();

        ActivityLog::record('deleted', "Removed the interview record for {$name}.", $investigation);

        return back()->with('success', 'Interview removed.');
    }

    /**
     * CAPA raised FROM the analysis that found the cause.
     *
     * Same polymorphic CorrectiveAction entity Incident, Safety
     * Observation and HSE Inspection already use -- no second CAPA
     * system, which was explicitly ruled out.
     */
    public function raiseAction(Request $request, IncidentInvestigation $investigation): RedirectResponse
    {
        abort_unless($request->user()->canManageIncidents(), 403);
        $this->assertInCurrentTenant($investigation);

        $data = $request->validate([
            'action' => ['required', 'string', 'max:500'],
            'assigned_to' => ['nullable', Rule::in($this->tenantUserIds())],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(CorrectiveAction::PRIORITIES)],
        ]);

        $investigation->correctiveActions()->create([
            ...$data,
            'company_id' => $investigation->company_id,
            'status' => CorrectiveAction::STATUS_OPEN,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record(
            'created',
            "Raised a corrective action from {$investigation->investigation_number}.",
            $investigation
        );

        return back()->with('success', 'Corrective action raised.');
    }

    private function tenantUserIds()
    {
        $tenantId = app(CurrentTenant::class)->id();

        return User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');
    }

    /**
     * Same 404-not-403 pattern used throughout HSE -- see
     * SafetyObservationController's own doc comment. `company_id` is
     * nullable here only because the parent incidents table made it
     * nullable first, so a null-company record has nothing to leak.
     */
    private function assertInCurrentTenant(IncidentInvestigation $investigation): void
    {
        if ($investigation->company_id === null) {
            return;
        }

        abort_unless(Company::query()->pluck('id')->contains($investigation->company_id), 404);
    }

    private function assertIncidentInCurrentTenant(Incident $incident): void
    {
        if ($incident->company_id === null) {
            return;
        }

        abort_unless(Company::query()->pluck('id')->contains($incident->company_id), 404);
    }
}
