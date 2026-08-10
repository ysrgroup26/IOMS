<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\InspectionRequest;
use App\Models\Ncr;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 3 (NCR). Corrective action reuses the existing CorrectiveAction entity -- see Ncr's own doc comment. */
class NcrController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $ncrs = Ncr::whereIn('company_id', $tenantCompanyIds)
            ->with('raiser:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('ncr_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->latest('raised_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Ncrs/Index', [
            'ncrs' => $ncrs,
            'filters' => $request->only('search', 'status', 'severity'),
            'can' => ['manage' => $request->user()->canManageProjects()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageProjects(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $prefill = null;
        if ($request->filled('inspection')) {
            $inspection = InspectionRequest::whereIn('company_id', $tenantCompanyIds)->find($request->integer('inspection'));
            if ($inspection) {
                $prefill = ['source_type' => InspectionRequest::class, 'source_id' => $inspection->id, 'description' => "Failed QC Inspection {$inspection->inspection_number}: ".($inspection->notes ?? '')];
            }
        }

        return Inertia::render('Ncrs/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'ncrNumber' => Ncr::generateNumber(),
            'severities' => Ncr::SEVERITIES,
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageProjects(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'description' => ['required', 'string', 'max:2000'],
            'severity' => ['required', Rule::in(Ncr::SEVERITIES)],
            'responsible_party' => ['nullable', 'string', 'max:255'],
            'raised_date' => ['required', 'date'],
            'source_type' => ['nullable', 'string', 'in:'.InspectionRequest::class],
            'source_id' => ['nullable', 'integer'],
        ]);

        // IDOR guard on the polymorphic source -- only allow a source
        // record that actually belongs to the current tenant, same
        // Rule::in()-over-tenant-scoped-ids principle as everywhere else,
        // applied to a polymorphic reference instead of a plain FK.
        if (! empty($data['source_type']) && ! empty($data['source_id'])) {
            $validSourceIds = InspectionRequest::whereIn('company_id', $tenantCompanyIds)->pluck('id');
            if (! $validSourceIds->contains($data['source_id'])) {
                abort(404);
            }
        }

        $ncr = Ncr::create([
            ...$data,
            'ncr_number' => Ncr::generateNumber(),
            'status' => Ncr::STATUS_OPEN,
            'raised_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Raised NCR {$ncr->ncr_number}.", $ncr);

        return redirect()->route('ncrs.show', $ncr)->with('flash', ['success' => 'NCR raised.']);
    }

    public function show(Ncr $ncr, Request $request): Response
    {
        $this->assertInCurrentTenant($ncr);
        $ncr->load('raiser:id,name', 'correctiveActions.assignee:id,name');

        $tenantId = app(CurrentTenant::class)->id();

        return Inertia::render('Ncrs/Show', [
            'ncr' => $ncr,
            'canManage' => $request->user()->canManageProjects(),
            'users' => User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function raiseCorrectiveAction(Request $request, Ncr $ncr): RedirectResponse
    {
        abort_unless($request->user()->canManageProjects(), 403);
        $this->assertInCurrentTenant($ncr);

        $tenantId = app(CurrentTenant::class)->id();
        $tenantUserIds = User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');

        $data = $request->validate([
            'action' => ['required', 'string', 'max:500'],
            'assigned_to' => ['nullable', Rule::in($tenantUserIds)],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(CorrectiveAction::PRIORITIES)],
        ]);

        $ncr->correctiveActions()->create([
            ...$data,
            'company_id' => $ncr->company_id,
            'status' => CorrectiveAction::STATUS_OPEN,
            'created_by' => $request->user()->id,
        ]);

        $ncr->update(['status' => Ncr::STATUS_IN_PROGRESS]);

        ActivityLog::record('created', "Raised a corrective action from NCR {$ncr->ncr_number}.", $ncr);

        return back()->with('success', 'Corrective action raised.');
    }

    public function close(Ncr $ncr, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageProjects(), 403);
        $this->assertInCurrentTenant($ncr);

        $ncr->update(['status' => Ncr::STATUS_CLOSED]);
        ActivityLog::record('updated', "Closed NCR {$ncr->ncr_number}.", $ncr);

        return back()->with('success', 'NCR closed.');
    }

    private function assertInCurrentTenant(Ncr $ncr): void
    {
        abort_unless(Company::query()->pluck('id')->contains($ncr->company_id), 404);
    }
}
