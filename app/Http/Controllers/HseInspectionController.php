<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\HseInspection;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Workstream B2 (HSE Inspection). */
class HseInspectionController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $inspections = HseInspection::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'inspector:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('inspection_number', 'like', "%{$v}%"))
            ->when($request->input('type'), fn ($q, $v) => $q->where('inspection_type', $v))
            ->when($request->input('result'), fn ($q, $v) => $q->where('overall_result', $v))
            ->latest('inspection_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('HseInspections/Index', [
            'inspections' => $inspections,
            'filters' => $request->only('search', 'type', 'result'),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('HseInspections/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'inspectionNumber' => HseInspection::generateNumber(),
            'types' => HseInspection::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);

        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'inspection_type' => ['required', Rule::in(HseInspection::TYPES)],
            'location' => ['nullable', 'string', 'max:255'],
            'inspection_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'checklist_items' => ['nullable', 'array'],
            'checklist_items.*.item' => ['nullable', 'string', 'max:500'],
            'checklist_items.*.result' => ['nullable', Rule::in(['ok', 'not_ok', 'na'])],
            'checklist_items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $overallResult = collect($data['checklist_items'] ?? [])->contains(fn ($i) => ($i['result'] ?? null) === 'not_ok') ? 'fail' : 'pass';

        $inspection = HseInspection::create([
            ...$data,
            'inspection_number' => HseInspection::generateNumber(),
            'overall_result' => $overallResult,
            'inspector_id' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Recorded HSE Inspection {$inspection->inspection_number} ({$overallResult}).", $inspection);

        return redirect()->route('hse-inspections.show', $inspection)->with('flash', ['success' => 'Inspection recorded.']);
    }

    public function show(HseInspection $hseInspection, Request $request): Response
    {
        abort_unless(Company::query()->pluck('id')->contains($hseInspection->company_id), 404);
        $hseInspection->load('company:id,name', 'project:id,name', 'inspector:id,name', 'correctiveActions.assignee:id,name');

        $activities = ActivityLog::where('subject_type', HseInspection::class)
            ->where('subject_id', $hseInspection->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        $tenantId = app(CurrentTenant::class)->id();

        return Inertia::render('HseInspections/Show', [
            'inspection' => $hseInspection,
            'activities' => $activities,
            'canManage' => $request->user()->canManageHse(),
            'users' => User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Turns one checklist finding into a real, reusable CorrectiveAction row. */
    public function raiseFinding(Request $request, HseInspection $hseInspection): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($hseInspection->company_id), 404);

        $tenantId = app(CurrentTenant::class)->id();
        $tenantUserIds = User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');

        $data = $request->validate([
            'action' => ['required', 'string', 'max:500'],
            'assigned_to' => ['nullable', Rule::in($tenantUserIds)],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(CorrectiveAction::PRIORITIES)],
        ]);

        $hseInspection->correctiveActions()->create([
            ...$data,
            'company_id' => $hseInspection->company_id,
            'status' => CorrectiveAction::STATUS_OPEN,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Raised a corrective action from {$hseInspection->inspection_number}.", $hseInspection);

        return back()->with('success', 'Corrective action raised.');
    }
}
