<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\DailyReport;
use App\Models\Employee;
use App\Models\Task;
use App\Services\DashboardStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardStatsService $stats) {}

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

        $recentDailyReports = DailyReport::with('project:id,name')
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
        // needed to surface "recent employee changes".
        $recentEmployeeChanges = ActivityLog::where('subject_type', Employee::class)
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
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->count(),
        ]);
    }

    private function availableYears(): array
    {
        $currentYear = (int) now()->format('Y');

        return range($currentYear, $currentYear - 4);
    }
}
