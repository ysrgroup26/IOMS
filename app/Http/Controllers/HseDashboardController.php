<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\EmployeePpe;
use App\Models\Incident;
use App\Models\Project;
use App\Models\SafetyObservation;
use App\Services\DashboardStatsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HSE Department Dashboard (v1.10.0). Distinct from PPE's own dashboard
 * (`ppe.dashboard`, unchanged, still the detailed PPE-specific view) --
 * this is the broader department overview combining PPE + Incident
 * Management + (Milestone 4, Workstream B1) Safety Observation.
 * Deliberately does NOT include Safe Man Hours, Open PTW, Inspection Due,
 * Risk Assessment Status, Upcoming Safety Meeting, Training Due, or a
 * Calendar -- none of those have a backing data model yet.
 *
 * Milestone 4, Workstream B (tenant-isolation fix, found while extending
 * this controller to add the Safety Observation widget): every query
 * below queried Incident/Project/EmployeePpe/SafetyObservation with NO
 * company scoping at all -- the exact same bug class already fixed in
 * DashboardStatsService (a brand-new, company-less tenant's Dashboard
 * showing another tenant's data) and flagged separately for PpeController.
 * Fixed by reusing DashboardStatsService::resolveCompanyIds() (the same
 * TenantScope-safe, "empty company list -> whereIn matches zero rows"
 * helper), not a second copy of the same logic.
 */
class HseDashboardController extends Controller
{
    public function __construct(private readonly DashboardStatsService $dashboardStats) {}

    public function index(): Response
    {
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        $openIncidents = Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            ->whereIn('status', [Incident::STATUS_REPORTED, Incident::STATUS_INVESTIGATING]);
        // Incident.company_id is nullable (its own older migration
        // convention, see 2026_08_12_100038's doc comment) -- a null-company
        // incident has no tenant to leak across, so it's included for
        // every tenant rather than hidden from all of them. This does NOT
        // change behavior for any incident that already has a company_id
        // set; it only stops those from leaking cross-tenant, which is the
        // actual bug.

        return Inertia::render('Hse/Dashboard', [
            'activeProjectsCount' => Project::whereIn('company_id', $companyIds)->whereIn('status', ['planned', 'ongoing'])->count(),
            'openIncidentsCount' => (clone $openIncidents)->count(),
            'incidentsBySeverity' => (clone $openIncidents)
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity'),
            'ppeAlertCount' => EmployeePpe::query()->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))->effectiveStatus('expiring_soon')->count()
                + EmployeePpe::query()->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))->effectiveStatus('expired')->count(),
            'recentIncidents' => Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
                ->with('reporter:id,name')
                ->latest('incident_date')
                ->limit(5)
                ->get(['id', 'incident_number', 'title', 'severity', 'status', 'incident_date', 'reported_by', 'company_id']),
            'openSafetyObservationsCount' => SafetyObservation::whereIn('company_id', $companyIds)
                ->whereNotIn('status', [SafetyObservation::STATUS_CLOSED, SafetyObservation::STATUS_CANCELLED])
                ->count(),
            'recentSafetyObservations' => SafetyObservation::whereIn('company_id', $companyIds)
                ->with('reporter:id,name')
                ->latest('observed_at')
                ->limit(5)
                ->get(['id', 'observation_number', 'type', 'severity', 'status', 'observed_at', 'reported_by']),
            'recentActivity' => ActivityLog::whereIn('subject_type', [Incident::class, EmployeePpe::class, SafetyObservation::class])
                ->with('user:id,name')
                ->latest()
                ->limit(6)
                ->get(['id', 'user_id', 'description', 'created_at']),
        ]);
    }
}
