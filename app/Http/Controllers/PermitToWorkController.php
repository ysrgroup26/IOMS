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

    /**
     * v2.9.0 (Field/Foreman Experience pass, Phase 3C -- My PTW). A
     * separate, field-oriented view over the SAME `permits_to_work`
     * table `index()` already queries above -- no new table, no new
     * model, no duplicated backend logic. The one real difference:
     * scoped to `requested_by = $request->user()->id` in addition to
     * the same tenant scope `index()` already uses, so a field user
     * sees only the permits they themselves requested -- confirmed via
     * this pass's own audit that `requested_by` (via
     * `PermitToWork::requester()`) is the ONLY existing ownership
     * field on this model; no new column/relationship was introduced.
     *
     * Ownership filtering happens entirely server-side from the
     * authenticated session (`$request->user()->id`) -- there is no
     * client-supplied "whose permits" parameter of any kind, so this
     * can never be used to browse another user's permits by guessing a
     * query string.
     *
     * `status` here accepts exactly the model's own real status values
     * (draft/submitted/approved/active/rejected/closed/cancelled) plus
     * `all` -- no new grouping/synthetic status invented. Same
     * `paginate(20)` as `index()`, reused rather than a new pagination
     * scheme.
     */
    public function myIndex(Request $request): Response
    {
        $user = $request->user();
        $tenantCompanyIds = Company::query()->pluck('id');

        $permits = PermitToWork::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->where('requested_by', $user->id)
            ->with('project:id,name')
            ->when($request->input('status') && $request->input('status') !== 'all', fn ($q, $v) => $q->where('status', $request->input('status')))
            ->latest('start_datetime')
            ->paginate(20)
            ->withQueryString();

        // v2.9.0: rejection reasons for the current page's rejected
        // items ONLY -- one batched query (not N+1 per rejected item),
        // matching the product's "REJECTED / Reason: ... / [Resubmit]"
        // card requirement so a field user sees WHY without navigating
        // to Show first. Same ActivityLog.meta trail
        // rejectionReasonFor() (used by show()/document()) already
        // reads -- reused, not a new source of truth.
        $rejectedIds = $permits->getCollection()->where('status', PermitToWork::STATUS_REJECTED)->pluck('id');
        $rejectionReasons = $rejectedIds->isEmpty() ? collect() : ActivityLog::where('subject_type', PermitToWork::class)
            ->whereIn('subject_id', $rejectedIds)
            ->where('action', PermitToWork::STATUS_REJECTED)
            ->latest()
            ->get()
            ->unique('subject_id')
            ->mapWithKeys(fn ($log) => [$log->subject_id => $log->meta['comments'] ?? null]);

        $permits->getCollection()->transform(function (PermitToWork $p) use ($rejectionReasons) {
            $p->rejection_reason = $rejectionReasons->get($p->id);

            return $p;
        });

        // Real, tenant+requester-scoped counts for the filter tabs and
        // for Field Home's own summary -- one cheap grouped query, not
        // one query per status.
        $counts = PermitToWork::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->where('requested_by', $user->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('PermitsToWork/MyIndex', [
            'permits' => $permits,
            'filters' => $request->only('status'),
            'counts' => [
                'all' => $counts->sum(),
                'submitted' => $counts->get(PermitToWork::STATUS_SUBMITTED, 0),
                'approved' => $counts->get(PermitToWork::STATUS_APPROVED, 0),
                'active' => $counts->get(PermitToWork::STATUS_ACTIVE, 0),
                'rejected' => $counts->get(PermitToWork::STATUS_REJECTED, 0),
                'closed' => $counts->get(PermitToWork::STATUS_CLOSED, 0),
            ],
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
            // v2.8.0 (PTW Mobile / Task-First pass, Phase 3B, Part 6/7):
            // the Show page previously had NO way to surface WHY a
            // permit was rejected -- confirmed via this pass's own
            // fresh re-read, the reason only ever appeared inside the
            // Activity timeline card (secondary, needs scrolling) or on
            // the separate Document view. Same lookup `document()`
            // already does, reused rather than duplicated logic.
            'rejectionReason' => $this->rejectionReasonFor($permitToWork),
        ]);
    }

    public function transition(Request $request, PermitToWork $permitToWork): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($permitToWork);

        $data = $request->validate([
            // v2.8.0 (PTW Mobile / Task-First pass, Phase 3B): added
            // STATUS_DRAFT to this allow-list. The MODEL's own
            // `$transitions` array has always allowed
            // `rejected -> draft` (a real, existing "resubmit" path --
            // not a transition invented by this pass), but this
            // validation rule never included it, so the Show page had
            // no way to actually reach it -- a rejected PTW's UI
            // silently offered zero path back to Draft even though the
            // backend state machine supported one. Every OTHER target
            // here is unchanged; this only adds the one missing,
            // already-modeled transition.
            'status' => ['required', Rule::in([
                PermitToWork::STATUS_DRAFT, PermitToWork::STATUS_SUBMITTED, PermitToWork::STATUS_APPROVED,
                PermitToWork::STATUS_REJECTED, PermitToWork::STATUS_ACTIVE, PermitToWork::STATUS_CLOSED,
                PermitToWork::STATUS_CANCELLED,
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
            // v2.10.0 (PTW Document Polish pass, Phase 3D). REAL,
            // CONFIRMED gap found by this pass's own audit: the browser
            // Document view has shown the rejection reason since v2.6.0,
            // but this PDF endpoint never received or rendered it at
            // all -- the two outputs materially differed on a rejected
            // permit. Same rejectionReasonFor() helper document()
            // already uses (added in Phase 3B), reused here rather than
            // a third copy of the lookup.
            'rejectionReason' => $this->rejectionReasonFor($permitToWork),
        ], "{$permitToWork->ptw_number}.pdf");
    }

    /**
     * v2.6.0 (PTW Document View pass). In-browser, document-oriented
     * presentation of the permit -- the actual product gap this pass
     * addresses: IOMS previously only ever showed PTW as application
     * data/detail cards (`show()` above), never as something that reads
     * like a real HSE form. This is a SEPARATE Inertia page, not a
     * redesign of `show()` -- Show stays the operational/workflow-action
     * page (Approve/Reject/Cancel etc.), Document is the read-oriented,
     * shareable, print/PDF-ready presentation, matching the directive's
     * own "Detail page keeps its workflow actions; add a `View Document`
     * action into a separate view" structure.
     *
     * Reuses the EXACT same eager-loading and DocumentEngine
     * template/branding resolution as `pdf()` above -- one data shape,
     * two renderers (HTML page here, dompdf view there) -- so the
     * browser document and the downloaded PDF can never drift out of
     * sync on what data they show. No new PTW data structure, no new
     * migration.
     */
    public function document(PermitToWork $permitToWork, DocumentEngine $documents): Response
    {
        $this->assertInCurrentTenant($permitToWork);

        $permitToWork->load('company', 'project', 'riskAssessment', 'jsa', 'requester', 'areaAuthority', 'hseApprover', 'closer', 'gasTests.tester');

        return Inertia::render('PermitsToWork/Document', [
            'permit' => $permitToWork,
            'company' => $permitToWork->company,
            'documentTemplate' => $documents->resolveTemplate('permit_to_work', $permitToWork->company_id),
            'branding' => $documents->branding(),
            'rejectionReason' => $this->rejectionReasonFor($permitToWork),
        ]);
    }

    /**
     * v2.8.0 (PTW Mobile / Task-First pass, Phase 3B): extracted out of
     * `document()` (where this exact lookup already lived, verbatim) so
     * `show()` can now surface the same rejection reason without
     * duplicating the query. Rejection reason isn't a column on
     * PermitToWork itself -- it was passed through transitionTo()'s
     * $meta['comments'] param (see transition() above) into
     * ActivityLog.meta. Returns null (never fabricated) for a permit
     * that isn't rejected, or one rejected before v2.4.0 added this
     * field.
     */
    private function rejectionReasonFor(PermitToWork $permitToWork): ?string
    {
        if ($permitToWork->status !== PermitToWork::STATUS_REJECTED) {
            return null;
        }

        $meta = ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permitToWork->id)
            ->where('action', PermitToWork::STATUS_REJECTED)
            ->latest()
            ->value('meta');

        return $meta['comments'] ?? null;
    }

    private function assertInCurrentTenant(PermitToWork $permitToWork): void
    {
        abort_unless(Company::query()->pluck('id')->contains($permitToWork->company_id), 404);
    }
}
