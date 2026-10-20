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
use App\Models\Notification;
use App\Models\User;
use App\Services\DocumentEngine;
use App\Services\Documents\StampRenderer;
use App\Services\NotificationService;
use App\Services\PdfGeneratorService;
use App\Services\PtwQuotaService;
use App\Services\PtwWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        /*
         * v2.127.0 -- STAGE ONE: HSE IS TOLD, AND NOBODY ELSE IS.
         *
         * The permit is now `submitted`, so `pendingStage()` can only
         * return `verify` -- which is how "the approver must not hear about
         * this yet" is guaranteed structurally rather than by remembering.
         * The approver's turn comes when, and only when, a verification has
         * been recorded.
         *
         * After the quota transaction and outside it, for the reason given
         * on `notifyNextStage()`: a permit that exists must not be undone
         * by a notification that does not send.
         */
        $this->notifyNextStage($permit, app(PtwWorkflowService::class), $request->user());

        return redirect()->route('permits-to-work.show', $permit)
            ->with('flash', ['success' => 'Izin kerja berhasil dibuat dan sedang menunggu verifikasi HSE.']);
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
            // v2.127.0 -- the verifier, and the company job title their
            // seal names. Constrained the same way, and for the same
            // reason: without `employee_id` in the select the relation
            // resolves to null and the title silently falls all the way
            // back to the system role.
            'hseVerifier:id,name,role,employee_id', 'hseVerifier.employee:id,position_id', 'hseVerifier.employee.position:id,name',
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
            /*
             * v2.127.0 -- THE PAGE ASKS THE GATE, NOT ITS OWN GUESS.
             *
             * Both answers come from `PtwWorkflowService`, which is the
             * same object `transition()` gates the write with -- so a
             * Verify or Approve button appears only where the server would
             * accept the decision, and a person configured for neither sees
             * neither. ADR 061: an offer is bound to what the server
             * allows. A button that answers 403 reads as a broken account.
             */
            'workflow' => [
                'can_verify' => app(PtwWorkflowService::class)->canVerify($request->user(), $permitToWork),
                'can_approve' => app(PtwWorkflowService::class)->canApprove($request->user(), $permitToWork),
                'pending_stage' => app(PtwWorkflowService::class)->pendingStage($permitToWork),
            ],
            // v2.8.0 (PTW Mobile / Task-First pass, Phase 3B, Part 6/7):
            // the Show page previously had NO way to surface WHY a
            // permit was rejected -- confirmed via this pass's own
            // fresh re-read, the reason only ever appeared inside the
            // Activity timeline card (secondary, needs scrolling) or on
            // the separate Document view. Same lookup `document()`
            // already does, reused rather than duplicated logic.
            'rejectionReason' => $this->rejectionReasonFor($permitToWork),
            // v2.72.0: null unless the permit is genuinely authorised.
            'authorization' => $authorization = $this->authorizationFor($permitToWork),
            // v2.127.0 -- the verification decision, which is a different
            // act by a different person. @see verificationFor()
            'verification' => $verification = $this->verificationFor($permitToWork),
            // v2.126.0 -- the same SVG the PDF embeds. @see stampsFor()
            'stamps' => $this->stampsFor($permitToWork, $authorization, $verification),
            // v2.73.0 -- resolved names for the ids this permit stored.
            'ppe' => $this->ppeFor($permitToWork),
        ]);
    }

    /**
     * v2.127.0 -- EVERY TARGET STATE IS GATED BY ITS OWN QUESTION.
     *
     * This method used to open with one line -- `abort_unless(canManageHse())`
     * -- and then accept any target status. With verification and approval
     * now separate decisions held by different people, a single gate would
     * mean anybody who can verify can also approve, which is exactly what
     * the owner's requirement forbids ("Pengguna yang hanya memiliki
     * kewenangan VERIFIED tidak otomatis memiliki kewenangan APPROVED").
     *
     * So authorization is resolved per target, from `PtwWorkflowService` --
     * the same source the page asks before it offers the button, so the
     * two can never disagree (ADR 061).
     */
    public function transition(Request $request, PermitToWork $permitToWork, PtwWorkflowService $workflow): RedirectResponse
    {
        $this->assertInCurrentTenant($permitToWork);

        $data = $request->validate([
            // v2.8.0 (PTW Mobile / Task-First pass, Phase 3B): added
            // STATUS_DRAFT to this allow-list -- a real, existing
            // "resubmit" path the UI previously had no way to reach.
            // v2.127.0: STATUS_VERIFIED joins it.
            'status' => ['required', Rule::in([
                PermitToWork::STATUS_DRAFT, PermitToWork::STATUS_SUBMITTED,
                PermitToWork::STATUS_VERIFIED, PermitToWork::STATUS_APPROVED,
                PermitToWork::STATUS_REJECTED, PermitToWork::STATUS_ACTIVE, PermitToWork::STATUS_CLOSED,
                PermitToWork::STATUS_CANCELLED,
            ])],
            // v2.4.0 (PTW UX + Field Operations pass, Part 14): "If
            // rejecting: Require a short reason." Passed through to
            // transitionTo()'s `$meta['comments']`, which the notification
            // body reads -- so a returned permit actually says why.
            'reason' => ['required_if:status,'.PermitToWork::STATUS_REJECTED, 'nullable', 'string', 'max:500'],
            // v2.73.0 -- only meaningful on verification; ignored otherwise.
            'confirmed_ppe_ids' => ['nullable', 'array', 'max:40'],
            'confirmed_ppe_ids.*' => [Rule::in(PpeType::effectiveForCompany($permitToWork->company_id)->pluck('id'))],
        ]);

        $target = $data['status'];
        $user = $request->user();

        /*
         * WHO MAY DO THIS, decided per target and on the SERVER.
         *
         * Hiding a button is not authorization -- the owner's requirement
         * says so in as many words ("Validasi kewenangan harus dilakukan di
         * backend, bukan hanya menyembunyikan tombol di frontend"), and
         * this endpoint is reachable with curl by anybody who can sign in.
         */
        $allowed = match ($target) {
            PermitToWork::STATUS_VERIFIED => $workflow->canVerify($user, $permitToWork),
            PermitToWork::STATUS_APPROVED => $workflow->canApprove($user, $permitToWork),
            /*
             * Returning a permit is held by EITHER gate: a verifier who
             * finds a problem and an approver who disagrees with a
             * verification both need it, and a permit that can only go
             * forward is not a control.
             */
            PermitToWork::STATUS_REJECTED => $workflow->canVerify($user, $permitToWork)
                || $workflow->canApprove($user, $permitToWork),
            /*
             * ACTIVATION IS NOT A DEAD END.
             *
             * It was `canManageHse()` alone, which was right while HSE was
             * the only gate -- and became a trap the moment approval moved
             * elsewhere: a company that routes final approval to a manager
             * got a permit that was approved and that NOBODY present could
             * activate. Seen in a browser: the approver approved the permit
             * and then had no Activate button, and neither did anybody else
             * on that screen.
             *
             * So activation is held by HSE **or** by whoever holds either
             * gate on this permit. They are already trusted with the
             * decision that authorised the work; being allowed to say the
             * work has started is strictly less than that, and the state
             * machine still refuses activation on anything that is not
             * approved.
             */
            PermitToWork::STATUS_ACTIVE => $user->canManageHse()
                || $workflow->canVerify($user, $permitToWork)
                || $workflow->canApprove($user, $permitToWork),
            // Closure and cancellation stay where they were: operational
            // acts on an already authorised permit, which this release
            // deliberately does not re-litigate.
            default => $user->canManageHse(),
        };

        abort_unless($allowed, 403);

        try {
            /*
             * THE DECISION AND THE STATE CHANGE ARE ONE WRITE.
             *
             * Wrapped in a transaction with the row LOCKED, and the status
             * re-read inside the lock, because three things would otherwise
             * be possible and two of them are silent:
             *
             *   - DOUBLE APPROVAL. Two approvers pressing Approve at the
             *     same moment both pass the state check, both write
             *     `hse_approver_id`, and the second overwrites the first --
             *     so the stamp names whoever lost the race.
             *   - A LOST ACTOR. Writing the actor and the status as two
             *     statements means a failure between them leaves a permit
             *     approved by nobody, or an approver recorded on a permit
             *     that never moved.
             *   - A STALE DECISION. A permit that someone else already
             *     advanced is re-advanced from a page rendered minutes ago.
             *
             * The lock makes the second presser read the NEW status and be
             * refused by the state machine, which is the correct outcome:
             * one decision, one record, and the loser is told rather than
             * silently overwriting.
             */
            DB::transaction(function () use ($permitToWork, $target, $data, $user) {
                $fresh = PermitToWork::withoutGlobalScopes()
                    ->whereKey($permitToWork->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // The in-memory copy may be stale; the locked row is not.
                $permitToWork->setRawAttributes($fresh->getAttributes(), true);

                if ($target === PermitToWork::STATUS_VERIFIED) {
                    $permitToWork->hse_verifier_id = $user->id;
                    $permitToWork->verified_at = now();

                    /*
                     * v2.73.0, moved to the VERIFICATION in v2.127.0.
                     *
                     * Confirming that the required PPE is actually present
                     * is an HSE CHECK, not an approval decision -- it is
                     * the one thing the verifier is there to do. It moved
                     * with the gate rather than staying on approval, where
                     * it would have asked a manager to attest to equipment
                     * they never saw.
                     *
                     * Still never accepted on the create request: a
                     * requester attesting to their own PPE compliance is
                     * exactly what the required/confirmed split prevents.
                     * Defaults to the required list, because verifying
                     * without narrowing it IS the statement that the stated
                     * PPE is in place.
                     */
                    $permitToWork->confirmed_ppe_ids = $data['confirmed_ppe_ids']
                        ?? $permitToWork->required_ppe_ids
                        ?? [];
                }

                if ($target === PermitToWork::STATUS_APPROVED) {
                    $permitToWork->hse_approver_id = $user->id;
                    $permitToWork->approved_at = now();
                }

                if ($target === PermitToWork::STATUS_CLOSED) {
                    $permitToWork->closed_by = $user->id;
                    $permitToWork->closed_at = now();
                }

                // Throws if the move is not allowed FROM the locked status,
                // which is what refuses a double approval and a skipped
                // verification alike.
                $permitToWork->transitionTo($target, $user, meta: ['comments' => $data['reason'] ?? null]);
                $permitToWork->save();
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        /*
         * THE NEXT STAGE IS TOLD **AFTER** THE DECISION IS COMMITTED.
         *
         * Outside the transaction on purpose. The owner's requirement is
         * explicit that a notification failure must not cost the decision
         * ("Kegagalan pengiriman notifikasi tidak membuat keputusan
         * persetujuan hilang"), and a send inside the transaction does
         * exactly that: a mail driver timing out would roll back an
         * approval that had already been made.
         *
         * So the write is durable first, and the telling is best-effort and
         * logged. The decision is the record; the notification is a
         * courtesy on top of it, and a courtesy may fail.
         */
        $this->notifyNextStage($permitToWork, $workflow, $user);

        return back()->with('flash', ['success' => $this->transitionFlash($target)]);
    }

    /**
     * Tell whoever is next, and nobody else.
     *
     * The ORDER is the whole point of this release: the approver hears
     * nothing at submission, and hears about the permit only once
     * verification has actually been recorded. That is guaranteed
     * structurally rather than by care -- the recipients are derived from
     * the permit's CURRENT status, so a permit sitting at `submitted` can
     * only ever produce verifier notifications.
     *
     * Failures are swallowed and logged. See the caller for why.
     */
    private function notifyNextStage(PermitToWork $permitToWork, PtwWorkflowService $workflow, User $actor): void
    {
        $stage = $workflow->pendingStage($permitToWork);

        if ($stage === null) {
            return;
        }

        $title = $stage === PtwWorkflowService::STAGE_VERIFY
            ? $permitToWork->documentLabel().' '.$permitToWork->ptw_number.' needs your HSE verification'
            : $permitToWork->documentLabel().' '.$permitToWork->ptw_number.' needs your approval';

        $body = $stage === PtwWorkflowService::STAGE_VERIFY
            ? 'Periksa pekerjaan, bahaya dan APD yang diwajibkan, lalu verifikasi izin ini.'
            : 'Izin ini sudah diverifikasi HSE dan menunggu persetujuan akhir Anda.';

        try {
            $notifier = app(NotificationService::class);

            foreach ($workflow->recipientsFor($permitToWork, $stage) as $recipient) {
                // Not the person who just acted. Telling a verifier that
                // the permit they verified now needs verifying is noise,
                // and an approver who verified it themselves (a company may
                // legitimately configure one person for both) does not need
                // telling about their own action either.
                if ((int) $recipient->id === (int) $actor->id) {
                    continue;
                }

                $notifier->notify(
                    $recipient,
                    Notification::CATEGORY_APPROVAL,
                    $title,
                    $body,
                    $permitToWork->notificationUrl(),
                    $permitToWork,
                    array_filter([
                        'route' => $permitToWork->notificationRouteName(),
                        'ptw_stage' => $stage,
                    ]),
                );
            }
        } catch (\Throwable $e) {
            // The decision is already committed and must stay that way.
            report($e);
        }
    }

    /** What the person who just acted is told, in the product's own words. */
    private function transitionFlash(string $target): string
    {
        return match ($target) {
            PermitToWork::STATUS_VERIFIED => 'Izin kerja telah diverifikasi dan diteruskan untuk persetujuan akhir.',
            PermitToWork::STATUS_APPROVED => 'Izin kerja telah disetujui dan siap diaktifkan.',
            PermitToWork::STATUS_ACTIVE => 'Izin kerja aktif. Pekerjaan boleh dimulai.',
            PermitToWork::STATUS_REJECTED => 'Izin kerja dikembalikan kepada pemohon beserta alasannya.',
            PermitToWork::STATUS_CLOSED => 'Izin kerja ditutup.',
            PermitToWork::STATUS_CANCELLED => 'Izin kerja dibatalkan.',
            default => 'Status izin kerja diperbarui.',
        };
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

        $permitToWork->load('company', 'project', 'riskAssessment', 'jsa', 'requester', 'areaAuthority', 'hseApprover', 'hseApprover.employee.position', 'hseVerifier', 'hseVerifier.employee.position', 'closer', 'gasTests.tester', 'pic', 'personnel');

        return $pdf->streamInline('pdf.permit-to-work', [
            'permit' => $permitToWork,
            'company' => $permitToWork->company,
            'documentTemplate' => $documents->resolveTemplate('permit_to_work', $permitToWork->company_id),
            'branding' => $documents->branding(),
            /*
             * v2.126.0 -- THE LETTERHEAD THE OTHER DOCUMENTS ALREADY USE.
             *
             * This view had its own header built from `branding()`, whose
             * `logo_url` is an HTTP URL -- so rendering a permit made an
             * outbound request to our own public host and, behind a
             * firewall or a wrong APP_URL, drew nothing at all. v2.112.0
             * fixed exactly that for every document that uses
             * `partials/letterhead`, and the permit was not one of them,
             * which is why the owner saw a company NAME where a logo
             * should be. `identity()` carries `logo_path` -- the file on
             * local disk, null unless it is really there -- plus the legal
             * name, address, locality, contact and registry lines the
             * header is supposed to show.
             */
            'identity' => $documents->identity(),
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
            'authorization' => $authorization = $this->authorizationFor($permitToWork),
            // v2.127.0 -- the verification decision, which is a different
            // act by a different person. @see verificationFor()
            'verification' => $verification = $this->verificationFor($permitToWork),
            // v2.126.0 -- the same SVG the PDF embeds. @see stampsFor()
            'stamps' => $this->stampsFor($permitToWork, $authorization, $verification),
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

        $permitToWork->load('company', 'project', 'riskAssessment', 'jsa', 'requester', 'areaAuthority', 'hseApprover', 'hseApprover.employee.position', 'hseVerifier', 'hseVerifier.employee.position', 'closer', 'gasTests.tester', 'pic', 'personnel');

        return Inertia::render('PermitsToWork/Document', [
            'permit' => $permitToWork,
            'company' => $permitToWork->company,
            'documentTemplate' => $documents->resolveTemplate('permit_to_work', $permitToWork->company_id),
            'branding' => $documents->branding(),
            'rejectionReason' => $this->rejectionReasonFor($permitToWork),
            // v2.72.0: null unless the permit is genuinely authorised.
            'authorization' => $authorization = $this->authorizationFor($permitToWork),
            // v2.127.0 -- the verification decision, which is a different
            // act by a different person. @see verificationFor()
            'verification' => $verification = $this->verificationFor($permitToWork),
            // v2.126.0 -- the same SVG the PDF embeds. @see stampsFor()
            'stamps' => $this->stampsFor($permitToWork, $authorization, $verification),
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
    /**
     * v2.126.0 -- THE STAMPS, DRAWN ONCE, FOR EVERY SURFACE.
     *
     * v2.125.0 drew the stamps as a React component and gave the PDF a
     * plain bordered box, on the stated belief that Dompdf could not
     * honour a rotation or an ink texture. That belief was never tested
     * and is wrong -- Dompdf 3.1.5 renders both, verified by rendering a
     * probe and looking at it -- and the owner's reply to that release was
     * that the PDF stamp was still a plain blue box. It was.
     *
     * So `StampRenderer` draws them as SVG and the SAME data URI goes to
     * the PDF and to the Inertia page. Two surfaces cannot disagree about
     * a stamp when there is one stamp.
     *
     * `standing` is null for any status that is not a standing, and
     * `approval` is null unless there is a real authorization record --
     * both refusals are the renderer's, not a flag a view could get wrong.
     *
     * @return array{standing: ?string, approval: ?string}
     */
    private function stampsFor(PermitToWork $permitToWork, ?array $authorization, ?array $verification = null): array
    {
        $stamps = app(StampRenderer::class);

        return [
            'standing' => $stamps->state($permitToWork->status),
            /*
             * v2.127.0 -- the green VERIFIED seal, which ADR 065 left
             * rendering nowhere because PTW had no verification stage to
             * render it from. It has one now, so the variant that was built
             * and deliberately unused is finally driven by a real record --
             * and still only by a real record.
             */
            'verification' => $verification === null ? null : $stamps->action(
                'verified',
                (string) $verification['approver'],
                $verification['role'] ?? null,
                $verification['at']
                    ? Carbon::parse($verification['at'])->timezone(config('ioms.display_timezone'))->format('d M Y H:i')
                    : null,
                'HSE',
            ),
            'approval' => $authorization === null ? null : $stamps->action(
                'approved',
                (string) $authorization['approver'],
                $authorization['role'] ?? null,
                $authorization['at']
                    ? Carbon::parse($authorization['at'])->timezone(config('ioms.display_timezone'))->format('d M Y H:i')
                    : null,
                /*
                 * v2.127.0 -- NO function label on the approval seal.
                 *
                 * It was hardcoded to 'HSE', which was true while HSE held
                 * the only gate and became a lie the moment approval moved:
                 * a permit approved by a manager printed "APPROVED / HSE /
                 * Manager Audit-business / MANAGER". Seen on the rendered
                 * sheet.
                 *
                 * Deriving it from the approver's role would just repeat
                 * the title line below it, so it is omitted. The seal reads
                 * APPROVED, then who, then the job title their company
                 * configured, then when -- which is the whole fact with
                 * nothing invented and nothing said twice. The VERIFIED
                 * seal keeps 'HSE' because that gate IS the HSE check, by
                 * definition in this product.
                 */
                null,
            ),
        ];
    }

    /**
     * v2.127.0 -- THE VERIFICATION RECORD, shaped exactly like the
     * authorization one and read the same way.
     *
     * Null unless the permit is genuinely verified, so the VERIFIED stamp
     * has no boolean it could be told to lie with -- the contract the whole
     * stamp family is built on (ADR 065).
     *
     * The name and the job title come from the ActivityLog row for the
     * `verified` action, NOT from the live relation: renaming a person or
     * moving them between positions must not rewrite a permit already
     * filed. That is the v2.100.0 rule, applied to the second gate the day
     * the second gate exists rather than a release later.
     */
    private function verificationFor(PermitToWork $permitToWork): ?array
    {
        if (! $permitToWork->isVerified()) {
            return null;
        }

        $verifier = $permitToWork->hseVerifier;

        $record = ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permitToWork->id)
            ->where('action', PermitToWork::STATUS_VERIFIED)
            ->latest()
            ->first(['actor_name', 'actor_title', 'created_at']);

        return [
            'approver' => $record?->actor_name ?: $verifier?->name,
            'role' => $record?->actor_title
                ?: ($verifier?->employee?->position?->name
                    ?: ($verifier?->role ? $verifier->roleLabel() : null)),
            // The stored timestamp first -- it is the decision's own time.
            // The log row is the fallback for a permit verified before the
            // column existed, which is none today and will be some later.
            'at' => $permitToWork->verified_at ?? $record?->created_at,
        ];
    }

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
