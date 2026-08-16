<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\EmployeePpe;
use App\Models\Incident;
use App\Models\P3kBox;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\SafetyEquipment;
use App\Models\SafetyObservation;
use App\Models\WasteRecord;
use App\Models\WasteType;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HSE Department Dashboard (v1.10.0). Distinct from PPE's own dashboard
 * (`ppe.dashboard`, unchanged, still the detailed PPE-specific view) --
 * this is the broader department overview combining PPE + Incident
 * Management + (Milestone 4, Workstream B1) Safety Observation.
 * Milestone 4, Workstream B16/B17: Open PTW, Overdue Safety Equipment, and
 * Overdue P3K now have a real backing data model and are included below.
 * Deliberately still does NOT include Safe Man Hours, Upcoming Safety
 * Meeting attendance trends, Training Due, or a Calendar -- those need
 * data this codebase doesn't compute yet (man-hours tracking, training
 * expiry has its own existing `competency.expiring-soon` page instead of
 * being duplicated here).
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
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

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
            'openPermitsCount' => PermitToWork::whereIn('company_id', $companyIds)
                ->whereIn('status', [PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_ACTIVE])
                ->count(),
            'overdueSafetyEquipmentCount' => SafetyEquipment::whereIn('company_id', $companyIds)
                ->where('status', 'active')
                ->whereNotNull('next_inspection_due')
                ->whereDate('next_inspection_due', '<', now())
                ->count(),
            'overdueP3kCount' => P3kBox::whereIn('company_id', $companyIds)
                ->whereNotNull('next_inspection_due')
                ->whereDate('next_inspection_due', '<', now())
                ->count(),
            'openCapaCount' => CorrectiveAction::whereIn('company_id', $companyIds)
                ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
                ->count(),
            // v1.11.5 (Dashboard UX Completion, Phase 2) -- "Action
            // Required" panel needs actual rows, not just counts. Same
            // WHERE clauses as the counts above (already proven correct),
            // just also fetching the rows -- no new query logic.
            'actionRequired' => collect()
                ->merge(SafetyEquipment::whereIn('company_id', $companyIds)->where('status', 'active')
                    ->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<', now())
                    ->limit(5)->get(['id', 'name', 'next_inspection_due'])
                    ->map(fn (SafetyEquipment $e) => ['type' => 'Equipment Overdue', 'label' => $e->name, 'date' => $e->next_inspection_due, 'href' => route('hse.master').'?tab=equipment']))
                ->merge(P3kBox::whereIn('company_id', $companyIds)
                    ->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<', now())
                    ->limit(5)->get(['id', 'location', 'next_inspection_due'])
                    ->map(fn (P3kBox $b) => ['type' => 'P3K Overdue', 'label' => $b->location, 'date' => $b->next_inspection_due, 'href' => route('hse.master').'?tab=supplies']))
                ->merge(CorrectiveAction::whereIn('company_id', $companyIds)
                    ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())
                    ->limit(5)->get(['id', 'title', 'due_date'])
                    ->map(fn (CorrectiveAction $c) => ['type' => 'CAPA Overdue', 'label' => $c->title, 'date' => $c->due_date, 'href' => route('corrective-actions.index')]))
                ->sortBy('date')
                ->values()
                ->take(8),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'hse'),
            // v1.11.4 (HSE Waste Management, Part 20) -- compact summary
            // only, explicit instruction: "Do NOT turn it into another
            // huge card." Real counts, click-through to the full Waste
            // Management dashboard.
            'wasteSummary' => (function () use ($companyIds) {
                $records = WasteRecord::whereIn('company_id', $companyIds)
                    ->whereIn('status', WasteRecord::STORED_STATUSES)
                    ->with('wasteType:id,category')
                    ->get();

                return [
                    'b3_stored' => $records->filter(fn (WasteRecord $r) => $r->wasteType?->category === WasteType::CATEGORY_B3)->count(),
                    'non_b3_stored' => $records->filter(fn (WasteRecord $r) => $r->wasteType?->category === WasteType::CATEGORY_NON_B3)->count(),
                    'storage_alerts' => $records->filter(fn (WasteRecord $r) => $r->is_approaching_storage_limit || $r->is_storage_overdue)->count(),
                    'pending_disposal' => WasteRecord::whereIn('company_id', $companyIds)
                        ->whereIn('status', [WasteRecord::STATUS_SCHEDULED_PICKUP, WasteRecord::STATUS_IN_TRANSIT])
                        ->count(),
                ];
            })(),
        ]);
    }
}
