<?php

namespace App\Services;

use App\Models\ControlledDocument;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCompetency;
use App\Models\GoodsReceipt;
use App\Models\Incident;
use App\Models\Item;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use App\Models\LeaveRequest;
use App\Models\ManHourLog;
use App\Models\MaterialRequest;
use App\Models\P3kBox;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\SafetyEquipment;
use App\Models\SafetyObservation;
use App\Models\Stock;
use App\Models\TbmMeeting;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * v2.83.0 -- THE MANAGEMENT WORKSPACE'S ONLY DATA SOURCE.
 *
 * Management answers a question no existing surface answered:
 *
 *   Dashboard             What is happening in IOMS right now?
 *   Department Overview   What is happening in THIS department?
 *   Management            How is the company doing, and what needs
 *                         management's attention?
 *
 * The distinction is not decoration -- it decides what belongs here. A
 * count of today's open permits is operational and already lives on the
 * HSE Overview; a twelve-month incident trend set beside the departments
 * it came from is not, and had nowhere to live at all.
 *
 * THREE RULES THIS CLASS IS BUILT AROUND.
 *
 * 1. IT AGGREGATES, IT NEVER OWNS. Every figure below is read from a
 *    module's own table through that module's own model. There is no
 *    management_* table, no rollup cache and no second copy of any
 *    record, so a management figure cannot drift from the operational
 *    record it describes -- it IS that record, counted.
 *
 * 2. NOTHING IS INVENTED. Where IOMS has no data for a metric a manager
 *    might reasonably expect -- financial performance, delivery lead
 *    time, cost per incident -- the metric is ABSENT rather than
 *    estimated or zero-filled. A confident zero is worse than a blank: it
 *    reads as "the company had none" when it means "the product does not
 *    know". Each section therefore also reports whether it has any data
 *    at all, so the page can say which of the two it is.
 *
 * 3. TENANT SCOPING IS BORROWED, NOT REBUILT. Every query goes through
 *    `DashboardStatsService::resolveCompanyIds()` -- the same
 *    TenantScope-safe helper the existing dashboards use, where an empty
 *    company list produces a `whereIn` matching zero rows rather than
 *    every row. There is not one raw cross-tenant aggregate in this file.
 */
class ManagementInsightsService
{
    public function __construct(private readonly DashboardStatsService $dashboardStats) {}

    /** @var array<int>|null */
    private ?array $companyIds = null;

    /**
     * The operating units this request may see, resolved once per
     * instance. Company carries CompanyAuthorizationScope, so this is
     * already narrowed to the viewer's authorized units as well as to
     * their tenant -- a management page is not a licence to read an
     * operating unit the viewer is not assigned to.
     */
    private function companyIds(): array
    {
        return $this->companyIds ??= $this->dashboardStats->resolveCompanyIds(null);
    }

    /* ==================================================================
     | EXECUTIVE OVERVIEW
     |================================================================= */

