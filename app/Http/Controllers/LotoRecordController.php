<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\LotoRecord;
use App\Models\PermitToWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Workstream B8 (LOTO). Standalone list + apply/release actions -- no HasWorkflow, see LotoRecord's own doc comment. */
class LotoRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $lotoRecords = LotoRecord::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('permitToWork:id,ptw_number', 'applier:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('loto_number', 'like', "%{$v}%")->orWhere('equipment_name', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('applied_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('LotoRecords/Index', [
            'lotoRecords' => $lotoRecords,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('LotoRecords/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'permits' => PermitToWork::whereIn('company_id', $tenantCompanyIds)->whereIn('status', [PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_ACTIVE])->get(['id', 'ptw_number']),
            'lotoNumber' => LotoRecord::generateNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);

        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantPermitIds = PermitToWork::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'permit_to_work_id' => ['nullable', Rule::in($tenantPermitIds)],
            'equipment_name' => ['required', 'string', 'max:255'],
            'isolation_points' => ['nullable', 'array'],
            'isolation_points.*.equipment' => ['nullable', 'string', 'max:255'],
            'isolation_points.*.type' => ['nullable', 'string', 'max:100'],
            'isolation_points.*.tag_number' => ['nullable', 'string', 'max:100'],
            'isolation_points.*.location' => ['nullable', 'string', 'max:255'],
            'applied_at' => ['required', 'date'],
        ]);

        $loto = LotoRecord::create([
            ...$data,
            'loto_number' => LotoRecord::generateNumber(),
            'status' => LotoRecord::STATUS_ISOLATED,
            'applied_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Applied LOTO {$loto->loto_number} on {$loto->equipment_name}.", $loto);

        return redirect()->route('loto-records.index')->with('flash', ['success' => 'LOTO applied.']);
    }

    public function release(Request $request, LotoRecord $lotoRecord): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($lotoRecord->company_id), 404);
        abort_if($lotoRecord->status === LotoRecord::STATUS_REMOVED, 422, 'Already released.');

        $lotoRecord->update([
            'status' => LotoRecord::STATUS_REMOVED,
            'removed_by' => $request->user()->id,
            'removed_at' => now(),
        ]);

        ActivityLog::record('updated', "Released LOTO {$lotoRecord->loto_number}.", $lotoRecord);

        return back()->with('success', 'LOTO released.');
    }
}
