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
 * Project Management Department Dashboard (v1.10.0). Milestone 4,
 * Acceleration Part 3/7: average Project Activity progress now has a
 * real backing data model (ProjectActivity) and is included below.
 * Deliberately does NOT include a Project Calendar widget -- no calendar/
 * scheduling data model exists in this app.
 *
 * Tenant-isolation fix (found while extending this controller for the new
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
            'departmentCalendar' => $this->calendar->departmentEvents($companyIds, 'project-management'),
        ]);
    }
}
