<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDailyReportRequest;
use App\Http\Requests\UpdateDailyReportRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\DailyReport;
use App\Models\DailyReportPhoto;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectTimelineEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily Report. A project may have multiple reports on the same date
 * (different departments, shifts, or activities). Deliberately does NOT
 * ask for manpower or PPE -- those already live in Project Manpower and
 * PPE Distribution. Each report represents a DEPARTMENT (free text,
 * v1.5.1 -- no master list to maintain) rather than an individual;
 * `created_by` is still recorded for internal audit purposes only and is
 * never shown in this module's UI. On create/update, writes a summary
 * event to the Project Timeline (activities + date only, per spec's
 * explicit exclusion of Toolbox Meeting/PPE/Employee PPE from the
 * timeline) so timeline entries are derived from this module rather than
 * entered twice.
 */
class DailyReportController extends Controller
{
    public function index(Request $request): Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $reports = DailyReport::query()
            ->with('project:id,name,company_id', 'project.company:id,name')
            ->withCount('activities', 'photos')
            ->when($companyId, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('company_id', $companyId)))
            ->when($request->input('project_id'), fn ($q, $v) => $q->where('project_id', $v))
            ->when($request->input('report_type'), fn ($q, $v) => $q->where('report_type', $v))
            ->latest('report_date')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('DailyReports/Index', [
            'reports' => $reports,
            'projects' => Project::inCompany($companyId)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('company_id', 'project_id', 'report_type'),
            'can' => ['manage' => $request->user()->canManageDailyReports()],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', DailyReport::class);

        return Inertia::render('DailyReports/Form', [
            'projects' => Project::orderBy('name')->get(['id', 'name', 'company_id']),
            // Suggestions only, not a restricted list -- the field stays
            // free text (v1.5.1: "every company can type their own
            // department names without maintaining a master list").
            // These are the already-configured official Department
            // names, offered as autocomplete suggestions so someone
            // typing "hs" sees "HSE" without needing to remember exact
            // spelling/casing, while still being free to type anything
            // else entirely.
            'departmentSuggestions' => Department::where('is_active', true)->ordered()->pluck('name')->unique()->values(),
            'report' => null,
        ]);
    }

    public function store(StoreDailyReportRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $activities = $data['activities'];
        $photos = $request->file('photos', []);
        unset($data['activities'], $data['photos']);
        $data['created_by'] = $request->user()->id; // internal audit only, not shown in UI

        $report = DB::transaction(function () use ($data, $activities, $photos) {
            $report = DailyReport::create($data);

            foreach ($activities as $i => $description) {
                $report->activities()->create(['description' => $description, 'sort_order' => $i]);
            }

            foreach ($photos as $photo) {
                $path = $photo->store('uploads/daily-reports', 'public');
                $report->photos()->create(['photo_path' => $path]);
            }

            return $report;
        });

        $report->load('project', 'activities');
        $this->writeTimelineEvent($report);

        ActivityLog::record('created', "Daily report for {$report->project->name} on {$report->report_date->format('d M Y')} was created.", $report);

        return redirect()->route('daily-reports.show', $report)->with('success', 'Daily report submitted.');
    }

    public function show(DailyReport $dailyReport): Response
    {
        $dailyReport->load('project.company', 'activities', 'photos');

        return Inertia::render('DailyReports/Show', [
            'report' => $dailyReport,
            'can' => ['manage' => request()->user()->canManageDailyReports()],
        ]);
    }

    public function edit(DailyReport $dailyReport): Response
    {
        $this->authorize('update', $dailyReport);

        $dailyReport->load('activities', 'photos');

        return Inertia::render('DailyReports/Form', [
            'projects' => Project::orderBy('name')->get(['id', 'name', 'company_id']),
            'departmentSuggestions' => Department::where('is_active', true)->ordered()->pluck('name')->unique()->values(),
            'report' => $dailyReport,
        ]);
    }

    public function update(UpdateDailyReportRequest $request, DailyReport $dailyReport): RedirectResponse
    {
        $data = $request->validated();
        $activities = $data['activities'];
        $photos = $request->file('photos', []);
        unset($data['activities'], $data['photos']);

        DB::transaction(function () use ($dailyReport, $data, $activities, $photos) {
            $dailyReport->update($data);

            $dailyReport->activities()->delete();
            foreach ($activities as $i => $description) {
                $dailyReport->activities()->create(['description' => $description, 'sort_order' => $i]);
            }

            foreach ($photos as $photo) {
                $path = $photo->store('uploads/daily-reports', 'public');
                $dailyReport->photos()->create(['photo_path' => $path]);
            }
        });

        $dailyReport->load('project', 'activities');
        $this->writeTimelineEvent($dailyReport, isUpdate: true);

        ActivityLog::record('updated', "Daily report for {$dailyReport->project->name} on {$dailyReport->report_date->format('d M Y')} was updated.", $dailyReport);

        return redirect()->route('daily-reports.show', $dailyReport)->with('success', 'Daily report updated.');
    }

    public function destroy(DailyReport $dailyReport): RedirectResponse
    {
        $this->authorize('delete', $dailyReport);

        $projectName = $dailyReport->project->name;
        $date = $dailyReport->report_date->format('d M Y');
        $dailyReport->delete();

        ActivityLog::record('deleted', "Daily report for {$projectName} on {$date} was removed.");

        return redirect()->route('daily-reports.index')->with('success', 'Daily report removed.');
    }

    /**
     * Removes a single already-saved documentation photo (v1.5.2) --
     * needed so the reusable MultiImageUpload component's "remove
     * existing image" action has a real endpoint to call, rather than
     * only being able to add new photos. Deletes both the storage file
     * and the database row.
     */
    public function destroyPhoto(DailyReport $dailyReport, DailyReportPhoto $photo): RedirectResponse
    {
        $this->authorize('update', $dailyReport);

        abort_if($photo->daily_report_id !== $dailyReport->id, 404);

        Storage::disk('public')->delete($photo->photo_path);
        $photo->delete();

        return back()->with('success', 'Photo removed.');
    }

    /**
     * Writes/refreshes the Project Timeline entry for this report. Only
     * carries the activity summary and date -- never manpower or PPE,
     * per spec. On update, the previous auto-written event for this
     * report is replaced rather than duplicated.
     */
    private function writeTimelineEvent(DailyReport $report, bool $isUpdate = false): void
    {
        if ($isUpdate) {
            ProjectTimelineEvent::where('subject_type', DailyReport::class)
                ->where('subject_id', $report->id)
                ->delete();
        }

        $summary = $report->activities->pluck('description')->implode(', ');

        ProjectTimelineEvent::record(
            $report->project_id,
            'daily_report',
            $summary ?: 'Daily Report',
            null,
            $report->report_date,
            $report,
        );
    }
}
