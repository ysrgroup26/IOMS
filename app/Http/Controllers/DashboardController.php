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
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Stock;
use App\Models\Task;
use App\Models\WorkOrder;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use App\Services\WorkCenterService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $stats,
        private readonly CalendarService $calendar,
        private readonly WorkCenterService $workCenter,
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
    /**
     * v2.7.0 (Field/Foreman Experience pass, Phase 3A). `dashboard` is
     * the one universal landing route every role already lands on
     * post-login (see AuthenticatedSessionController -- only Platform
     * Admin has a separate redirect) and is already in
     * RestrictDepartmentAccess::UNIVERSAL_PREFIXES, so branching HERE
     * needed no new route, no middleware change, and no risk to the
     * existing enterprise Dashboard path below (untouched, same
     * queries, same props, same page).
     *
     * Audit finding this pass acted on: `department_key`
     * (`User::isDepartmentUser()`) is the ONLY existing mechanism that
     * already narrows a user's experience to one operational area, is
     * already live (Settings > Users + `php artisan
     * users:assign-department`), and is already consumed by
     * `WorkCenterService::quickActionsFor()`'s department filter -- no
     * dedicated "Foreman" role exists anywhere in this codebase (6 real
     * roles total: super_admin/hse/hrd/manager/warehouse/platform_admin,
     * confirmed via a full audit of User.php before writing this), and
     * inventing one would violate the explicit "do not invent a new RBAC
     * system" instruction. Reusing `isDepartmentUser()` here is a
     * deliberate, documented MVP proxy -- it will also affect an
     * office-based, single-department user who isn't literally in the
     * field, not just literal Foremen; that's an accepted, honest
     * limitation of this pass (see the Phase 3 audit report), not a
     * hidden assumption. A future pass can add a finer per-account
     * "experience mode" preference if that proves too coarse in
     * practice -- deliberately NOT built now, since department_key
     * already does the job for this MVP and adding a new column wasn't
     * "absolutely required."
     */
    public function index(Request $request): Response
    {
        if ($request->user()->isDepartmentUser()) {
            return $this->fieldHome($request);
        }

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
            // v2.2.0 (IOMS OS Ecosystem pass, Part 5 -- Quick Actions): was
            // 4 hardcoded links (New Project/Daily Report/New Employee/
            // Issue PPE) baked directly into Dashboard/Index.jsx, shown to
            // every user regardless of role/module/department. Replaced
            // with the same module-gated, role-gated, department-tagged
            // list Work Center's own Quick Actions bar already uses (see
            // WorkCenterService::quickActionsFor()'s own doc comment) --
            // one implementation, two surfaces, per this app's established
            // "topbar badge and full page share one query" convention.
            'quickActions' => $this->workCenter->quickActionsFor($request->user()),
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
            // v1.11.1, Part 6 -- Man-Power foundation. What genuinely
            // exists and is shown: total active workforce, and how many
            // are currently assigned to a shift right now
            // (EmployeeShiftAssignment, real data, tenant-scoped).
            'manpower' => [
                'active_employees' => Employee::whereIn('company_id', $companyIds)->active()->count(),
                'on_shift_today' => EmployeeShiftAssignment::whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
                    ->where('status', 'active')
                    ->where('effective_date', '<=', now()->toDateString())
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString()))
                    ->count(),
            ],
            // v1.11.6 (Production Readiness pass, Part 4) -- Man-Hour is
            // now a real, separate data source (ManHourLog, see its own
            // migration doc comment) and deliberately shown as a
            // DIFFERENT concept from Man-Power above, not conflated with
            // headcount. `null` (not 0) whenever zero rows exist yet for
            // a period, so an empty log reads as "not recorded" rather
            // than a real zero.
            'manhours' => [
                'today' => $this->stats->sumManHours($companyIds, now()->toDateString(), now()->toDateString()),
                'this_month' => $this->stats->sumManHours($companyIds, now()->startOfMonth()->toDateString(), now()->toDateString()),
                'ytd' => $this->stats->sumManHours($companyIds, now()->startOfYear()->toDateString(), now()->toDateString()),
            ],
        ]);
    }

    private function availableYears(): array
    {
        $currentYear = (int) now()->format('Y');

        return range($currentYear, $currentYear - 4);
    }

    /**
     * v2.7.0 (Field/Foreman Experience pass, Phase 3A). Task-first
     * landing page for a Department User -- greeting + a small set of
     * large, obviously-tappable action tiles (Create PTW/My PTW/Digital
     * Checklist/Safety Observation/Report Incident/My Tasks), each
     * gated by the SAME `canManage*()` capability method its own
     * destination page already enforces (no new authorization surface,
     * no gate weakened -- a tile simply doesn't render if the user
     * couldn't actually use what it links to). Every destination is an
     * EXISTING route (`permits-to-work.create`, `hse-inspections.create`,
     * `safety-observations.create`, `incidents.create`,
     * `permits-to-work.index`, `work-center.index`) -- no new pages
     * except the landing page itself, no duplicated backend logic.
     * `pendingApprovalsFor()`/`myTasksFor()` counts are reused verbatim
     * from `WorkCenterService` (the same queries the Work Center topbar
     * badge and page already use) rather than re-derived.
     */
    private function fieldHome(Request $request): Response
    {
        $user = $request->user();

        $tiles = [];
        if ($user->canManageHse()) {
            $tiles[] = ['label' => 'Create PTW', 'description' => 'Ajukan izin kerja baru.', 'href' => route('permits-to-work.create'), 'icon' => 'Flame'];
        }
        // v2.9.0 (Field/Foreman Experience pass, Phase 3C): now points at
        // the new field-oriented `permits-to-work.mine` (My PTW) instead
        // of the enterprise `permits-to-work.index` -- same underlying
        // data, requester-scoped and card-based instead of the full
        // enterprise table. Description shows real pending/active counts
        // (from the same tenant+requester-scoped query `myIndex()` uses)
        // rather than a generic label, matching the product's own "3
        // pending approval, 2 active" example.
        $tenantCompanyIds = $this->stats->resolveCompanyIds(null);
        $myPtwPendingCount = PermitToWork::whereIn('company_id', $tenantCompanyIds)
            ->where('requested_by', $user->id)
            ->where('status', PermitToWork::STATUS_SUBMITTED)
            ->count();
        $myPtwActiveCount = PermitToWork::whereIn('company_id', $tenantCompanyIds)
            ->where('requested_by', $user->id)
            ->where('status', PermitToWork::STATUS_ACTIVE)
            ->count();
        $myPtwParts = array_filter([
            $myPtwPendingCount > 0 ? "{$myPtwPendingCount} menunggu persetujuan" : null,
            $myPtwActiveCount > 0 ? "{$myPtwActiveCount} aktif" : null,
        ]);
        $myPtwDescription = $myPtwParts ? implode(', ', $myPtwParts) : 'Lihat status izin kerja Anda.';
        $tiles[] = ['label' => 'My PTW', 'description' => $myPtwDescription, 'href' => route('permits-to-work.mine'), 'icon' => 'ClipboardList'];
        if ($user->canManageHse()) {
            $tiles[] = ['label' => 'Digital Checklist', 'description' => 'Mulai inspeksi/checklist baru.', 'href' => route('hse-inspections.create'), 'icon' => 'ClipboardCheck'];
        }
        if ($user->canManageSafetyObservations()) {
            $tiles[] = ['label' => 'Safety Observation', 'description' => 'Laporkan temuan/observasi.', 'href' => route('safety-observations.create'), 'icon' => 'Eye'];
        }
        if ($user->canManageIncidents()) {
            $tiles[] = ['label' => 'Report Incident', 'description' => 'Laporkan insiden dengan cepat.', 'href' => route('incidents.create'), 'icon' => 'AlertTriangle'];
        }
        // v2.11.0 (Field/Foreman Experience pass, Phase 3H.5): of the
        // four "secondary where justified" candidates the directive
        // named (JSA/HIRADC/Gas Test/LOTO), only LOTO is added here as
        // its own tile. Reasoning, not an oversight: JSA/HIRADC are
        // multi-field risk-assessment DOCUMENTS (5 header fields + N
        // detailed rows each) typically prepared ahead of time by HSE,
        // not a 30-second field action, and are already reachable in
        // context from an approved PTW's own "HIRADC: ... / JSA: ..."
        // links (Phase 3D); Gas Test already has two real entry points
        // (PTW Show's own inline form, and GasTestRecords/Index.jsx's
        // dialog) and needs no third. LOTO is different: it previously
        // had ZERO entry point anywhere outside directly typing its URL
        // -- not in quickActionsFor(), not here -- despite having a
        // real, working, standalone Create page since an earlier pass.
        // Adding 4 tiles here would also directly violate the explicit
        // "do not create a giant wall of buttons, keep Field Home
        // simple" instruction; one genuinely-missing entry point does
        // not.
        if ($user->canManageHse()) {
            $tiles[] = ['label' => 'LOTO', 'description' => 'Catat isolasi Lock-Out Tag-Out.', 'href' => route('loto-records.create'), 'icon' => 'Lock'];
        }
        $tiles[] = ['label' => 'My Tasks', 'description' => 'Persetujuan dan tugas yang menunggu Anda.', 'href' => route('work-center.index'), 'icon' => 'CheckSquare'];

        return Inertia::render('Field/Home', [
            'tiles' => $tiles,
            'pendingApprovalsCount' => $this->workCenter->pendingApprovalsFor($user)->count(),
            'myTasksCount' => $this->workCenter->myTasksFor($user)->count(),
        ]);
    }
}
