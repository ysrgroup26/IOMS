<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\GasTestRecord;
use App\Models\PermitToWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B7 (Gas Test). Creation/deletion stays nested
 * under a PermitToWork (permit_to_work_id is NOT nullable on
 * GasTestRecord -- a reading is meaningless without the permit it was
 * taken for, unlike LotoRecord which can exist independently) -- there is
 * still only ONE store() endpoint, ONE model, ONE table.
 *
 * v1.10.7: added index() -- a read-only, company-wide, cross-permit list
 * (mirrors LotoRecordController::index()'s shape) so gas test history is
 * discoverable from HSE navigation, not only by opening the one permit it
 * was recorded against.
 *
 * v1.10.8: index() now also carries `permits` (approved/active, same
 * eligibility filter LotoRecordController::create() already uses) so the
 * Index page can offer an "Add Gas Test" dialog with a permit picker --
 * a second entry point into the SAME store() action below, not a second
 * creation mechanism. The PTW Show page's own embedded "Add Reading" form
 * (the original, always-existing entry point) is completely unchanged and
 * still posts to the exact same route.
 *
 * v1.10.9 (HSE Domain Hardening): `location` (free-text, where the
 * reading was actually taken -- pre-filled from the permit's own
 * `location` by the frontend, independently editable) and `stage`
 * (initial/re_test/final -- see GasTestRecord::STAGES) added to store()'s
 * validated data. Both are plain columns on the existing table (see that
 * migration's own doc comment for why no new model/relation was needed) --
 * store()/destroy()'s tenant-ownership checks are unchanged, since neither
 * new field affects tenant ownership at all.
 */
class GasTestRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $gasTests = GasTestRecord::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('permitToWork:id,ptw_number', 'tester:id,name')
            ->when($request->input('result'), fn ($q, $v) => $q->where('result', $v))
            ->when($request->input('stage'), fn ($q, $v) => $q->where('stage', $v))
            ->latest('tested_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('GasTestRecords/Index', [
            'gasTests' => $gasTests,
            'filters' => $request->only('result', 'stage'),
            'results' => GasTestRecord::RESULTS,
            'stages' => GasTestRecord::STAGES,
            'stageLabels' => GasTestRecord::STAGE_LABELS,
            'permits' => PermitToWork::whereIn('company_id', $tenantCompanyIds)
                ->whereIn('status', [PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_ACTIVE])
                ->orderByDesc('start_datetime')
                ->get(['id', 'ptw_number', 'location']),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function store(Request $request, PermitToWork $permitToWork): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($permitToWork->company_id), 404);

        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:255'],
            'tested_at' => ['required', 'date'],
            'stage' => ['required', Rule::in(GasTestRecord::STAGES)],
            'o2_level' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lel_level' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'h2s_level' => ['nullable', 'numeric', 'min:0'],
            'co_level' => ['nullable', 'numeric', 'min:0'],
            'result' => ['required', Rule::in(GasTestRecord::RESULTS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $gasTest = $permitToWork->gasTests()->create([
            ...$data,
            'company_id' => $permitToWork->company_id,
            'tested_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "{$gasTest->stageLabel()} gas test recorded ({$gasTest->result}) for {$permitToWork->ptw_number}.", $permitToWork);

        return back()->with('success', 'Gas test recorded.');
    }

    public function destroy(PermitToWork $permitToWork, GasTestRecord $gasTest, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless($gasTest->permit_to_work_id === $permitToWork->id, 404);
        abort_unless(Company::query()->pluck('id')->contains($permitToWork->company_id), 404);

        $gasTest->delete();

        return back()->with('success', 'Gas test removed.');
    }
}
