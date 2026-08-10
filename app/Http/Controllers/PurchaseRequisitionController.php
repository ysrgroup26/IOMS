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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
            'materialRequests' => MaterialRequest::whereIn('company_id', $tenantCompanyIds)
                ->whereIn('status', ['approved', 'processing'])
                ->orderByDesc('request_date')
                ->get(['id', 'request_number', 'company_id']),
            'prNumber' => PurchaseRequisition::generateNumber(),
            'priorities' => PurchaseRequisition::PRIORITIES,
        ]);
    }

    public function store(StorePurchaseRequisitionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $estimatedTotal = collect($data['items'])->sum(fn ($i) => ($i['quantity'] ?? 0) * ($i['estimated_unit_price'] ?? 0));

        $pr = PurchaseRequisition::create([
            ...$data,
            'pr_number' => PurchaseRequisition::generateNumber(),
            'estimated_total' => $estimatedTotal,
            'status' => PurchaseRequisition::STATUS_DRAFT,
            'requested_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Created Purchase Requisition {$pr->pr_number}.", $pr);

        return redirect()->route('purchase-requisitions.show', $pr)->with('flash', ['success' => 'Purchase Requisition created.']);
    }

    public function edit(PurchaseRequisition $purchaseRequisition, Request $request): Response
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($purchaseRequisition);
        abort_unless($purchaseRequisition->status === PurchaseRequisition::STATUS_DRAFT, 422);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('PurchaseRequisitions/Form', [
            'purchaseRequisition' => $purchaseRequisition,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::whereIn('company_id', $tenantCompanyIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'materialRequests' => MaterialRequest::whereIn('company_id', $tenantCompanyIds)
                ->whereIn('status', ['approved', 'processing'])
                ->orderByDesc('request_date')
                ->get(['id', 'request_number', 'company_id']),
            'prNumber' => $purchaseRequisition->pr_number,
            'priorities' => PurchaseRequisition::PRIORITIES,
        ]);
    }

    public function update(StorePurchaseRequisitionRequest $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        $this->assertInCurrentTenant($purchaseRequisition);
        abort_unless($purchaseRequisition->status === PurchaseRequisition::STATUS_DRAFT, 422);

        $data = $request->validated();
        $data['estimated_total'] = collect($data['items'])->sum(fn ($i) => ($i['quantity'] ?? 0) * ($i['estimated_unit_price'] ?? 0));

        $purchaseRequisition->update($data);
        ActivityLog::record('updated', "Updated Purchase Requisition {$purchaseRequisition->pr_number}.", $purchaseRequisition);

        return redirect()->route('purchase-requisitions.show', $purchaseRequisition)->with('flash', ['success' => 'Purchase Requisition updated.']);
    }

    public function show(PurchaseRequisition $purchaseRequisition, Request $request): Response
    {
        $this->assertInCurrentTenant($purchaseRequisition);
        $purchaseRequisition->load('company:id,name', 'project:id,name', 'department:id,name', 'sourceMaterialRequest:id,request_number', 'requester:id,name', 'rfqs:id,rfq_number,purchase_requisition_id', 'purchaseOrders:id,po_number,purchase_requisition_id');

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

        return $this->doTransition($purchaseRequisition, PurchaseRequisition::STATUS_CANCELLED, $request);
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

    private function assertInCurrentTenant(PurchaseRequisition $pr): void
    {
        abort_unless(Company::query()->pluck('id')->contains($pr->company_id), 404);
    }
}
