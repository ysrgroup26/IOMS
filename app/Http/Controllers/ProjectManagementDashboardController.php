<?php

namespace App\Http\Controllers;

use App\Models\DailyReportActivity;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project Management Department Dashboard (v1.10.0). Deliberately does
 * NOT include a Project Calendar widget -- no calendar/scheduling data
 * model exists in this app. "Delayed Projects" and "Project Progress"
 * are both computed from real, already-stored data (end_date vs. today,
 * milestone completion ratio) rather than a fabricated percentage.
 */
class ProjectManagementDashboardController extends Controller
{
    public function index(): Response
    {
        $today = Carbon::today();

        $totalMilestones = Milestone::count();
        $completedMilestones = Milestone::where('status', 'completed')->count();

        return Inertia::render('ProjectManagement/Dashboard', [
            'activeProjectsCount' => Project::whereIn('status', ['planned', 'ongoing'])->count(),
            'delayedProjectsCount' => Project::where('status', 'ongoing')->whereDate('end_date', '<', $today)->count(),
            'milestoneCompletionPercent' => $totalMilestones > 0 ? round(($completedMilestones / $totalMilestones) * 100) : null,
            'todaysActivitiesCount' => DailyReportActivity::whereHas('dailyReport', fn ($q) => $q->whereDate('report_date', $today))->count(),
            'upcomingMilestones' => Milestone::with('project:id,name')
                ->whereIn('status', ['pending', 'in_progress'])
                ->where('target_date', '>=', $today)
                ->orderBy('target_date')
                ->limit(5)
                ->get(['id', 'project_id', 'title', 'target_date', 'status']),
            'delayedProjects' => Project::where('status', 'ongoing')
                ->whereDate('end_date', '<', $today)
                ->orderBy('end_date')
                ->limit(5)
                ->get(['id', 'name', 'end_date']),
        ]);
    }
}
