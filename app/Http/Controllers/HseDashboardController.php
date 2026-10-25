<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\EmployeePpe;
use App\Models\Incident;
use App\Models\MaterialRequest;
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

        /*
         * v2.116.0 -- counted once and reused, because the KPI card's
         * detail line has to partition the very same number it sits under.
         */
        $openIncidentsCount = (clone $openIncidents)->count();

        $ppeAlertCount = EmployeePpe::query()->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))->effectiveStatus('expiring_soon')->count()
            + EmployeePpe::query()->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))->effectiveStatus('expired')->count();

        return Inertia::render('Hse/Dashboard', [
            'activeProjectsCount' => Project::whereIn('company_id', $companyIds)->whereIn('status', ['planned', 'ongoing'])->count(),
            'openIncidentsCount' => $openIncidentsCount,
            'operationalKpis' => $this->operationalKpis($companyIds, $openIncidentsCount, $ppeAlertCount),
            'incidentsBySeverity' => (clone $openIncidents)
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity'),
            'ppeAlertCount' => $ppeAlertCount,
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
                    ->map(fn (SafetyEquipment $e) => ['type' => 'Equipment Overdue', 'label' => $e->name, 'date' => $e->next_inspection_due, 'href' => route('safety-equipment.show', $e->id)]))
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
            /*
             * v2.116.0 -- ACCIDENT CLASSIFICATION AND FREQUENCY RATES ARE
             * TWO DIFFERENT MEASURES, SO THEY ARE TWO DIFFERENT PROPS.
             *
             * A classification is a COUNT OF CASES. A frequency rate is
             * cases divided by exposure hours. Merging them into one panel
             * of numbers is how a reader ends up quoting a case count as a
             * rate, so the page gets them separately and each states its
             * own period, numerator and denominator.
             */
            'accidentClassification' => $this->accidentClassification($companyIds),
            'frequencyRates' => $this->frequencyRates($companyIds),

            'safetyKpi' => [
                'man_hours_ytd' => $this->dashboardStats->sumManHours($companyIds, now()->startOfYear()->toDateString(), now()->toDateString()),
                /*
                 * v2.116.0 -- THIS NUMBER WAS MISLABELLED, AND THE FIX IS
                 * THE WHOLE POINT OF THE CLASSIFICATION WORK.
                 *
                 * It counted every incident whose CATEGORY is `injury`,
                 * under the label "Recordable Injuries". Those are not the
                 * same set: a first-aid case is an injury and is NOT
                 * recordable, so the figure overstated the recordable
                 * count by however many first-aid cases a tenant had
                 * recorded. It now reads the classification the reporter
                 * actually selected -- see `accidentClassification()` for
                 * which values are recordable and why.
                 */
                'recordable_injuries_ytd' => $this->accidentClassification($companyIds)['recordable'],
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
     * The five operational KPIs across the top of the Overview, in the
     * order the approved design fixes them.
     *
     * BUILT ON THE SERVER, deliberately, and not six loose props the page
     * arranges. Each card carries a value AND the two-part detail line
     * underneath it, and both halves have to be counted with the same
     * WHERE clause or the detail contradicts the number above it. The page
     * maps `key` to an icon and an accent and decides nothing else.
     *
     * EVERY DETAIL LINE PARTITIONS ITS OWN VALUE. "1 active, 0 past end
     * time" adds up to the 1 above it. A detail that counted a different
     * set would read as a breakdown and not be one, which is worse than
     * having no detail at all.
     *
     * @param  Collection<int, int>|array<int, int>  $companyIds
     * @return list<array<string, mixed>>
     */
    private function operationalKpis($companyIds, int $openIncidentsCount, int $ppeAlertCount): array
    {
        $now = now();

        // --- Permits: open now, split by whether their window has passed.
        $openPermits = PermitToWork::whereIn('company_id', $companyIds)
            ->whereIn('status', [PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_ACTIVE]);

        $permitsPastEnd = (clone $openPermits)->whereNotNull('end_datetime')->where('end_datetime', '<', $now)->count();
        $permitsOpen = (clone $openPermits)->count();

        // --- Incidents: the same two statuses the headline count uses.
        $reported = Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            ->where('status', Incident::STATUS_REPORTED)->count();

        // --- PPE: the two states the alert count is the sum of.
        $ppeExpiring = EmployeePpe::query()
            ->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->effectiveStatus('expiring_soon')->count();

        /*
         * --- Inspections: THE ONLY SCHEDULED INSPECTIONS IOMS HAS.
         *
         * `hse_inspections` records inspections that HAPPENED -- it has an
         * `inspection_date` and a pass/fail result, and no scheduled or
         * planned date anywhere. What IS scheduled is the next inspection
         * due on a piece of safety equipment and on a P3K box, both real
         * date columns the Overview already counts overdue items from.
         * So this card counts those, and says so; counting completed
         * inspections and calling them upcoming would be a different fact
         * under the same word.
         */
        $equipmentDue = SafetyEquipment::whereIn('company_id', $companyIds)
            ->where('status', 'active')
            ->whereNotNull('next_inspection_due');

        $p3kDue = P3kBox::whereIn('company_id', $companyIds)->whereNotNull('next_inspection_due');

        $dueThisWeek = (clone $equipmentDue)->whereBetween('next_inspection_due', [$now->toDateString(), $now->copy()->addDays(7)->toDateString()])->count()
            + (clone $p3kDue)->whereBetween('next_inspection_due', [$now->toDateString(), $now->copy()->addDays(7)->toDateString()])->count();

        $dueOverdue = (clone $equipmentDue)->whereDate('next_inspection_due', '<', $now->toDateString())->count()
            + (clone $p3kDue)->whereDate('next_inspection_due', '<', $now->toDateString())->count();

        /*
         * --- Material Requests: outstanding, not "all".
         *
         * `OUTSTANDING_STATUSES` is the model's own definition of still
         * owing the requester something, and `AGING_OVERDUE_DAYS` is its
         * own overdue threshold. Both are read from the model rather than
         * restated here, so this card cannot drift from the Material
         * Request module's answer to the same question.
         */
        $outstandingRequests = MaterialRequest::whereIn('company_id', $companyIds)
            ->whereIn('status', MaterialRequest::OUTSTANDING_STATUSES);

        $awaitingApproval = (clone $outstandingRequests)->where('status', MaterialRequest::STATUS_SUBMITTED)->count();

        $requestsOverdue = (clone $outstandingRequests)
            ->whereNotNull('request_date')
            ->whereDate('request_date', '<=', $now->copy()->subDays(MaterialRequest::AGING_OVERDUE_DAYS)->toDateString())
            ->count();

        return [
            [
                'key' => 'permits',
                'label' => 'Active Permits',
                'value' => $permitsOpen,
                'detail' => [
                    ['value' => $permitsOpen - $permitsPastEnd, 'label' => 'active'],
                    ['value' => $permitsPastEnd, 'label' => 'past end time'],
                ],
                'href' => route('permits-to-work.index'),
                'alert' => $permitsPastEnd > 0,
            ],
            [
                'key' => 'incidents',
                'label' => 'Open Incidents',
                'value' => $openIncidentsCount,
                'detail' => [
                    ['value' => $reported, 'label' => 'reported'],
                    ['value' => $openIncidentsCount - $reported, 'label' => 'investigating'],
                ],
                'href' => route('incidents.index'),
                'alert' => $openIncidentsCount > 0,
            ],
            [
                'key' => 'ppe',
                'label' => 'PPE Alerts',
                'value' => $ppeAlertCount,
                'detail' => [
                    ['value' => $ppeExpiring, 'label' => 'expiring'],
                    ['value' => $ppeAlertCount - $ppeExpiring, 'label' => 'expired'],
                ],
                'href' => route('ppe.dashboard'),
                'alert' => $ppeAlertCount > 0,
            ],
            [
                'key' => 'inspections',
                'label' => 'Inspections Due',
                'value' => $dueThisWeek + $dueOverdue,
                'detail' => [
                    ['value' => $dueThisWeek, 'label' => 'this week'],
                    ['value' => $dueOverdue, 'label' => 'overdue'],
                ],
                'href' => route('hse.master'),
                'alert' => $dueOverdue > 0,
            ],
            [
                'key' => 'material',
                'label' => 'Material Requests',
                'value' => (clone $outstandingRequests)->count(),
                'detail' => [
                    ['value' => $awaitingApproval, 'label' => 'awaiting approval'],
                    ['value' => $requestsOverdue, 'label' => 'overdue'],
                ],
                'href' => route('material-requests.index'),
                'alert' => $requestsOverdue > 0,
            ],
        ];
    }

    /**
     * Accident classification, year to date: HOW MANY CASES OF EACH KIND.
     *
     * WHERE THE DATA COMES FROM. `incidents.injury_severity` has existed
     * since v2.73.0 and holds exactly one of `none`, `first_aid`,
     * `medical_treatment`, `restricted_work`, `lost_time`, `fatality` --
     * selected by the person filing the report, not derived by IOMS. The
     * five classifications below are those values, under the abbreviations
     * an HSE reader expects:
     *
     *   FAT  fatality            LTI  lost_time          RWC  restricted_work
     *   MTC  medical_treatment   FAC  first_aid
     *
     * NOTHING IS DOUBLE COUNTED, and the reason is structural rather than
     * careful: an incident carries ONE `injury_severity`, so the five
     * counts are disjoint by construction and a case can never land in two
     * of them. An incident classified `none`, or left unclassified, is in
     * none of the five -- it is not silently folded into the lowest one.
     *
     * THESE ARE CASES, NOT PEOPLE. `incidents.people_injured` exists and
     * is a different measure; one incident that injured three people is
     * one case here. Mixing the two would produce a number that is neither.
     *
     * RECORDABLE EXCLUDES FIRST AID, which is the one judgement in this
     * method and the reason it is stated here rather than inside a view: a
     * recordable case is one requiring more than first aid, so the
     * recordable total is FAT + LTI + RWC + MTC. Adding FAC would inflate
     * every rate computed from it.
     *
     * @param  Collection<int, int>|array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function accidentClassification($companyIds): array
    {
        $counts = Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            ->whereYear('incident_date', now()->year)
            ->whereNotNull('injury_severity')
            ->selectRaw('injury_severity, count(*) as total')
            ->groupBy('injury_severity')
            ->pluck('total', 'injury_severity');

        $of = fn (string $value) => (int) ($counts[$value] ?? 0);

        $classes = [
            ['key' => 'fat', 'code' => 'FAT', 'label' => 'Fatality', 'short_label' => 'Fatality', 'value' => $of('fatality'), 'recordable' => true],
            ['key' => 'lti', 'code' => 'LTI', 'label' => 'Lost Time Injury', 'short_label' => 'Lost Time', 'value' => $of('lost_time'), 'recordable' => true],
            ['key' => 'rwc', 'code' => 'RWC', 'label' => 'Restricted Work Case', 'short_label' => 'Restricted Work', 'value' => $of('restricted_work'), 'recordable' => true],
            ['key' => 'mtc', 'code' => 'MTC', 'label' => 'Medical Treatment Case', 'short_label' => 'Medical Treatment', 'value' => $of('medical_treatment'), 'recordable' => true],
            ['key' => 'fac', 'code' => 'FAC', 'label' => 'First Aid Case', 'short_label' => 'First Aid', 'value' => $of('first_aid'), 'recordable' => false],
        ];

        $recordable = collect($classes)->where('recordable', true)->sum('value');

        return [
            'period' => 'ytd',
            'period_label' => (string) now()->year,
            'classes' => $classes,
            'recordable' => (int) $recordable,
            'lost_time' => $of('lost_time') + $of('fatality'),
            'total_cases' => (int) collect($classes)->sum('value'),
            /*
             * Unclassified injury reports: filed under the `injury`
             * CATEGORY but with no severity selected, so they belong to no
             * classification and to no rate. Surfaced rather than hidden,
             * because the gap is the reason a count may look low.
             */
            'unclassified' => Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
                ->whereYear('incident_date', now()->year)
                ->where('category', 'injury')
                ->where(fn ($q) => $q->whereNull('injury_severity')->orWhere('injury_severity', 'none'))
                ->count(),
        ];
    }

    /**
     * TRIR and LTIFR, year to date -- or an honest refusal.
     *
     * IOMS DELIBERATELY DID NOT PUBLISH THESE (ADR 044, and this
     * controller since v1.11.6), for three stated reasons. Two of them are
     * no longer true and one still is, so the decision is revisited rather
     * than inherited:
     *
     *  - "no recordability classification" -- WAS true, is not since
     *    v2.73.0 added `incidents.injury_severity`. The claim outlived the
     *    column by several releases.
     *  - "no lost-time days" -- true, and irrelevant to these two: lost
     *    DAYS are the input to a severity rate, not to a frequency rate,
     *    which counts CASES.
     *  - "exposure hours cover employees rather than everyone on site" --
     *    STILL TRUE where a contractor's workers are not in the workforce
     *    register, and it is why the denominator is named on the panel
     *    rather than left implied.
     *
     * So the rates are computed from real inputs and shown WITH their
     * formula, period, numerator and denominator, or not shown at all.
     *
     * THE DENOMINATOR DECIDES WHETHER THERE IS AN ANSWER. A rate needs
     * exposure hours; with none recorded the result is undefined, not
     * zero. `sumManHours()` already answers `null` rather than 0 for a
     * period nobody logged, which is exactly the distinction this needs --
     * so no hours means `available: false` with the reason, never a
     * confident 0.00 that gets quoted in a board pack.
     *
     * @param  Collection<int, int>|array<int, int>  $companyIds
     * @return array<string, mixed>
     */
    private function frequencyRates($companyIds): array
    {
        $classification = $this->accidentClassification($companyIds);
        $manHours = $this->dashboardStats->sumManHours($companyIds, now()->startOfYear()->toDateString(), now()->toDateString());

        $available = $manHours !== null && $manHours > 0;

        $rate = fn (int $cases, int $multiplier) => $available
            ? round(($cases * $multiplier) / $manHours, 2)
            : null;

        return [
            'available' => $available,
            'period_label' => 'Year to date '.now()->year,
            'man_hours' => $manHours,
            'unavailable_reason' => $available
                ? null
                : 'Belum ada jam kerja tercatat untuk periode ini, jadi pembagi rasionya belum ada.',
            'rates' => [
                [
                    'key' => 'trir',
                    'code' => 'TRIR',
                    'label' => 'Total Recordable Incident Rate',
                    'value' => $rate($classification['recordable'], 200000),
                    'cases' => $classification['recordable'],
                    'cases_label' => 'FAT + LTI + RWC + MTC',
                    'multiplier' => 200000,
                ],
                [
                    'key' => 'ltifr',
                    'code' => 'LTIFR',
                    'label' => 'Lost Time Injury Frequency Rate',
                    'value' => $rate($classification['lost_time'], 1000000),
                    'cases' => $classification['lost_time'],
                    'cases_label' => 'LTI + FAT',
                    'multiplier' => 1000000,
                ],
            ],
        ];
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
