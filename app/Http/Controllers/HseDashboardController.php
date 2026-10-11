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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function index(Request $request): Response
    {
        /*
         * v2.109.0 -- THE OVERVIEW CAN NOW BE NARROWED TO ONE OPERATING UNIT.
         *
         * `resolveCompanyIds()` already took an optional company id and is
         * the same tenant-safe helper the main Dashboard filters with; this
         * page simply never passed one. Nothing about the scoping rules
         * changed: a null company id still means "every company this tenant
         * owns", and an id belonging to another tenant resolves to an empty
         * list rather than that tenant's data.
         */
        $user = $request->user();
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $companyIds = $this->dashboardStats->resolveCompanyIds($companyId);

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
                    ->map(fn (SafetyEquipment $e) => ['type' => 'Equipment Overdue', 'label' => $e->name, 'date' => $e->next_inspection_due, 'href' => route('hse.master').'?tab=register']))
                ->merge(P3kBox::whereIn('company_id', $companyIds)
                    ->whereNotNull('next_inspection_due')->whereDate('next_inspection_due', '<', now())
                    ->limit(5)->get(['id', 'location', 'next_inspection_due'])
                    ->map(fn (P3kBox $b) => ['type' => 'P3K Overdue', 'label' => $b->location, 'date' => $b->next_inspection_due, 'href' => route('hse.master').'?tab=supplies']))
                // v1.11.6 fix: `corrective_actions` has no `title` column
                // (confirmed against 2026_08_19_100064_create_corrective_
                // actions_table -- the actual free-text field is `action`)
                // -- this was the exact root cause of the HSE Overview
                // HTTP 500 (a QueryException on an unknown column, the
                // same class of bug this pass was told to watch for).
                ->merge(CorrectiveAction::whereIn('company_id', $companyIds)
                    ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())
                    ->limit(5)->get(['id', 'action', 'due_date'])
                    ->map(fn (CorrectiveAction $c) => ['type' => 'CAPA Overdue', 'label' => $c->action, 'date' => $c->due_date, 'href' => route('corrective-actions.index')]))
                ->sortBy('date')
                ->values()
                ->take(8),
            // v1.11.6 (Production Readiness pass, Part 4/5) -- real
            // Man-Hours (see ManHourLog) plus a Safety KPI Foundation
            // built ONLY from data that actually exists: open injury-
            // category incidents (a real, already-captured field) and
            // total Man-Hours for the same period. LTI/LTIFR/TRIR/
            // Fatality rate are deliberately NOT computed -- Incident has
            // no lost-time-days or fatality/recordability classification
            // captured anywhere, so those formulas would need fabricated
            // inputs. `safetyKpi.lost_time_metrics_available` is false so
            // the frontend can render an honest "not available" state
            // instead of a fake number.
            'manHours' => [
                'today' => $this->dashboardStats->sumManHours($companyIds, now()->toDateString(), now()->toDateString()),
                'this_month' => $this->dashboardStats->sumManHours($companyIds, now()->startOfMonth()->toDateString(), now()->toDateString()),
                'ytd' => $this->dashboardStats->sumManHours($companyIds, now()->startOfYear()->toDateString(), now()->toDateString()),
            ],
            'safetyKpi' => [
                'man_hours_ytd' => $this->dashboardStats->sumManHours($companyIds, now()->startOfYear()->toDateString(), now()->toDateString()),
                'recordable_injuries_ytd' => Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
                    ->where('category', 'injury')
                    ->whereYear('incident_date', now()->year)
                    ->count(),
                'lost_time_metrics_available' => false,
            ],
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
            /*
             * v2.109.0 -- THE FILTER'S OWN INPUTS.
             *
             * `Company::active()` is tenant-scoped by TenantScope, so this
             * list can only ever name companies the viewer already sees.
             */
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => ['company_id' => $companyId],

            /*
             * v2.109.0 -- TWELVE MONTHS OF REAL ACTIVITY, GROUPED IN PHP.
             *
             * Four series the Overview already counts as "open right now",
             * read here as "raised per month" so the page can show a trend
             * rather than four more totals. Each row carries the date the
             * module itself treats as the operational one: an incident's
             * `incident_date`, an observation's `observed_at`, a permit's
             * `start_datetime`, and a CAPA's `created_at` -- CAPA has no
             * raised-date column, and `due_date` is a different fact, so
             * the honest answer is when the record was made.
             *
             * GROUPED IN PHP ON PURPOSE. The obvious implementation is
             * `DATE_FORMAT(date, '%Y-%m')`, which is MySQL-only and would
             * make this page untestable on the suite's SQLite connection --
             * exactly the portability trap recorded in CONVENTIONS.md. One
             * date column over twelve months is a small read.
             *
             * `has_data` lets the chart tell an empty system from a quiet
             * one, rather than drawing twelve months of zeroes.
             */
            'safetyTrend' => $this->safetyTrend($companyIds),

            /*
             * v2.109.0 -- THE CALENDAR MOVED INTO THIS PAGE.
             *
             * The Overview used to receive `departmentEvents()`: the next
             * six HSE events in twenty-one days, which is a list, not a
             * calendar. A month grid needs a month, and navigating needs
             * the months either side, so this asks the SAME aggregation for
             * a three-month window. No new source, no new filter: it is
             * `CalendarService::departmentEventsBetween()`, which is
             * `departmentEvents()` with the window passed in rather than
             * assumed.
             *
             * Three months, not twelve: the widget navigates within what it
             * was given and links to the full Calendar for anything beyond,
             * so the page never pays for months nobody opens.
             */
            'calendar' => [
                'events' => $this->calendar->departmentEventsBetween(
                    $companyIds,
                    'hse',
                    now()->startOfMonth()->subMonth(),
                    now()->endOfMonth()->addMonth(),
                ),
                'window' => [
                    'start' => now()->startOfMonth()->subMonth()->toDateString(),
                    'end' => now()->endOfMonth()->addMonth()->toDateString(),
                ],
                'today' => now()->toDateString(),
            ],

            /*
             * v2.109.0 -- QUICK ACCESS, GATED SERVER-SIDE.
             *
             * Built here rather than in the page because authorization is
             * a server question: each entry uses the SAME predicate the
             * destination route already enforces, so a visible tile can
             * never lead to a 403 and a hidden one never hides a route the
             * account could use. The page maps `key` to an icon and an
             * accent; it decides nothing about access.
             *
             * CAPA LINKS TO ITS REGISTER, NOT TO A CREATE FORM. There is no
             * `corrective-actions.create` route -- a CAPA is raised FROM an
             * incident, observation or inspection, never from nothing -- so
             * offering "New CAPA" would be a button for a page that does
             * not exist.
             */
            'quickAccess' => collect([
                ['key' => 'incident', 'label' => 'Report Incident', 'href' => route('incidents.create'), 'allowed' => $user->canManageIncidents()],
                ['key' => 'observation', 'label' => 'Create Observation', 'href' => route('safety-observations.create'), 'allowed' => $user->canManageSafetyObservations()],
                ['key' => 'capa', 'label' => 'CAPA Register', 'href' => route('corrective-actions.index'), 'allowed' => true],
                ['key' => 'ptw', 'label' => 'Create PTW', 'href' => route('permits-to-work.create'), 'allowed' => $user->canCreatePtw()],
                ['key' => 'ppe', 'label' => 'PPE Management', 'href' => route('ppe.dashboard'), 'allowed' => true],
                ['key' => 'reports', 'label' => 'View Reports', 'href' => route('reports.index'), 'allowed' => true],
            ])->filter(fn (array $a) => $a['allowed'])
                ->map(fn (array $a) => ['key' => $a['key'], 'label' => $a['label'], 'href' => $a['href']])
                ->values()
                ->all(),

            // v2.71.0: gates the Overview's own HSE Administration row.
            // Same capability the destination routes enforce themselves --
            // this only decides whether the shortcut is worth showing, and
            // shows nothing an ordinary viewer could act on anyway.
            'canManageHse' => auth()->user()->canManageHse(),
        ]);
    }

    /**
     * Twelve months of "raised per month" for the four HSE record types
     * this Overview already reports on.
     *
     * @param  Collection<int, int>|array<int, int>  $companyIds
     * @return array{labels: list<string>, series: list<array{key: string, label: string, data: list<int>}>, has_data: bool}
     */
    private function safetyTrend($companyIds): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }

        /** Counts one date column into the month buckets above. */
        $bucket = function (iterable $dates) use ($months): array {
            $counts = array_fill_keys($months, 0);

            foreach ($dates as $date) {
                if ($date === null) {
                    continue;
                }

                $key = Carbon::parse($date)->format('Y-m');

                if (array_key_exists($key, $counts)) {
                    $counts[$key]++;
                }
            }

            return array_values($counts);
        };

        $incidents = $bucket(
            Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
                ->whereNotNull('incident_date')
                ->where('incident_date', '>=', $start)
                ->pluck('incident_date')
        );

        $observations = $bucket(
            SafetyObservation::whereIn('company_id', $companyIds)
                ->whereNotNull('observed_at')
                ->where('observed_at', '>=', $start)
                ->pluck('observed_at')
        );

        $permits = $bucket(
            PermitToWork::whereIn('company_id', $companyIds)
                ->whereNotNull('start_datetime')
                ->where('start_datetime', '>=', $start)
                ->pluck('start_datetime')
        );

        $capa = $bucket(
            CorrectiveAction::whereIn('company_id', $companyIds)
                ->where('created_at', '>=', $start)
                ->pluck('created_at')
        );

        $series = [
            ['key' => 'incidents', 'label' => 'Incidents', 'data' => $incidents],
            ['key' => 'observations', 'label' => 'Observations', 'data' => $observations],
            ['key' => 'capa', 'label' => 'CAPA', 'data' => $capa],
            ['key' => 'permits', 'label' => 'Permits to Work', 'data' => $permits],
        ];

        return [
            'labels' => $months,
            'series' => $series,
            // False when every series is empty for the whole window: the
            // chart then says so instead of drawing a flat line at zero,
            // which an empty system must never be shown as.
            'has_data' => collect($series)->flatMap(fn (array $s) => $s['data'])->sum() > 0,
        ];
    }
}