    /**
     * The headline state of the company, in the order management reads it:
     * scale, safety, attention.
     *
     * Each entry carries its own `available` flag rather than a bare
     * number, because "no incidents this month" and "this tenant has never
     * used Incident Management" must not render as the same 0.
     */
    public function executiveSummary(): array
    {
        $ids = $this->companyIds();
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $activeEmployees = Employee::active()->whereIn('company_id', $ids)->count();
        $totalEmployees = Employee::whereIn('company_id', $ids)->count();

        $incidentScope = fn () => Incident::where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'));

        $openCapa = fn () => CorrectiveAction::whereIn('company_id', $ids)
            ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED]);

        $manHours = ManHourLog::whereIn('company_id', $ids)
            ->whereDate('work_date', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(regular_hours), 0) as regular, COALESCE(SUM(overtime_hours), 0) as overtime')
            ->first();

        return [
            'operating_units' => count($ids),
            'headcount' => [
                'active' => $activeEmployees,
                'total' => $totalEmployees,
                'available' => $totalEmployees > 0,
            ],
            'projects' => [
                'active' => Project::whereIn('company_id', $ids)->whereIn('status', ['planned', 'ongoing'])->count(),
                'total' => Project::whereIn('company_id', $ids)->count(),
                'available' => Project::whereIn('company_id', $ids)->exists(),
            ],
            'incidents' => [
                'open' => $this->openIncidents()->count(),
                'this_month' => $incidentScope()->whereDate('incident_date', '>=', $monthStart)->count(),
                // Days since the most recent incident -- the single number
                // a safety-led organization actually tracks, and null (not
                // 0) when there has never been one.
                'days_since_last' => $this->daysSinceLastIncident(),
                'available' => $incidentScope()->exists(),
            ],
            'corrective_actions' => [
                'open' => $openCapa()->count(),
                'overdue' => $openCapa()->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
                'available' => CorrectiveAction::whereIn('company_id', $ids)->exists(),
            ],
            'permits' => [
                'active' => PermitToWork::whereIn('company_id', $ids)
                    ->whereIn('status', [PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_ACTIVE])->count(),
                'available' => PermitToWork::whereIn('company_id', $ids)->exists(),
            ],
            'man_hours' => [
                'regular' => (float) ($manHours->regular ?? 0),
                'overtime' => (float) ($manHours->overtime ?? 0),
                'available' => ManHourLog::whereIn('company_id', $ids)->exists(),
            ],
        ];
    }

    /** Whole days since the most recent recorded incident; null when there has never been one. */
    public function daysSinceLastIncident(): ?int
    {
        $last = Incident::where(fn ($q) => $q->whereIn('company_id', $this->companyIds())->orWhereNull('company_id'))
            ->whereNotNull('incident_date')
            ->max('incident_date');

        return $last === null
            ? null
            : (int) CarbonImmutable::parse($last)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());
    }

    private function openIncidents()
    {
        return Incident::where(fn ($q) => $q->whereIn('company_id', $this->companyIds())->orWhereNull('company_id'))
            ->whereIn('status', [Incident::STATUS_REPORTED, Incident::STATUS_INVESTIGATING]);
    }

    /* ==================================================================
     | COMPANY KPI  /  KPI BY DEPARTMENT
     |================================================================= */

    /**
     * Company KPI totals for a period, and the same figures broken down by
     * department.
     *
     * Reads the SAME `KpiCategory::dashboardVisible()` catalogue the main
     * Dashboard reads, deliberately: a management report showing different
     * KPI categories from the operational dashboard would be a second
     * definition of the company's own KPIs.
     */
    public function companyKpi(int $year, ?int $month = null): array
    {
        $ids = $this->companyIds();
        $categories = KpiCategory::dashboardVisible()->get();

        if ($categories->isEmpty()) {
            return ['available' => false, 'categories' => [], 'departments' => [], 'trend' => []];
        }

        $byCategory = KpiRecord::query()
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $ids)
            ->forPeriod($year, $month)
            ->selectRaw('kpi_records.kpi_category_id as category_id, SUM(kpi_records.quantity) as total')
            ->groupBy('kpi_records.kpi_category_id')
            ->pluck('total', 'category_id');

        $byDepartment = KpiRecord::query()
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $ids)
            ->forPeriod($year, $month)
            ->selectRaw('departments.id as department_id, departments.name as department_name, kpi_records.kpi_category_id as category_id, SUM(kpi_records.quantity) as total')
            ->groupBy('departments.id', 'departments.name', 'kpi_records.kpi_category_id')
            ->get();

        $departments = $byDepartment
            ->groupBy('department_id')
            ->map(fn (Collection $rows) => [
                'id' => (int) $rows->first()->department_id,
                'name' => $rows->first()->department_name,
                'totals' => $rows->mapWithKeys(fn ($r) => [(int) $r->category_id => (int) $r->total])->all(),
                'total' => (int) $rows->sum('total'),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'available' => $byCategory->isNotEmpty(),
            'categories' => $categories->map(fn (KpiCategory $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'short_label' => $c->short_label,
                'is_negative' => (bool) $c->is_negative,
                'total' => (int) ($byCategory->get($c->id) ?? 0),
            ])->values()->all(),
            'departments' => $departments,
            'trend' => $this->kpiTrend($year, $categories),
        ];
    }

    /** Twelve monthly totals for the year, per category -- the shape a trend chart needs. */
    private function kpiTrend(int $year, Collection $categories): array
    {
        $rows = KpiRecord::query()
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $this->companyIds())
            ->where('kpi_records.year', $year)
            ->selectRaw('kpi_records.month as month, kpi_records.kpi_category_id as category_id, SUM(kpi_records.quantity) as total')
            ->groupBy('kpi_records.month', 'kpi_records.kpi_category_id')
            ->get();

        return collect(range(1, 12))->map(function (int $month) use ($rows, $categories) {
            $forMonth = $rows->where('month', $month);

            return [
                'month' => $month,
                'label' => CarbonImmutable::create(now()->year, $month, 1)->format('M'),
                'totals' => $categories->mapWithKeys(fn (KpiCategory $c) => [
                    $c->id => (int) ($forMonth->firstWhere('category_id', $c->id)->total ?? 0),
                ])->all(),
            ];
        })->all();
    }

    /* ==================================================================
     | HSE PERFORMANCE
     |================================================================= */

    /** Safety performance over the last `$months` whole months, plus the current state. */
    public function hsePerformance(int $months = 12): array
    {
        $ids = $this->companyIds();
        $since = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        /*
         * Bucketed with `substr(date, 1, 7)` rather than YEAR()/MONTH().
         *
         * Not a style choice: the date functions are MySQL-specific and
         * this codebase's test suite runs on SQLite, so a YEAR() here was
         * a query that could only ever be exercised in production.
         * `substr` is in both, and a DATE or DATETIME column's first seven
         * characters are 'YYYY-MM' in both -- which is exactly the bucket.
         */
        $incidentRows = Incident::where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'))
            ->whereDate('incident_date', '>=', $since->toDateString())
            ->selectRaw('substr(incident_date, 1, 7) as ym, COUNT(*) as total')
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $observationRows = SafetyObservation::whereIn('company_id', $ids)
            ->whereDate('observed_at', '>=', $since->toDateString())
            ->selectRaw('substr(observed_at, 1, 7) as ym, COUNT(*) as total')
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $trend = collect(range(0, $months - 1))->map(function (int $offset) use ($since, $incidentRows, $observationRows) {
            $point = $since->addMonths($offset);
            $bucket = $point->format('Y-m');

            return [
                'label' => $point->format('M Y'),
                'short_label' => $point->format('M'),
                'incidents' => (int) ($incidentRows->get($bucket) ?? 0),
                'observations' => (int) ($observationRows->get($bucket) ?? 0),
            ];
        })->all();

        return [
            'available' => Incident::where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'))->exists()
                || SafetyObservation::whereIn('company_id', $ids)->exists(),
            'trend' => $trend,
            'days_since_last_incident' => $this->daysSinceLastIncident(),
            // Grouped dynamically -- the severity vocabulary belongs to the
            // module, and hardcoding a list here would silently drop a
            // value the module later adds.
            'by_severity' => $this->openIncidents()
                ->selectRaw('severity, COUNT(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity')
                ->map(fn ($v) => (int) $v)
                ->all(),
            'by_status' => Incident::where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'))
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($v) => (int) $v)
                ->all(),
            'observations' => [
                'open' => SafetyObservation::whereIn('company_id', $ids)
                    ->whereNotIn('status', [SafetyObservation::STATUS_CLOSED, SafetyObservation::STATUS_CANCELLED])
                    ->count(),
                'total' => SafetyObservation::whereIn('company_id', $ids)->count(),
            ],
            'toolbox_meetings' => TbmMeeting::whereIn('company_id', $ids)
                ->where('status', TbmMeeting::STATUS_CONDUCTED)
                ->whereDate('meeting_date', '>=', $since->toDateString())
                ->count(),
            'permits' => [
                'active' => PermitToWork::whereIn('company_id', $ids)
                    ->whereIn('status', [PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_ACTIVE])->count(),
                'awaiting_approval' => PermitToWork::whereIn('company_id', $ids)
                    ->where('status', PermitToWork::STATUS_SUBMITTED)->count(),
                'closed' => PermitToWork::whereIn('company_id', $ids)
                    ->where('status', PermitToWork::STATUS_CLOSED)->count(),
                'available' => PermitToWork::whereIn('company_id', $ids)->exists(),
            ],
        ];
    }

    /* ==================================================================
     | WORKFORCE
     |================================================================= */

    public function workforce(): array
    {
        $ids = $this->companyIds();
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $byDepartment = Department::whereIn('company_id', $ids)
            ->withCount(['employees' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'headcount' => $d->employees_count])
            ->filter(fn (array $row) => $row['headcount'] > 0)
            ->sortByDesc('headcount')
            ->values()
            ->all();

        $manHours = ManHourLog::whereIn('company_id', $ids)
            ->whereDate('work_date', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(regular_hours), 0) as regular, COALESCE(SUM(overtime_hours), 0) as overtime, COUNT(DISTINCT employee_id) as people')
            ->first();

        return [
            'available' => Employee::whereIn('company_id', $ids)->exists(),
            'headcount' => [
                'active' => Employee::active()->whereIn('company_id', $ids)->count(),
                'total' => Employee::whereIn('company_id', $ids)->count(),
            ],
            'by_department' => $byDepartment,
            'by_employment_type' => Employee::active()->whereIn('company_id', $ids)
                ->selectRaw('employment_type, COUNT(*) as total')
                ->groupBy('employment_type')
                ->pluck('total', 'employment_type')
                ->map(fn ($v) => (int) $v)
                ->all(),
            'contracts_expiring_60_days' => Employee::active()->whereIn('company_id', $ids)
                ->whereNotNull('contract_end_date')
                ->whereBetween('contract_end_date', [now()->toDateString(), now()->addDays(60)->toDateString()])
                ->count(),
            'leave' => [
                'awaiting_approval' => LeaveRequest::whereIn('company_id', $ids)
                    ->where('status', LeaveRequest::STATUS_SUBMITTED)->count(),
                'approved_this_month' => LeaveRequest::whereIn('company_id', $ids)
                    ->where('status', LeaveRequest::STATUS_APPROVED)
                    ->whereDate('start_date', '>=', $monthStart->toDateString())->count(),
                'available' => LeaveRequest::whereIn('company_id', $ids)->exists(),
            ],
            'man_hours_this_month' => [
                'regular' => (float) ($manHours->regular ?? 0),
                'overtime' => (float) ($manHours->overtime ?? 0),
                'people' => (int) ($manHours->people ?? 0),
                'available' => ManHourLog::whereIn('company_id', $ids)->exists(),
            ],
            'competencies_expiring_90_days' => EmployeeCompetency::query()
                ->whereHas('employee', fn ($q) => $q->whereIn('company_id', $ids))
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(90)->toDateString()])
                ->count(),
        ];
    }

    /* ==================================================================
     | LOGISTICS / WAREHOUSE
     |================================================================= */

    public function logistics(): array
    {
        $ids = $this->companyIds();
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $warehouseIds = Warehouse::whereIn('company_id', $ids)->pluck('id');

        // Below minimum stock, per item, summed across every warehouse the
        // item is held in -- a shortage is a company-level fact, not a
        // per-location one.
        $held = Stock::whereIn('company_id', $ids)
            ->selectRaw('item_id, COALESCE(SUM(quantity), 0) as held')
            ->groupBy('item_id')
            ->pluck('held', 'item_id');

        $belowMinimum = Item::whereIn('company_id', $ids)
            ->where('is_active', true)
            ->whereNotNull('min_stock')
            ->where('min_stock', '>', 0)
            ->get(['id', 'item_code', 'name', 'unit', 'min_stock'])
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'unit' => $item->unit,
                'min_stock' => (float) $item->min_stock,
                'held' => (float) ($held->get($item->id) ?? 0),
            ])
            ->filter(fn (array $row) => $row['held'] < $row['min_stock'])
            ->sortBy(fn (array $row) => $row['held'] - $row['min_stock'])
            ->values();

        return [
            'available' => Item::whereIn('company_id', $ids)->exists()
                || MaterialRequest::whereIn('company_id', $ids)->exists(),
            'items' => [
                'active' => Item::whereIn('company_id', $ids)->where('is_active', true)->count(),
                'total' => Item::whereIn('company_id', $ids)->count(),
            ],
            'warehouses' => $warehouseIds->count(),
            'below_minimum_count' => $belowMinimum->count(),
            'below_minimum' => $belowMinimum->take(8)->all(),
            'material_requests' => [
                'by_status' => MaterialRequest::whereIn('company_id', $ids)
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->map(fn ($v) => (int) $v)
                    ->all(),
                'open' => MaterialRequest::whereIn('company_id', $ids)
                    ->whereNotIn('status', [
                        MaterialRequest::STATUS_COMPLETED,
                        MaterialRequest::STATUS_CANCELLED,
                        MaterialRequest::STATUS_REJECTED,
                    ])->count(),
                'this_month' => MaterialRequest::whereIn('company_id', $ids)
                    ->whereDate('request_date', '>=', $monthStart->toDateString())->count(),
                'available' => MaterialRequest::whereIn('company_id', $ids)->exists(),
            ],
            // GoodsReceipt carries no company_id of its own -- it is scoped
            // through the warehouse it was received into, which is where
            // its tenancy actually lives.
            'goods_receipts_this_month' => GoodsReceipt::whereIn('warehouse_id', $warehouseIds)
                ->whereDate('received_date', '>=', $monthStart->toDateString())->count(),
        ];
    }

    /* ==================================================================
     | OUTSTANDING ACTIONS  --  the "what needs attention" half
     |================================================================= */

    /**
     * Everything overdue or unresolved, as ROWS rather than counts.
     *
     * A count tells management there is a problem; a row tells them whose
     * it is, which is the difference between a report and an action list.
     */
    public function outstandingActions(int $limit = 8): array
    {
        $ids = $this->companyIds();

        $openCapa = fn () => CorrectiveAction::whereIn('company_id', $ids)
            ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED]);

        return [
            'available' => CorrectiveAction::whereIn('company_id', $ids)->exists()
                || SafetyEquipment::whereIn('company_id', $ids)->exists(),
            'corrective_actions' => [
                'open' => $openCapa()->count(),
                'overdue' => $openCapa()->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
                'by_status' => $openCapa()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->map(fn ($v) => (int) $v)
                    ->all(),
                'rows' => $openCapa()
                    ->with(['assignee:id,name'])
                    ->orderByRaw('due_date IS NULL, due_date ASC')
                    ->limit($limit)
                    ->get(['id', 'action', 'priority', 'status', 'due_date', 'assigned_to'])
                    ->map(fn (CorrectiveAction $c) => [
                        'id' => $c->id,
                        'action' => $c->action,
                        'priority' => $c->priority,
                        'status' => $c->status,
                        // Formatted here, not in the page: `due_date` is a
                        // cast date and serialises as a full ISO timestamp,
                        // which rendered literally in the table. A due date
                        // has no time of day, so the string that reaches the
                        // browser should not claim one.
                        'due_date' => $c->due_date?->toDateString(),
                        'assignee' => $c->assignee?->name,
                        'overdue' => $c->due_date !== null && CarbonImmutable::parse($c->due_date)->isPast(),
                    ])->all(),
            ],
            'overdue_inspections' => [
                'safety_equipment' => SafetyEquipment::whereIn('company_id', $ids)
                    ->where('status', 'active')
                    ->whereNotNull('next_inspection_due')
                    ->whereDate('next_inspection_due', '<', now())->count(),
                'p3k_boxes' => P3kBox::whereIn('company_id', $ids)
                    ->whereNotNull('next_inspection_due')
                    ->whereDate('next_inspection_due', '<', now())->count(),
            ],
            'awaiting_decision' => [
                'permits' => PermitToWork::whereIn('company_id', $ids)
                    ->where('status', PermitToWork::STATUS_SUBMITTED)->count(),
                'material_requests' => MaterialRequest::whereIn('company_id', $ids)
                    ->where('status', MaterialRequest::STATUS_SUBMITTED)->count(),
                'leave_requests' => LeaveRequest::whereIn('company_id', $ids)
                    ->where('status', LeaveRequest::STATUS_SUBMITTED)->count(),
            ],
        ];
    }

    /* ==================================================================
     | COMPLIANCE / DOCUMENT STATUS
     |================================================================= */

    /**
     * Controlled-document state only.
     *
     * Deliberately NOT a "compliance score". IOMS records which documents
     * exist and what state each is in; it does not know which documents a
     * given company is legally REQUIRED to hold, so any percentage
     * calculated here would be a number with no denominator -- exactly the
     * invented metric this class refuses to produce.
     */
    public function compliance(): array
    {
        $ids = $this->companyIds();

        return [
            'available' => ControlledDocument::whereIn('company_id', $ids)->exists(),
            'documents_by_status' => ControlledDocument::whereIn('company_id', $ids)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($v) => (int) $v)
                ->all(),
            'effective_documents' => ControlledDocument::whereIn('company_id', $ids)
                ->where('status', ControlledDocument::STATUS_EFFECTIVE)->count(),
            'documents_in_review' => ControlledDocument::whereIn('company_id', $ids)
                ->where('status', ControlledDocument::STATUS_REVIEW)->count(),
        ];
    }

    /* ==================================================================
     | DEPARTMENT COMPARISON  --  the overview's centre panel
     |================================================================= */

    /**
     * One row per operating department, carrying the figures that are
     * comparable ACROSS departments: headcount, KPI total for the period,
     * and open material requests raised by it.
     *
     * A department with nothing recorded is kept rather than filtered out:
     * "this department records nothing" is itself management information.
     */
    public function departmentComparison(int $year, ?int $month = null): array
    {
        $ids = $this->companyIds();

        $departments = Department::whereIn('company_id', $ids)
            ->where('is_active', true)
            ->withCount(['employees' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        if ($departments->isEmpty()) {
            return [];
        }

        $kpiTotals = KpiRecord::query()
            ->whereIn('department_id', $departments->pluck('id'))
            ->forPeriod($year, $month)
            ->selectRaw('department_id, SUM(quantity) as total')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        $requests = MaterialRequest::whereIn('company_id', $ids)
            ->whereNotNull('department_id')
            ->whereNotIn('status', [
                MaterialRequest::STATUS_COMPLETED,
                MaterialRequest::STATUS_CANCELLED,
                MaterialRequest::STATUS_REJECTED,
            ])
            ->selectRaw('department_id, COUNT(*) as total')
            ->groupBy('department_id')
            ->pluck('total', 'department_id');

        return $departments->map(fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'headcount' => $d->employees_count,
            'kpi_total' => (int) ($kpiTotals->get($d->id) ?? 0),
            'open_requests' => (int) ($requests->get($d->id) ?? 0),
        ])->sortByDesc('headcount')->values()->all();
    }
}
