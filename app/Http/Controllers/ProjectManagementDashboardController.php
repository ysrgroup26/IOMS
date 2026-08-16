<?php

namespace App\Http\Controllers;

use App\Models\DailyReportActivity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Services\CalendarService;
use App\Services\DashboardStatsService;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project Management Department Dashboard (v1.10.0, redesigned v1.11.5 --
 * Dashboard UX Completion, Phase 4). Milestone 4, Acceleration Part 3/7:
 * average Project Activity progress now has a real backing data model
 * (ProjectActivity) and is included below.
 *
 * v1.11.5 adds a real Project Portfolio dataset (Phase 4's requirement for
 * a compact TABLE, not a grid of project cards): for every active/ongoing
 * project, its manager (`Project::manager()`, already an existing
 * `belongsTo(User::class,'manager_id')` relation), its own milestone
 * completion percentage (same numerator/denominator pattern already used
 * for the department-wide `milestoneCompletionPercent` below, just
 * per-project instead of aggregated), and its single nearest upcoming
 * milestone. No new relations or columns were added -- this only
 * re-queries relations that already existed.
 *
 * The doc comment that used to say "no calendar/scheduling data model
 * exists" was stale -- `departmentCalendar` below has used
 * `CalendarService::departmentEvents()` since an earlier pass; corrected
 * here rather than left to keep contradicting the code beneath it.
 *
 * Tenant-isolation fix (found while extending this controller for the
 * Activity widget, same discipline as every other dashboard fixed this
 * milestone): every existing query here had ZERO company scoping. Fixed
 * via DashboardStatsService::resolveCompanyIds().
 */
class ProjectManagementDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardStatsService $dashboardStats,
        private readonly CalendarService $calendar,
    ) {}

    public function index(): Response
    {
        $today = Carbon::today();
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        $projectIds = Project::whereIn('company_id', $companyIds)->pluck('id');
        $totalMilestones = Milestone::whereIn('project_id', $projectIds)->count();
        $completedMilestones = Milestone::whereIn('project_id', $projectIds)->where('status', 'completed')->count();
        $avgActivityProgress = ProjectActivity::whereIn('project_id', $projectIds)->avg('progress');

        return Inertia::render('ProjectManagement/Dashboard', [
            'activeProjectsCount' => Project::whereIn('company_id', $companyIds)->whereIn('status', ['planned', 'ongoing'])->count(),
            'delayedProjectsCount' => Project::whereIn('company_id', $companyIds)->where('status', 'ongoing')->whereDate('end_date', '<', $today)->count(),
            'milestoneCompletionPercent' => $totalMilestones > 0 ? round(($completedMilestones / $totalMilestones) * 100) : null,
            'avgActivityProgressPercent' => $avgActivityProgress !== null ? round($avgActivityProgress) : null,
            'todaysActivitiesCount' => DailyReportActivity::whereHas('dailyReport', fn ($q) => $q->whereDate('report_date', $today)->whereHas('project', fn ($p) => $p->whereIn('company_id', $companyIds)))->count(),
            'upcomingMilestones' => Milestone::with('project:id,name')
                ->whereIn('project_id', $projectIds)
                ->whereIn('status', ['pending', 'in_progress'])
                ->where('target_date', '>=', $today)
                ->orderBy('target_date')
                ->limit(5)
                ->get(['id', 'project_id', 'title', 'target_date', 'status']),
            'delayedProjects' => Project::whereIn('company_id', $companyIds)
                ->where('status', 'ongoing')
                ->whereDate('end_date', '<', $today)
                ->orderBy('end_date')
                ->limit(5)
                ->get(['id', 'name', 'end_date']),
            // "Project Portfolio" -- compact table dataset, Phase 4. Reuses
            // the exact milestone-completion formula above, just scoped
            // per-project via `loadCount`/`load` instead of one aggregate
            // query, plus each project's single nearest open milestone.
            'projectPortfolio' => Project::whereIn('company_id', $companyIds)
                ->whereIn('status', ['planned', 'ongoing'])
                ->with(['manager:id,name'])
                ->orderBy('end_date')
                ->limit(10)
                ->get(['id', 'name', 'manager_id', 'status', 'end_date'])
                ->map(function (Project $p) {
                    $total = Milestone::where('project_id', $p->id)->count();
                    $completed = Milestone::where('project_id', $p->id)->where('status', 'completed')->count();
                    $nextMilestone = Milestone::where('project_id', $p->id)
                        ->whereIn('status', ['pending', 'in_progress'])
                        ->orderBy('target_date')
                        ->first(['title', 'target_date']);

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'manager' => $p->manager?->name,
                        'status' => $p->status,
                        'progress_percent' => $total > 0 ? round(($completed / $total) * 100) : null,
                        'next_milestone' => $nextMilestone?->title,
                        'next_milestone_date' => $nextMilestone?->target_date,
                        'end_date' => $p->end_date,
                    ];
                }),
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'project-management'),
        ]);
    }
}
