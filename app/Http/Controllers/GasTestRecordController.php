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
 * taken for, unlike LotoRecord which can exist independently), so there
 * is deliberately no standalone "create" form here -- that would just
 * duplicate the exact same permit-picker the PTW Show page's own embedded
 * form already provides, for no benefit.
 *
 * v1.10.7: added index() -- a read-only, company-wide, cross-permit list
 * (mirrors LotoRecordController::index()'s shape) so gas test history is
 * actually discoverable from HSE navigation, not only by opening the one
 * permit it happened to be recorded against. Genuinely new code, not a
 * duplicate: the create/destroy actions below are completely unchanged
 * and still the only way to add/remove a reading.
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
            ->latest('tested_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('GasTestRecords/Index', [
            'gasTests' => $gasTests,
            'filters' => $request->only('result'),
            'results' => GasTestRecord::RESULTS,
        ]);
    }

    public function store(Request $request, PermitToWork $permitToWork): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($permitToWork->company_id), 404);

        $data = $request->validate([
            'tested_at' => ['required', 'date'],
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

        ActivityLog::record('created', "Gas test recorded ({$gasTest->result}) for {$permitToWork->ptw_number}.", $permitToWork);

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
