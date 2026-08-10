<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSafetyObservationRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\HazardCategory;
use App\Models\Project;
use App\Models\SafetyObservation;
use App\Models\SafetyObservationPhoto;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B1 (Safety Observation). Structurally mirrors
 * IncidentController -- same authorization gate shape
 * (canManageSafetyObservations()), same transition() endpoint pattern --
 * plus photo evidence (DailyReport's pattern) and the reusable
 * CorrectiveAction record created alongside an "Assigned" transition.
 */
class SafetyObservationController extends Controller
{
    public function index(Request $request): Response
    {
        // Tenant isolation -- SafetyObservation has no automatic
        // TenantScope (only Company does); explicit whereIn is what keeps
        // this list from showing every tenant's observations, same
        // principle as DashboardStatsService::resolveCompanyIds() and the
        // HseDashboardController fix in this same commit.
        $tenantCompanyIds = Company::query()->pluck('id');

        $observations = SafetyObservation::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'reporter:id,name', 'assignee:id,name', 'hazardCategory:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('observation_number', 'like', "%{$v}%")->orWhere('description', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->latest('observed_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('SafetyObservations/Index', [
            'observations' => $observations,
            'filters' => $request->only('search', 'status', 'type'),
            'can' => ['manage' => $request->user()->canManageSafetyObservations()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageSafetyObservations(), 403);

        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('SafetyObservations/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'hazardCategories' => HazardCategory::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name']),
            // "assigned_to" is set later via transition() (moving to
            // "Assigned"), not at report-time -- see Form.jsx, which
            // doesn't ask for it -- so no user list is needed here.
            'observationNumber' => SafetyObservation::generateObservationNumber(),
            'types' => SafetyObservation::TYPES,
            'severities' => SafetyObservation::SEVERITIES,
        ]);
    }

    public function store(StoreSafetyObservationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $photos = $request->file('photos', []);
        unset($data['photos']);

        $observation = DB::transaction(function () use ($data, $photos, $request) {
            // Starts directly in "open" (the first ACTIVE state), same
            // one-click-report UX as Incident's own create flow -- "draft"
            // is fully modeled and transition-guarded (see
            // SafetyObservation::$transitions) for a future save-as-draft
            // entry point (e.g. a mobile app), just not wired to this
            // web form, which mirrors Incident's existing UX exactly.
            $observation = SafetyObservation::create([
                ...$data,
                'observation_number' => SafetyObservation::generateObservationNumber(),
                'status' => SafetyObservation::STATUS_OPEN,
                'reported_by' => $request->user()->id,
            ]);

            foreach ($photos as $photo) {
                $path = $photo->store('uploads/safety-observations', 'public');
                $observation->photos()->create(['photo_path' => $path]);
            }

            return $observation;
        });

        ActivityLog::record('created', "Reported Safety Observation {$observation->observation_number}.", $observation);

        return redirect()->route('safety-observations.show', $observation)->with('flash', ['success' => 'Safety observation reported.']);
    }

    public function show(SafetyObservation $safetyObservation, Request $request): Response
    {
        $this->assertObservationInCurrentTenant($safetyObservation);

        $safetyObservation->load(
            'company:id,name', 'project:id,name', 'hazardCategory:id,name',
            'reporter:id,name', 'assignee:id,name', 'closer:id,name', 'photos', 'correctiveActions.assignee:id,name'
        );

        $activities = ActivityLog::where('subject_type', SafetyObservation::class)
            ->where('subject_id', $safetyObservation->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        $tenantId = app(CurrentTenant::class)->id();

        return Inertia::render('SafetyObservations/Show', [
            'observation' => $safetyObservation,
            'activities' => $activities,
            'canManage' => $request->user()->canManageSafetyObservations(),
            'users' => User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Single status-transition endpoint, same shape as
     * IncidentController::transition() -- optionally carries the extra
     * fields each specific transition needs (assigned_to + due_date when
     * moving to "assigned"; closure_notes when moving to "closed"), and
     * creates the reusable CorrectiveAction record the first time an
     * observation is assigned a responsible person.
     */
    public function transition(Request $request, SafetyObservation $safetyObservation): RedirectResponse
    {
        abort_unless($request->user()->canManageSafetyObservations(), 403);
        $this->assertObservationInCurrentTenant($safetyObservation);

        $tenantId = app(CurrentTenant::class)->id();
        $tenantUserIds = User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');

        $data = $request->validate([
            'status' => ['required', Rule::in([
                SafetyObservation::STATUS_ASSIGNED,
                SafetyObservation::STATUS_IN_PROGRESS,
                SafetyObservation::STATUS_PENDING_VERIFICATION,
                SafetyObservation::STATUS_CLOSED,
                SafetyObservation::STATUS_CANCELLED,
            ])],
            'assigned_to' => ['nullable', Rule::in($tenantUserIds)],
            'due_date' => ['nullable', 'date'],
            'closure_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($data, $safetyObservation, $request) {
                if ($data['status'] === SafetyObservation::STATUS_ASSIGNED) {
                    $safetyObservation->assigned_to = $data['assigned_to'] ?? $safetyObservation->assigned_to;
                    $safetyObservation->due_date = $data['due_date'] ?? $safetyObservation->due_date;
                    $safetyObservation->save();

                    if ($safetyObservation->assigned_to && ! $safetyObservation->correctiveActions()->exists()) {
                        $safetyObservation->correctiveActions()->create([
                            'company_id' => $safetyObservation->company_id,
                            'action' => $safetyObservation->immediate_action ?: 'Follow up on '.$safetyObservation->observation_number,
                            'assigned_to' => $safetyObservation->assigned_to,
                            'due_date' => $safetyObservation->due_date,
                            'status' => CorrectiveAction::STATUS_OPEN,
                            'created_by' => $request->user()->id,
                        ]);
                    }
                }

                if ($data['status'] === SafetyObservation::STATUS_CLOSED) {
                    $safetyObservation->closed_by = $request->user()->id;
                    $safetyObservation->closed_at = now();
                    $safetyObservation->closure_notes = $data['closure_notes'] ?? null;
                    $safetyObservation->save();

                    $safetyObservation->correctiveActions()
                        ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
                        ->update(['status' => CorrectiveAction::STATUS_VERIFIED, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
                }

                $safetyObservation->transitionTo($data['status'], $request->user());
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Safety observation '.str_replace('_', ' ', $data['status']).'.']);
    }

    public function destroyPhoto(SafetyObservation $safetyObservation, SafetyObservationPhoto $photo, Request $request): RedirectResponse
    {
        abort_unless($photo->safety_observation_id === $safetyObservation->id, 404);
        abort_unless($request->user()->canManageSafetyObservations(), 403);
        $this->assertObservationInCurrentTenant($safetyObservation);

        Storage::disk('public')->delete($photo->photo_path);
        $photo->delete();

        return back()->with('success', 'Photo removed.');
    }

    /**
     * Tenant ownership guard for route-model-bound SafetyObservation --
     * same 404-not-403 pattern as EmployeeCompetencyController's/
     * EmployeeShiftAssignmentController's own assertEmployeeInCurrentTenant()
     * (never confirms existence to a different tenant). SafetyObservation
     * has no automatic TenantScope (only Company does), so this is what
     * actually stops Tenant B from viewing/transitioning Tenant A's
     * observation by guessing/incrementing its id.
     */
    private function assertObservationInCurrentTenant(SafetyObservation $safetyObservation): void
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        abort_unless($tenantCompanyIds->contains($safetyObservation->company_id), 404);
    }
}
