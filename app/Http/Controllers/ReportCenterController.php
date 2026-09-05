<?php

namespace App\Http\Controllers;

use App\Exports\AnalyticsDatasetExport;
use App\Models\ActivityLog;
use App\Models\CompanySetting;
use App\Models\ReportSchedule;
use App\Services\AnalyticsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Milestone 3 (Report Center, Task #65). A generic PDF/Excel/CSV
 * download surface over the Analytics Framework's dataset registry
 * (config/analytics.php) -- every dataset already registered there gets
 * Preview + 3 export formats + Scheduled Report for free; a new module
 * that registers an Analytics dataset automatically gains a Report
 * Center entry too, with zero code here.
 *
 * Deliberately reuses AnalyticsService rather than re-querying models --
 * "Preview" IS the same data the download will contain, not a
 * lookalike, so there's no risk of the two ever disagreeing.
 */
class ReportCenterController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $enabledKeys = $this->enabledModuleKeys($request);
        $available = $this->analytics->available($enabledKeys);

        return Inertia::render('ReportCenter/Index', [
            'available' => $available,
            'schedules' => ReportSchedule::with('company:id,name')
                ->where('user_id', $user->id)
                ->latest('id')
                ->get()
                ->map(fn (ReportSchedule $s) => [
                    'id' => $s->id,
                    'dataset_key' => $s->dataset_key,
                    'format' => $s->format,
                    'frequency' => $s->frequency,
                    'is_active' => $s->is_active,
                    'last_run_at' => $s->last_run_at?->diffForHumans(),
                    'next_run_at' => $s->next_run_at?->toDateTimeString(),
                    'company' => $s->company?->name,
                ]),
        ]);
    }

    public function preview(Request $request, string $key)
    {
        $this->assertVisible($request, $key);

        return response()->json($this->analytics->dataset($key));
    }

    public function exportCsv(Request $request, string $key)
    {
        $this->assertVisible($request, $key);
        $dataset = $this->analytics->dataset($key);

        ActivityLog::record('exported', "Exported report [{$key}] to CSV.");

        $rows = ["Category,Count"];
        foreach ($dataset['labels'] as $i => $label) {
            $rows[] = '"'.str_replace('"', '""', $label).'",'.($dataset['values'][$i] ?? 0);
        }

        return response(implode("\n", $rows), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$key.'.csv"',
        ]);
    }

    public function exportExcel(Request $request, string $key)
    {
        $this->assertVisible($request, $key);
        $dataset = $this->analytics->dataset($key);

        ActivityLog::record('exported', "Exported report [{$key}] to Excel.");

        return Excel::download(new AnalyticsDatasetExport($dataset), $key.'.xlsx');
    }

    public function exportPdf(Request $request, string $key)
    {
        $this->assertVisible($request, $key);
        $dataset = $this->analytics->dataset($key);

        ActivityLog::record('exported', "Exported report [{$key}] to PDF.");

        $pdf = Pdf::loadView('exports.analytics-dataset-pdf', [
            'dataset' => $dataset,
            'companyName' => CompanySetting::get('company_name', config('ioms.name')),
        ])->setPaper('a4', 'portrait');

        return $pdf->download($key.'.pdf');
    }

    public function storeSchedule(Request $request)
    {
        $user = $request->user();
        $available = array_column($this->analytics->available($this->enabledModuleKeys($request)), 'key');

        $validated = Validator::make($request->all(), [
            'dataset_key' => ['required', 'string', Rule::in($available)],
            'format' => ['required', Rule::in(['csv', 'excel', 'pdf'])],
            'frequency' => ['required', Rule::in([ReportSchedule::FREQUENCY_DAILY, ReportSchedule::FREQUENCY_WEEKLY, ReportSchedule::FREQUENCY_MONTHLY])],
        ])->validate();

        $schedule = ReportSchedule::create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'dataset_key' => $validated['dataset_key'],
            'format' => $validated['format'],
            'frequency' => $validated['frequency'],
            'is_active' => true,
        ]);
        $schedule->update(['next_run_at' => $schedule->computeNextRunAt()]);

        ActivityLog::record('created', "Scheduled a {$validated['frequency']} {$validated['format']} report for [{$validated['dataset_key']}].", $schedule);

        return back()->with('success', 'Scheduled report created.');
    }

    public function destroySchedule(Request $request, ReportSchedule $reportSchedule)
    {
        abort_unless($reportSchedule->user_id === $request->user()->id, 404);

        $reportSchedule->delete();

        return back()->with('success', 'Scheduled report removed.');
    }

    private function assertVisible(Request $request, string $key): void
    {
        $available = array_column($this->analytics->available($this->enabledModuleKeys($request)), 'key');

        abort_unless(in_array($key, $available, true), 404);
    }

    private function enabledModuleKeys(Request $request): array
    {
        $user = $request->user();

        if (! $user?->tenant) {
            return [];
        }

        $grantedKeys = $user->tenant->modules()->pluck('key')->all();
        $stored = json_decode(
            CompanySetting::where('key', 'enabled_modules')->value('value') ?? json_encode($grantedKeys),
            true
        ) ?? $grantedKeys;

        return array_values(array_intersect($stored, $grantedKeys));
    }
}
