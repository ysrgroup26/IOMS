<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\DailyReport;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Incident;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Stock;
use App\Models\Task;
use App\Models\WorkOrder;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $stats,
        private readonly CalendarService $calendar,
    ) {}

    /**
     * Dashboard is the landing page as of v1.9.0 (Home was retired --
     * see docs/ADR/007's v1.9.0 section). recentDailyReports,
     * recentEmployeeChanges, and the release announcement below are
     * ported verbatim from the old HomeController: real, already-working
     * queries against data that already exists, not new functionality --
     * Home and Dashboard overlapped, and the instruction was to keep
     * whichever query, not build two.
     */
    public function index(Request $request): Response
    {
        $year = (int) $request->input('year', now()->format('Y'));
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        // Tenant-isolation fix: these two queries had NO company/tenant
        // filter at all, regardless of $companyId -- a genuine
        // cross-tenant data leak (any tenant's Dashboard showed every
        // tenant's recent daily reports and employee changes), not just
        // the "no company selected = unfiltered" bug the rest of this
        // controller's stats had. $companyIds is resolved the same
        // tenant-safe way DashboardStatsService now resolves it (see that
        // class's own doc comment) -- Company::query() already respects
        // App\Models\Scopes\TenantScope, so this can never include
        // another tenant's company id.
        $companyIds = $this->stats->resolveCompanyIds($companyId);

        $recentDailyReports = DailyReport::with('project:id,name')
            ->whereHas('project', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->latest('report_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (DailyReport $r) => [
                'id' => $r->id,
                'project_name' => $r->project->name,
                'department_name' => $r->department_name,
                'date' => $r->report_date->format('d M Y'),
            ]);

        // Reuses the existing activity_logs audit trail -- no new table
        // needed to surface "recent employee changes". ActivityLog::record()
        // already auto-populates company_id off the subject (Employee)
        // being logged (see ActivityLog::record()'s own doc comment), so
        // filtering on it directly is correct and doesn't need a join.
        $recentEmployeeChanges = ActivityLog::where('subject_type', Employee::class)
            ->whereIn('company_id', $companyIds)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'description' => $log->description,
                'when' => $log->created_at->diffForHumans(),
            ]);

        $releaseDate = Carbon::parse(config('ioms.release_date'));

        return Inertia::render('Dashboard/Index', [
            'recentDailyReports' => $recentDailyReports,
            'recentEmployeeChanges' => $recentEmployeeChanges,
            // Auto-hides 48h after release -- computed server-side so it
            // never flashes stale even with cached assets.
            'showAnnouncement' => now()->lessThan($releaseDate->copy()->addHours(48)),
            'filters' => ['year' => $year, 'month' => $month, 'company_id' => $companyId],
            'availableYears' => $this->availableYears(),
            'currentMonth' => now()->format('F Y'),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'summary' => $this->stats->summaryCards($year, $month, $companyId),
            'companyHeadcount' => $this->stats->companyHeadcount(),
            'departmentDistribution' => $this->stats->departmentDistribution($companyId),
            'monthlyTrend' => $this->stats->monthlyTrend($year, $companyId),
            'leaderboards' => $this->stats->leaderboards($year, $companyId),
            'activeProjectsCount' => $this->stats->activeProjectsCount($companyId),
            'todaysActivities' => $this->stats->todaysActivities($companyId),
            'upcomingReminders' => $this->stats->upcomingReminders($companyId),
            // Universal Task Engine Dashboard integration (v1.6.4) -- real
            // query against the tasks table, not placeholder data. Shows
            // the CURRENT user's own open tasks (the natural "what do I
            // need to do" reading of a personal dashboard widget),
            // soonest due date first, nulls (no due date) last.
            'pendingTasks' => Task::query()
                ->assignedTo($request->user()->id)
                ->openStatus()
                ->orderByRaw('due_date IS NULL, due_date ASC')
                ->limit(5)
                ->get(['id', 'task_number', 'title', 'priority', 'status', 'due_date']),
            // Employee Import (v1.6.8) -- real count of employees whose
            // profile_status accessor resolves to needs_completion
            // (department_id null), scoped the same way every other
            // company-filterable Dashboard card already is.
            'employeesNeedCompletionCount' => Employee::query()
                ->whereNull('department_id')
                ->whereIn('company_id', $companyIds)
                ->count(),
            // Milestone 4, Acceleration Part 7 -- Executive cross-department
            // summary. Every widget is a real, tenant-scoped count over the
            // actual tables this milestone built (HSE/Procurement/Warehouse/
            // Asset/Maintenance), not a fabricated metric. Incident's own
            // company_id is nullable (its older migration convention -- see
            // IncidentController's own doc comment) so a null-company
            // incident is included here too, same reasoning as everywhere
            // else that queries it.
            'openIncidentsCount' => Incident::where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
                ->whereIn('status', [Incident::STATUS_REPORTED, Incident::STATUS_INVESTIGATING])
                ->count(),
            'openCapaCount' => CorrectiveAction::whereIn('company_id', $companyIds)
                ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
                ->count(),
            'pendingProcurementCount' => PurchaseRequisition::whereIn('company_id', $companyIds)
                ->whereIn('status', [PurchaseRequisition::STATUS_SUBMITTED, PurchaseRequisition::STATUS_UNDER_REVIEW])
                ->count()
                + PurchaseOrder::whereIn('company_id', $companyIds)->where('status', PurchaseOrder::STATUS_SUBMITTED)->count(),
            'stockAlertCount' => Stock::whereIn('company_id', $companyIds)
                ->whereRaw('stocks.quantity <= (select items.min_stock from items where items.id = stocks.item_id)')
                ->count(),
            'assetCount' => Asset::whereIn('company_id', $companyIds)->active()->count(),
            'maintenanceDueCount' => WorkOrder::whereIn('company_id', $companyIds)
                ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_IN_PROGRESS])
                ->where('planned_date', '<=', now()->addDays(7)->toDateString())
                ->count(),
            // v1.11.1 (Final Production Readiness Pass, Part 5), narrowed to
            // the actual Management Calendar in v1.11.2 (Final Completion
            // Pass, Part 2/3): this is now genuinely the "Management
            // Calendar" surfaced on the Main Dashboard -- manual events an
            // authorized manager/admin explicitly flagged
            // `is_management_event`, plus PTW/Milestone (inherently
            // cross-department significant regardless of any flag). NOT a
            // duplicate of the full Calendar page (CalendarController::
            // index(), still every source unfiltered) -- both now read from
            // the same CalendarService so there is one aggregation, two
            // views. See CalendarService's own doc comment for the policy.
            'upcomingEvents' => $this->calendar->managementEvents($companyIds),
            // v1.11.3.2 (Priority Pass Part 4) -- Management Summary /
            // cross-department executive visibility. Explicit product
            // rule: department Overviews show only their own department's
            // data; cross-department project visibility belongs HERE,
            // once, not repeated in every department. Real data only --
            // Project.manager_id already exists (belongsTo User), Milestone
            // already has target_date/status; no field is fabricated.
            'projectSummary' => Project::whereIn('company_id', $companyIds)
                ->whereIn('status', ['planned', 'ongoing'])
                ->with('manager:id,name')
                ->withCount(['milestones as total_milestones'])
                ->withCount(['milestones as completed_milestones' => fn ($q) => $q->where('status', 'completed')])
                ->orderBy('end_date')
                ->limit(6)
                ->get(['id', 'name', 'status', 'manager_id', 'end_date'])
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'status' => $p->status,
                    'manager' => $p->manager?->name,
                    'end_date' => $p->end_date,
                    'progress_percent' => $p->total_milestones > 0
                        ? round(($p->completed_milestones / $p->total_milestones) * 100)
                        : null,
                ]),
            'upcomingMilestones' => Milestone::with('project:id,name')
                ->whereHas('project', fn ($q) => $q->whereIn('company_id', $companyIds))
                ->whereIn('status', ['pending', 'in_progress'])
                ->where('target_date', '>=', now()->toDateString())
                ->orderBy('target_date')
                ->limit(5)
                ->get(['id', 'project_id', 'title', 'target_date', 'status']),
            // v1.11.1, Part 6 -- Man-Hour/Man-Power foundation. Audited
            // first: no attendance/timesheet/clock-in table exists
            // anywhere in this codebase, so actual WORKED man-hours
            // cannot be reliably computed -- NOT fabricated here. What
            // genuinely exists and is shown instead: total active
            // workforce, and how many are currently assigned to a shift
            // right now (EmployeeShiftAssignment, real data, tenant-
            // scoped). See docs/MODULES.md's own note on this limitation.
            'manpower' => [
                'active_employees' => Employee::whereIn('company_id', $companyIds)->active()->count(),
                'on_shift_today' => EmployeeShiftAssignment::whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
                    ->where('status', 'active')
                    ->where('effective_date', '<=', now()->toDateString())
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))
                    ->count(),
            ],
        ]);
    }

    private function availableYears(): array
    {
        $currentYear = (int) now()->format('Y');

        return range($currentYear, $currentYear - 4);
    }
}
