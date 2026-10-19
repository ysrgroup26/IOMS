<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePermitToWorkRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobSafetyAnalysis;
use App\Models\PermitToWork;
use App\Models\PpeType;
use App\Models\Project;
use App\Models\RiskAssessment;
use App\Services\DocumentEngine;
use App\Services\PdfGeneratorService;
use App\Services\PtwQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // v2.17.1 (PTW Field Workflow Verification & Correction
            // pass, Part 2): PIC and Workforce count now surfaced on the
            // Index list -- eager-loaded here (pic:id,full_name +
            // withCount('personnel')) so this stays exactly one query
            // per page of results, same N+1-free shape `with()` already
            // used for project/requester. `pic`/`personnel` both
            // resolve through PermitToWork's own tenant-scoped rows
            // (already filtered by `whereIn('company_id', ...)` above),
            // so no other tenant's Employee data can surface here.
            ->with('project:id,name', 'requester:id,name', 'pic:id,full_name')
            ->withCount('personnel')
            ->when($request->input('search'), fn ($q, $v) => $q->where('ptw_number', 'like', "%{$v}%")->orWhere('work_description', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('permit_type', $v))
            ->latest('start_datetime')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('PermitsToWork/Index', [
            'permits' => $permits,
            'filters' => $request->only('search', 'status', 'type'),
            /*
             * v2.103.0 -- THE PROP REPORTS THE GATE, not half of it.
             *
             * This was `canManageHse()`, while the server gate for raising
             * a permit is `canCreatePtw()` -- which is `canManageHse() OR
             * ptw_access`. The second half is the entire point of PTW
             * Access: a foreman or supervisor who is not HSE is granted it,
             * and the grant is quota-checked and billed.
             *
             * The only thing this prop gates is the "New Permit" button, so
             * a granted account could raise a permit by URL and had no
             * button to do it with. The customer pays for the access and it
             * appears to do nothing.
             *
             * Reported from the same method the route enforces with, so the
             * page and the server cannot disagree in either direction.
             */
            'can' => ['manage' => $request->user()->canCreatePtw()],
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
            /*
             * v2.122.0 -- DO NOT OFFER A PERMIT THIS ACCOUNT CANNOT RAISE.
             *
             * "New PTW" was unconditional here, so an account without PTW
             * Access -- which every My Work account is until an
             * administrator grants it -- got a prominent button that
             * answers 403. Browser-verified. `canCreatePtw()` is the same
             * gate `create()` and `store()` already enforce, so this
             * grants nothing; it only stops the page from advertising
             * what the server will refuse.
             */
            'canCreate' => $request->user()->canCreatePtw(),
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

    public function create(Request $request): Response|RedirectResponse
    {
        // v2.17.0 (PTW Field Workflow Foundation + Controlled PTW
        // Access): was canManageHse() only -- see User::canCreatePtw()'s
        // own doc comment for why this is a union, not a replacement.
        abort_unless($request->user()->canCreatePtw(), 403);

        /*
         * v2.86.0 -- QUOTA IS CHECKED BEFORE THE FORM, NOT AFTER IT.
         *
         * Letting somebody fill in a permit and refusing it on submit is the
         * worst version of this: the work is already done, and on a phone in
         * the field it is done twice. A tenant with no documents left is
         * sent to the explanation instead, which names the two ways out.
         *
         * This is NOT an authorization check. `canCreatePtw()` above decides
         * who may raise a permit and is unchanged; this decides whether the
         * company has capacity left to raise one at all.
         */
        $quota = app(PtwQuotaService::class);

        if (! $quota->canCreate($request->user()->tenant)) {
            return redirect()->route('permits-to-work.quota')
                ->with('flash', ['error' => 'Kuota dokumen PTW sudah habis. Tambah kuota atau tingkatkan paket untuk membuat PTW baru.']);
        }

        $tenantCompanyIds = Company::query()->pluck('id');


        return Inertia::render('PermitsToWork/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'riskAssessments' => RiskAssessment::whereIn('company_id', $tenantCompanyIds)->where('status', RiskAssessment::STATUS_APPROVED)->get(['id', 'ra_number', 'title']),
            'jsas' => JobSafetyAnalysis::whereIn('company_id', $tenantCompanyIds)->where('status', JobSafetyAnalysis::STATUS_APPROVED)->get(['id', 'jsa_number', 'job_title']),
            'ptwNumber' => PermitToWork::previewNumber(),
            /*
             * v2.125.0 -- THE PERSON RAISING THE PERMIT IS USUALLY THE
             * PERSON RESPONSIBLE FOR THE WORK, SO SAY SO AND LET THEM
             * DISAGREE.
             *
             * The PIC field opened empty for every account, including a
             * My Work account that IS an employee (ADR 062: that class is
             * created by picking somebody out of the Employee Register,
             * so `users.employee_id` is not a hint, it is the identity).
             * A supervisor on a phone therefore searched the directory
             * for themselves before they could start.
             *
             * This is a DEFAULT, not an assignment: the field stays a
             * normal editable `EmployeeSelector`, and nothing downstream
             * reads it as authority. `requested_by` is still set from the
             * session in `store()` and still has no key in
             * StorePermitToWorkRequest, so filling this in grants the
             * creator no approval right whatsoever -- approving remains
             * `canManageHse()` and writes `hse_approver_id`.
             *
             * Null for an account with no employee link (a dashboard user
             * who is not in the register), which leaves the field exactly
             * as it was rather than guessing.
             */
            'defaultPicEmployeeId' => $request->user()->employee_id
                ? (string) $request->user()->employee_id
                : '',
            'types' => PermitToWork::TYPES,
            // v2.73.0 -- the EXISTING PPE master, not a new one.
            'ppeTypes' => $tenantCompanyIds->flatMap(fn ($companyId) =>
                PpeType::effectiveForCompany((int) $companyId)->active()->get(['id', 'name'])->map(fn (PpeType $type) => [
                    'id' => $type->id, 'name' => $type->name, 'company_id' => (int) $companyId,
                ])
            )->values(),
            // v2.38.0: the `employees` prop is GONE. It used to ship this
            // tenant's entire active employee directory into the page
            // payload just to populate two <select> elements -- fine for
            // 40 staff, a multi-megabyte payload and an unusable dropdown
            // for the 1,500-3,000-worker sites IOMS targets. The form now
            // uses the shared `EmployeeSelector`, which queries
            // `/employee-lookup` on demand (tenant-scoped, capped,
            // department-grouped). Employee Master remains the single
            // source of truth; only the delivery mechanism changed.
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
        $validated = $request->validated();
        // v2.17.0 (PTW Field Workflow Foundation, Part 9): workforce is a
        // pivot relationship, not a `permits_to_work` column -- pulled
        // out here so it isn't passed to PermitToWork::create() (which
        // would silently no-op, since it's not in $fillable) and synced
        // separately below, after the permit itself exists.
        $personnelIds = $validated['personnel_ids'] ?? [];
        unset($validated['personnel_ids']);

        /*
         * v2.86.0 -- THE PERMIT AND ITS DOCUMENT ARE ONE TRANSACTION.
         *
         * Creating the permit and spending the document have to succeed or
         * fail together. Apart, a failure between them either gives away a
         * permit that was never paid for or charges for one that does not
         * exist, and both are silent.
         *
         * The quota check in create() is the courteous one; this is the real
         * one. Two supervisors submitting the last document at the same
         * moment both pass create(), and only one may pass here --
         * PtwQuotaService::consume() locks the grant rows to make sure of it.
         */
        $quota = app(PtwQuotaService::class);
        $tenant = $request->user()->tenant;

        if (! $quota->canCreate($tenant)) {
            return back()->withInput()
                ->with('flash', ['error' => 'Kuota dokumen PTW sudah habis. Tambah kuota atau tingkatkan paket untuk membuat PTW baru.']);
        }

        $permit = DB::transaction(function () use ($validated, $request, $quota, $tenant) {
            $permit = PermitToWork::create([
            ...$validated,
            'ptw_number' => PermitToWork::generateNumber(),
            'status' => PermitToWork::STATUS_DRAFT,

            // Requester is ALWAYS the authenticated user -- never a
            // client-supplied value (there is no `requested_by` key in
            // StorePermitToWorkRequest::rules() at all, so nothing in
            // $validated could override this even if the frontend tried).
            'requested_by' => $request->user()->id,
            ]);

            // Spent inside the transaction, so a permit can never exist
            // without its document having been charged for.
            $quota->consume($tenant, $permit, $request->user());

            return $permit;
        });

        if (! empty($personnelIds)) {
            $permit->personnel()->sync($personnelIds);
        }

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
            // v2.72.0: `role` on hseApprover, because the approval stamp
            // names the authority the permit was signed under, not just a
            // person. Browser-verified: without it roleLabel() returned an
            // empty string rather than failing, so the seal rendered a
            // person with no role and nothing anywhere said why.
            'requester:id,name', 'areaAuthority:id,name', 'closer:id,name',
            /*
             * v2.125.0 -- `employee_id` and the position behind it.
             *
             * The seal's title line is now the company's own job title
             * (see authorizationFor()), which is reached account ->
             * employee -> position. Without `employee_id` in this select
             * the relation resolves to null and the title silently falls
             * all the way back to the system role -- the exact shape of
             * the v2.72.0 defect this constraint caused once already,
             * where a missing column made roleLabel() return '' rather
             * than fail.
             */
            'hseApprover:id,name,role,employee_id', 'hseApprover.employee:id,position_id', 'hseApprover.employee.position:id,name',
            'gasTests.tester:id,name', 'lotoRecords',
            // v2.17.0 (PTW Field Workflow Foundation, Part 8/9/14/15).
            'pic:id,full_name', 'personnel:id,full_name'
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
            // v2.72.0: null unless the permit is genuinely authorised.
            'authorization' => $this->authorizationFor($permitToWork),
            // v2.73.0 -- resolved names for the ids this permit stored.
            'ppe' => $this->ppeFor($permitToWork),
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
            // v2.73.0 -- only meaningful on approval; ignored otherwise.
            'confirmed_ppe_ids' => ['nullable', 'array', 'max:40'],
            'confirmed_ppe_ids.*' => [Rule::in(PpeType::effectiveForCompany($permitToWork->company_id)->pluck('id'))],
        ]);

        try {
            if ($data['status'] === PermitToWork::STATUS_APPROVED) {
                $permitToWork->hse_approver_id = $request->user()->id;

                /*
                 * v2.73.0 -- CONFIRMED PPE IS WRITTEN AT AUTHORISATION,
                 * BY THE PERSON AUTHORISING.
                 *
                 * This is the only place `confirmed_ppe_ids` is ever
                 * written, and it is deliberately not accepted on the
                 * create request: a requester asserting their own PPE
                 * compliance is exactly what the required/confirmed split
                 * exists to prevent. Same discipline as
                 * `hse_approver_id` directly above -- server-side, gated
                 * on canManageHse(), never settable from a form the
                 * requester controls.
                 *
                 * Defaults to the required list when the approver did not
                 * narrow it, because approving a permit without saying
                 * otherwise IS the statement that its stated PPE is in
                 * place. An approver who checked and found something
                 * missing posts the shorter list.
                 */
                $permitToWork->confirmed_ppe_ids = $data['confirmed_ppe_ids']
                    ?? $permitToWork->required_ppe_ids
                    ?? [];

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

        $permitToWork->load('company', 'project', 'riskAssessment', 'jsa', 'requester', 'areaAuthority', 'hseApprover', 'hseApprover.employee.position', 'closer', 'gasTests.tester', 'pic', 'personnel');

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
            // v2.72.0: null unless the permit is genuinely authorised.
            'authorization' => $this->authorizationFor($permitToWork),
            'ppe' => $this->ppeFor($permitToWork),
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

        $permitToWork->load('company', 'project', 'riskAssessment', 'jsa', 'requester', 'areaAuthority', 'hseApprover', 'hseApprover.employee.position', 'closer', 'gasTests.tester', 'pic', 'personnel');

        return Inertia::render('PermitsToWork/Document', [
            'permit' => $permitToWork,
            'company' => $permitToWork->company,
            'documentTemplate' => $documents->resolveTemplate('permit_to_work', $permitToWork->company_id),
            'branding' => $documents->branding(),
            'rejectionReason' => $this->rejectionReasonFor($permitToWork),
            // v2.72.0: null unless the permit is genuinely authorised.
            'authorization' => $this->authorizationFor($permitToWork),
            'ppe' => $this->ppeFor($permitToWork),
        ]);
    }

    /**
     * v2.73.0 -- RESOLVE THIS PERMIT'S PPE IDS TO NAMES, ONCE.
     *
     * Shared by the Show page, the Document view and the PDF so all three
     * describe the same equipment -- the same reason `authorizationFor()`
     * exists rather than three copies of one lookup.
     *
     * Returns `required`, `confirmed` and `unconfirmed` as resolved
     * lists. The third is computed here rather than left to each renderer
     * to diff two arrays by eye, because the gap between what a permit
     * requires and what somebody verified is the single most important
     * thing on it.
     */
    private function ppeFor(PermitToWork $permitToWork): array
    {
        $ids = collect($permitToWork->required_ppe_ids ?? [])
            ->merge($permitToWork->confirmed_ppe_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique();

        // One query for every id either list mentions, including types
        // that have since been deactivated -- a permit must keep naming
        // what it named, and filtering to active types here would make
        // last year's permit quietly lose an item.
        $types = $ids->isEmpty()
            ? collect()
            : PpeType::whereIn('id', $ids)->get(['id', 'name']);

        return [
            'required' => $permitToWork->resolvePpe($permitToWork->required_ppe_ids ?? [], $types),
            'confirmed' => $permitToWork->resolvePpe($permitToWork->confirmed_ppe_ids ?? [], $types),
            'unconfirmed' => $permitToWork->resolvePpe($permitToWork->unconfirmedPpeIds(), $types),
            'additional' => $permitToWork->additional_ppe ?? [],
        ];
    }

    /**
     * v2.72.0 -- THE APPROVAL RECORD BEHIND THE STAMP.
     *
     * Returns null unless the permit has genuinely been authorised (see
     * `PermitToWork::isAuthorised()`), so a caller cannot render a stamp
     * for a permit that has not got one -- the absence of data is the
     * guard, not a flag the view is trusted to check.
     *
     * WHERE THE TIMESTAMP COMES FROM. There is no `approved_at` column,
     * and this deliberately does not add one: the moment of approval is
     * already recorded, by the same `transitionTo()` call that performs
     * it, in the ActivityLog -- which is the audit trail of record for
     * every workflow in this product. Reading it here reuses that
     * evidence rather than creating a second, separately-maintained copy
     * of the same fact that could drift from it. Same technique, and the
     * same reasoning, as `rejectionReasonFor()` below.
     *
     * A permit approved before the ActivityLog carried this action still
     * stamps correctly and simply shows no time, rather than showing a
     * fabricated one.
     */
    private function authorizationFor(PermitToWork $permitToWork): ?array
    {
        if (! $permitToWork->isAuthorised()) {
            return null;
        }

        $approver = $permitToWork->hseApprover;

        /*
         * v2.100.0 -- THE NAME AS IT WAS WHEN THE PERMIT WAS AUTHORISED.
         *
         * This stamp is the compliance artefact on a printed permit, and it
         * was rendering the approver's CURRENT name: rename that person and
         * every permit already filed retroactively carried the new name;
         * remove them and the stamp went blank.
         *
         * The authorising entry is the same ActivityLog row this method
         * already reads the time from, so the name costs no extra query and
         * comes from the same evidence as the timestamp beside it. The live
         * relation stays as the fallback for permits authorised before the
         * actor was captured, which omits nothing it used to show.
         */
        $authorisation = ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permitToWork->id)
            ->where('action', PermitToWork::STATUS_APPROVED)
            ->latest()
            ->first(['actor_name', 'actor_title', 'created_at']);

        return [
            'approver' => $authorisation?->actor_name ?: $approver?->name,
            /*
             * v2.125.0 -- THE COMPANY'S OWN JOB TITLE, AS AT THE SIGNING.
             *
             * This was `$approver->roleLabel()`, which is the IOMS SYSTEM
             * role -- "Super Admin", "HSE". On a permit taped to a
             * bulkhead that is the wrong fact: the authority a reader
             * recognises is the yard's own title, and which title signs
             * which permit is the customer's configuration, not ours. One
             * company's permits are signed by an SPV HSE, another's by a
             * Manager Shipyard or a Site Manager, and nothing here may
             * force either word.
             *
             * So the title is read in this order, each step a real record
             * and never a guess:
             *
             *  1. `actor_title` on the authorising ActivityLog row -- the
             *     position that account held AT THE MOMENT it signed, so a
             *     later rename or transfer cannot rewrite a filed permit.
             *  2. the approver's CURRENT position, for a permit signed
             *     before that column existed. Shows no less than the old
             *     behaviour did.
             *  3. the system role, last, because it is at least true about
             *     the account even when the company configured no position
             *     for them.
             *
             * Null if none of the three is available, and the stamp then
             * renders without a title line rather than with a fabricated
             * one -- the rule the whole stamp is built on.
             *
             * This changes NO authority. Who may write `hse_approver_id`
             * is `canManageHse()` and is untouched; this only reports who
             * did.
             */
            'role' => $authorisation?->actor_title
                ?: ($approver?->employee?->position?->name
                    ?: ($approver?->role ? $approver->roleLabel() : null)),
            'at' => $authorisation?->created_at,
        ];
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
