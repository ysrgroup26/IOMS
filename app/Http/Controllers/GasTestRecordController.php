<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\GasTestRecord;
use App\Models\PermitToWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Milestone 4, Workstream B7 (Gas Test). Nested under a PermitToWork -- store/destroy only, no separate index/show. */
class GasTestRecordController extends Controller
{
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
