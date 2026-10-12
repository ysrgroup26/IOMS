<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\HseChecklistTemplate;
use App\Models\HseInspection;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentEngine;
use App\Services\PdfGeneratorService;
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
            // v1.11.2 (Final Completion Pass, Part 9) -- checklist templates
            // grouped by category so the Form can offer "Load Template" for
            // whichever inspection_type is currently selected, without a
            // second inspection engine.
            'checklistTemplates' => HseChecklistTemplate::whereIn('company_id', $tenantCompanyIds)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'company_id', 'category', 'name', 'items']),
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

    /**
     * v2.110.0 -- THE INSPECTION AS A FILEABLE DOCUMENT.
     *
     * HSE Inspection was the one HSE record with no printable output:
     * Permit to Work, Material Request, Goods Receipt and the Employee
     * Register all had one, and an inspection -- the record a client
     * auditor is most likely to ask for on paper -- did not.
     *
     * Deliberately the SAME tenant gate as `show()` (membership of the
     * current tenant's own company ids, 404 otherwise) and deliberately
     * NO extra role gate: anyone who may view the inspection may print
     * it. Gating the PDF behind `canManageHse()` while leaving `show()`
     * open would hide a document from someone already reading its
     * contents on screen.
     *
     * `streamInline()` so one URL serves both the Download link and the
     * Print button, exactly as PermitToWorkController::pdf() does.
     */
    public function pdf(HseInspection $hseInspection, PdfGeneratorService $pdf, DocumentEngine $documents): \Illuminate\Http\Response
    {
        abort_unless(Company::query()->pluck('id')->contains($hseInspection->company_id), 404);

        // correctiveActions is eager-loaded here because the Blade view
        // renders the CAPA section only when the relation is LOADED --
        // an unloaded relation would lazily fetch inside the template,
        // and a missing section would silently drop real findings.
        $hseInspection->load(
            'company:id,name',
            'project:id,name',
            'inspector:id,name',
            'correctiveActions.assignee:id,name',
        );

        ActivityLog::record('exported', "Generated the PDF for HSE Inspection {$hseInspection->inspection_number}.", $hseInspection);

        return $pdf->streamInline('pdf.hse-inspection', [
            'inspection' => $hseInspection,
            'identity' => $documents->identity(),
            'generatedAt' => now(),
        ], "{$hseInspection->inspection_number}.pdf");
    }
}
