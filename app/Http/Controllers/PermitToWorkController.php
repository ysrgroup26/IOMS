<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePermitToWorkRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\JobSafetyAnalysis;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\RiskAssessment;
use App\Services\DocumentEngine;
use App\Services\PdfGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B6 (Permit To Work). Structurally mirrors
 * RiskAssessmentController -- same authorization/tenant-guard shape.
 */
class PermitToWorkController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $permits = PermitToWork::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'requester:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('ptw_number', 'like', "%{$v}%")->orWhere('work_description', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('permit_type', $v))
            ->latest('start_datetime')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('PermitsToWork/Index', [
            'permits' => $permits,
            'filters' => $request->only('search', 'status', 'type'),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('PermitsToWork/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'riskAssessments' => RiskAssessment::whereIn('company_id', $tenantCompanyIds)->where('status', RiskAssessment::STATUS_APPROVED)->get(['id', 'ra_number', 'title']),
            'jsas' => JobSafetyAnalysis::whereIn('company_id', $tenantCompanyIds)->where('status', JobSafetyAnalysis::STATUS_APPROVED)->get(['id', 'jsa_number', 'job_title']),
            'ptwNumber' => PermitToWork::generateNumber(),
            'types' => PermitToWork::TYPES,
        ]);
    }

    /**
     * v2.4.0 (PTW UX + Field Operations pass, Phase 1). PREVIOUSLY: this
     * created the permit in STATUS_DRAFT and stopped -- the Create
     * form's only button says "Submit PTW", but the record actually
     * landed in an invisible Draft state, requiring a SEPARATE manual
     * "Submit" action on the Show page before HSE could see it as
     * pending at all. That is exactly the kind of hidden extra step the
     * product direction explicitly asks to remove ("Create PTW -> Submit
     * -> Pending Approval" is meant to be reached in one user action,
     * not two). Fixed by immediately transitioning draft -> submitted
     * via the existing `HasWorkflow::transitionTo()` state machine right
     * after creation -- reuses the SAME validated transition path
     * `PermitToWorkController::transition()` already uses elsewhere in
     * this file (no bypass of the state machine, no new status value),
     * so the allowed-transitions guard, ActivityLog entry, and
     * notification hook all still run exactly as they would for any
     * other transition.
     */
    public function store(StorePermitToWorkRequest $request): RedirectResponse
    {
        $permit = PermitToWork::create([
            ...$request->validated(),
            'ptw_number' => PermitToWork::generateNumber(),
            'status' => PermitToWork::STATUS_DRAFT,
            'requested_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Requested Permit To Work {$permit->ptw_number}.", $permit);
        $permit->transitionTo(PermitToWork::STATUS_SUBMITTED, $request->user());

        return redirect()->route('permits-to-work.show', $permit)
            ->with('flash', ['success' => 'PTW berhasil dibuat dan sedang menunggu persetujuan HSE.']);
    }

    public function show(PermitToWork $permitToWork, Request $request): Response
    {
        $this->assertInCurrentTenant($permitToWork);
        $permitToWork->load(
            'company:id,name', 'project:id,name', 'riskAssessment:id,ra_number', 'jsa:id,jsa_number',
            'requester:id,name', 'areaAuthority:id,name', 'hseApprover:id,name', 'closer:id,name',
            'gasTests.tester:id,name', 'lotoRecords'
        );

        $activities = ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permitToWork->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('PermitsToWork/Show', [
            'permit' => $permitToWork,
            'activities' => $activities,
            'canManage' => $request->user()->canManageHse(),
        ]);
    }

    public function transition(Request $request, PermitToWork $permitToWork): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($permitToWork);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                PermitToWork::STATUS_SUBMITTED, PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_REJECTED,
                PermitToWork::STATUS_ACTIVE, PermitToWork::STATUS_CLOSED, PermitToWork::STATUS_CANCELLED,
            ])],
            // v2.4.0 (PTW UX + Field Operations pass, Part 14): "If
            // rejecting: Require a short reason." Required only for
            // 'rejected' (see the rule below), optional/unused for every
            // other transition -- passed through to transitionTo()'s
            // existing `$meta['comments']` parameter, which
            // notifyStatusChange() already reads into the notification
            // body, so a rejected requester's notification now actually
            // says why, not just "PermitToWork X is now Rejected".
            'reason' => ['required_if:status,'.PermitToWork::STATUS_REJECTED, 'nullable', 'string', 'max:500'],
        ]);

        try {
            if ($data['status'] === PermitToWork::STATUS_APPROVED) {
                $permitToWork->hse_approver_id = $request->user()->id;
                $permitToWork->save();
            }
            if ($data['status'] === PermitToWork::STATUS_CLOSED) {
                $permitToWork->closed_by = $request->user()->id;
                $permitToWork->closed_at = now();
                $permitToWork->save();
            }
            $permitToWork->transitionTo($data['status'], $request->user(), meta: ['comments' => $data['reason'] ?? null]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Permit To Work '.$data['status'].'.']);
    }

    /**
     * v2.4.0 (PTW UX + Field Operations pass, Part 13 -- Document
     * Generation). PTW was explicitly named as a "future" consumer in
     * PdfGeneratorService's own doc comment -- never actually wired
     * in until now. Follows the EXACT same pattern as
     * MaterialRequestController::pdf() (the only other real consumer
     * using DocumentEngine): resolveTemplate()/branding() for optional
     * per-tenant letterhead, streamInline() so the same URL serves both
     * the "Download PDF" link and the "Print" button (dompdf's inline
     * stream opens in-browser, where the browser's own print dialog
     * handles Print -- no second rendering pipeline). Viewable by
     * anyone who can view the permit (index/show have no extra gate
     * beyond authentication + tenant match) -- a Foreman must be able to
     * download/print their OWN permit, not just HSE.
     */
    public function pdf(PermitToWork $permitToWork, PdfGeneratorService $pdf, DocumentEngine $documents): \Illuminate\Http\Response
    {
        $this->assertInCurrentTenant($permitToWork);

        $permitToWork->load('company', 'project', 'riskAssessment', 'jsa', 'requester', 'areaAuthority', 'hseApprover', 'closer', 'gasTests.tester');

        return $pdf->streamInline('pdf.permit-to-work', [
            'permit' => $permitToWork,
            'company' => $permitToWork->company,
            'documentTemplate' => $documents->resolveTemplate('permit_to_work', $permitToWork->company_id),
            'branding' => $documents->branding(),
        ], "{$permitToWork->ptw_number}.pdf");
    }

    private function assertInCurrentTenant(PermitToWork $permitToWork): void
    {
        abort_unless(Company::query()->pluck('id')->contains($permitToWork->company_id), 404);
    }
}
