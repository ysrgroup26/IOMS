<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseRequisitionRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\DocumentEngine;
use App\Services\PdfGeneratorService;

/**
 * Milestone 4, Workstream C2 (Purchase Requisition). Structurally mirrors
 * MaterialRequestController's own authorization split -- creation gated
 * to canManageProcurement() (Procurement's own operational role), review/
 * approval gated to config('workflow.approvers') (Manager/Super Admin) --
 * same segregation-of-duties precedent already established there, reused
 * rather than reinvented.
 */
class PurchaseRequisitionController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $requisitions = PurchaseRequisition::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'department:id,name', 'requester:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('pr_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('priority'), fn ($q, $v) => $q->where('priority', $v))
            ->latest('request_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('PurchaseRequisitions/Index', [
            'requisitions' => $requisitions,
            'filters' => $request->only('search', 'status', 'priority'),
            'can' => ['manage' => $request->user()->canManageProcurement()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('PurchaseRequisitions/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::whereIn('company_id', $tenantCompanyIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'materialRequests' => $this->sourceableMaterialRequests($tenantCompanyIds),
            'prNumber' => PurchaseRequisition::generateNumber(),
            'priorities' => PurchaseRequisition::PRIORITIES,
        ]);
    }

    public function store(StorePurchaseRequisitionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $materialRequestIds = $data['material_request_ids'] ?? [];
        unset($data['material_request_ids']);

        $estimatedTotal = collect($data['items'])->sum(fn ($i) => ($i['quantity'] ?? 0) * ($i['estimated_unit_price'] ?? 0));

        $pr = DB::transaction(function () use ($data, $estimatedTotal, $materialRequestIds, $request) {
            $pr = PurchaseRequisition::create([
                ...$data,
                'pr_number' => PurchaseRequisition::generateNumber(),
                'estimated_total' => $estimatedTotal,
                'status' => PurchaseRequisition::STATUS_DRAFT,
                'requested_by' => $request->user()->id,
            ]);

            ActivityLog::record('created', "Created Purchase Requisition {$pr->pr_number}.", $pr);

            $this->syncSourcedDemand($pr, $materialRequestIds, $request);

            return $pr;
        });

        return redirect()->route('purchase-requisitions.show', $pr)->with('flash', ['success' => 'Purchase Requisition created.']);
    }

    public function edit(PurchaseRequisition $purchaseRequisition, Request $request): Response
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($purchaseRequisition);
        abort_unless($purchaseRequisition->status === PurchaseRequisition::STATUS_DRAFT, 422);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('PurchaseRequisitions/Form', [
            'purchaseRequisition' => $purchaseRequisition->load('materialRequests:id'),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::whereIn('company_id', $tenantCompanyIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'materialRequests' => $this->sourceableMaterialRequests($tenantCompanyIds),
            'prNumber' => $purchaseRequisition->pr_number,
            'priorities' => PurchaseRequisition::PRIORITIES,
        ]);
    }

    public function update(StorePurchaseRequisitionRequest $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        $this->assertInCurrentTenant($purchaseRequisition);
        abort_unless($purchaseRequisition->status === PurchaseRequisition::STATUS_DRAFT, 422);

        $data = $request->validated();
        $materialRequestIds = $data['material_request_ids'] ?? [];
        unset($data['material_request_ids']);

        $data['estimated_total'] = collect($data['items'])->sum(fn ($i) => ($i['quantity'] ?? 0) * ($i['estimated_unit_price'] ?? 0));

        DB::transaction(function () use ($data, $materialRequestIds, $purchaseRequisition, $request) {
            $purchaseRequisition->update($data);
            ActivityLog::record('updated', "Updated Purchase Requisition {$purchaseRequisition->pr_number}.", $purchaseRequisition);

            $this->syncSourcedDemand($purchaseRequisition, $materialRequestIds, $request);
        });

        return redirect()->route('purchase-requisitions.show', $purchaseRequisition)->with('flash', ['success' => 'Purchase Requisition updated.']);
    }

    public function show(PurchaseRequisition $purchaseRequisition, Request $request): Response
    {
        $this->assertInCurrentTenant($purchaseRequisition);
        $purchaseRequisition->load('company:id,name', 'project:id,name', 'department:id,name', 'materialRequests:id,request_number,status,request_date', 'requester:id,name', 'rfqs:id,rfq_number,purchase_requisition_id', 'purchaseOrders:id,po_number,purchase_requisition_id');

        $activities = ActivityLog::where('subject_type', PurchaseRequisition::class)
            ->where('subject_id', $purchaseRequisition->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('PurchaseRequisitions/Show', [
            'purchaseRequisition' => $purchaseRequisition,
            'activities' => $activities,
            'canManage' => $request->user()->canManageProcurement(),
            'canDecide' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.approvers', []), true),
            'canOverride' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.overriders', []), true),
        ]);
    }

    /** Submit/withdraw -- Procurement's own action, no financial authority needed. */
    public function submit(Request $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($purchaseRequisition);

        return $this->doTransition($purchaseRequisition, PurchaseRequisition::STATUS_SUBMITTED, $request);
    }

    public function startReview(Request $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        $this->authorizeWorkflowAction($request, 'approvers');
        $this->assertInCurrentTenant($purchaseRequisition);

        return $this->doTransition($purchaseRequisition, PurchaseRequisition::STATUS_UNDER_REVIEW, $request);
    }

    /** Approval authority (config('workflow.approvers')) -- deliberately NOT canManageProcurement(); a requester/procurement officer never automatically gains approval authority (segregation of duties, per the spec's own explicit requirement). */
    public function approve(Request $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        $this->authorizeWorkflowAction($request, 'approvers');
        $this->assertInCurrentTenant($purchaseRequisition);

        return $this->doTransition($purchaseRequisition, PurchaseRequisition::STATUS_APPROVED, $request);
    }

    public function reject(Request $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        $this->authorizeWorkflowAction($request, 'approvers');
        $this->assertInCurrentTenant($purchaseRequisition);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->doTransition($purchaseRequisition, PurchaseRequisition::STATUS_REJECTED, $request, $data['reason'] ?? null);
    }

    public function cancel(Request $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        $this->authorizeWorkflowAction($request, 'overriders');
        $this->assertInCurrentTenant($purchaseRequisition);

        return DB::transaction(function () use ($purchaseRequisition, $request) {
            $response = $this->doTransition($purchaseRequisition, PurchaseRequisition::STATUS_CANCELLED, $request);

            // Only once the cancellation actually took. doTransition()
            // swallows an illegal transition into a validation error, and
            // demand must not be handed back off the strength of a
            // cancellation that did not happen.
            if ($purchaseRequisition->fresh()->status === PurchaseRequisition::STATUS_CANCELLED) {
                $this->releaseSourcedDemand($purchaseRequisition, $request);
            }

            return $response;
        });
    }

    private function doTransition(PurchaseRequisition $pr, string $status, Request $request, ?string $reason = null): RedirectResponse
    {
        try {
            $pr->transitionTo($status, $request->user(), $reason);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Purchase Requisition '.str_replace('_', ' ', $status).'.']);
    }

    private function authorizeWorkflowAction(Request $request, string $configKey): void
    {
        $allowed = config("workflow.{$configKey}", []);
        abort_unless($request->user()->isSuperAdmin() || in_array($request->user()->role, $allowed, true), 403);
    }

    /**
     * The demand a purchase may legitimately be raised against: approved,
     * or deliberately held back for consolidation. `processing` is
     * included so an existing PR can be edited without its own already-
     * advanced requests dropping out of the picker.
     *
     * Mirrors StorePurchaseRequisitionRequest's rule exactly -- the form
     * shows what the validator will accept, rather than the two drifting.
     */
    private function sourceableMaterialRequests($tenantCompanyIds)
    {
        return MaterialRequest::whereIn('company_id', $tenantCompanyIds)
            ->whereIn('status', [
                MaterialRequest::STATUS_APPROVED,
                MaterialRequest::STATUS_CONSOLIDATING,
                MaterialRequest::STATUS_PROCESSING,
            ])
            ->orderBy('request_date')
            ->get(['id', 'request_number', 'company_id', 'status', 'request_date', 'department_id']);
    }

    /**
     * v2.69.0 -- THE MOMENT A REQUEST STOPS BEING THE REQUESTER'S PROBLEM.
     *
     * Attaching demand to a Purchase Requisition is Procurement taking it
     * on, so the Material Request moves to `processing` here. Before this,
     * raising a PR from a request changed nothing on the request at all --
     * which is the whole reason a requester could watch theirs sit at
     * "Approved" for four months with no way to tell whether anyone had
     * picked it up.
     *
     * Only genuinely new links transition anything: re-saving a PR must
     * not re-log or re-notify for requests that were already attached.
     * The transition runs through `transitionTo()` so the guard, the
     * ActivityLog entry and the requester's notification are the shared
     * ones every other status change in IOMS uses.
     */
    private function syncSourcedDemand(PurchaseRequisition $pr, array $materialRequestIds, Request $request): void
    {
        $previouslyLinked = $pr->materialRequests()->pluck('material_requests.id')->all();

        $pr->materialRequests()->sync($materialRequestIds);

        $newlyLinked = array_diff($materialRequestIds, $previouslyLinked);

        if ($newlyLinked === []) {
            return;
        }

        // The tenant floor again -- sync() was given validated ids, but a
        // transition is a write and re-stating the boundary at the write
        // costs one predicate.
        /*
         * `requester` is eager-loaded because transitionTo() notifies the
         * request's owner, and HasWorkflow::notificationRecipient() reads
         * that relation. Without this it lazy-loads once PER REQUEST
         * inside the loop below -- an N+1 that consolidation makes worse
         * by definition, since the whole point is attaching many at once.
         * Caught by MaterialRequestLifecycleTest under Laravel's strict
         * lazy-loading mode rather than in production.
         */
        $requests = MaterialRequest::whereIn('id', $newlyLinked)
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('requester')
            ->get();

        foreach ($requests as $materialRequest) {
            if (! $materialRequest->canTransitionTo(MaterialRequest::STATUS_PROCESSING)) {
                continue;
            }

            $materialRequest->transitionTo(
                MaterialRequest::STATUS_PROCESSING,
                $request->user(),
                "Sourced by Purchase Requisition {$pr->pr_number}."
            );
        }
    }

    /**
     * A cancelled purchase must not strand the demand it was carrying.
     * Each attached request returns to `approved` -- still live, still
     * owed to its requester, and available to be consolidated into a
     * different purchase -- unless another live PR is still sourcing it.
     */
    private function releaseSourcedDemand(PurchaseRequisition $pr, Request $request): void
    {
        // Same reason as syncSourcedDemand(): the hand-back transition
        // notifies each requester, so the relation is loaded up front
        // rather than once per row.
        $pr->loadMissing('materialRequests.requester');

        foreach ($pr->materialRequests as $materialRequest) {
            $stillSourcedElsewhere = $materialRequest->purchaseRequisitions()
                ->where('purchase_requisitions.id', '!=', $pr->id)
                ->where('purchase_requisitions.status', '!=', PurchaseRequisition::STATUS_CANCELLED)
                ->exists();

            if ($stillSourcedElsewhere || ! $materialRequest->canTransitionTo(MaterialRequest::STATUS_APPROVED)) {
                continue;
            }

            $materialRequest->transitionTo(
                MaterialRequest::STATUS_APPROVED,
                $request->user(),
                "Returned to approved demand: Purchase Requisition {$pr->pr_number} was cancelled."
            );
        }
    }

    private function assertInCurrentTenant(PurchaseRequisition $pr): void
    {
        abort_unless(Company::query()->pluck('id')->contains($pr->company_id), 404);
    }

    /**
     * v2.51.0 -- Purchase Requisition / FPB as a printable document, on
     * the tenant's own letterhead via the shared document system.
     */
    public function pdf(PurchaseRequisition $purchaseRequisition, PdfGeneratorService $pdf, DocumentEngine $documents): \Illuminate\Http\Response
    {
        $this->assertInCurrentTenant($purchaseRequisition);
        $purchaseRequisition->load('company', 'project', 'department', 'requester');

        return $pdf->streamInline('pdf.purchase-requisition', [
            'purchaseRequisition' => $purchaseRequisition,
            'identity' => $documents->identity(),
            'documentTemplate' => $documents->resolveTemplate('purchase_requisition', $purchaseRequisition->company_id),
        ], "{$purchaseRequisition->pr_number}.pdf");
    }
}
