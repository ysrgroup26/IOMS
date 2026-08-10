<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\P3kBox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Milestone 4, Workstream B12 (P3K). Master CRUD, rendered on the shared Hse/Master.jsx page. */
class P3kBoxController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'location' => ['required', 'string', 'max:255'],
            'last_inspection_date' => ['nullable', 'date'],
            'next_inspection_due' => ['nullable', 'date'],
            'status' => ['required', Rule::in(P3kBox::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['inspected_by'] = $request->user()->id;

        $box = P3kBox::create($data);
        ActivityLog::record('created', "P3K box at \"{$box->location}\" was added.", $box);

        return back()->with('success', 'P3K box added.');
    }

    public function update(Request $request, P3kBox $p3kBox): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($p3kBox->company_id), 404);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'location' => ['required', 'string', 'max:255'],
            'last_inspection_date' => ['nullable', 'date'],
            'next_inspection_due' => ['nullable', 'date'],
            'status' => ['required', Rule::in(P3kBox::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['inspected_by'] = $request->user()->id;

        $p3kBox->update($data);
        ActivityLog::record('updated', "P3K box at \"{$p3kBox->location}\" was updated.", $p3kBox);

        return back()->with('success', 'P3K box updated.');
    }

    public function destroy(Request $request, P3kBox $p3kBox): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($p3kBox->company_id), 404);

        $location = $p3kBox->location;
        $p3kBox->delete();
        ActivityLog::record('deleted', "P3K box at \"{$location}\" was removed.");

        return back()->with('success', 'P3K box removed.');
    }
}
