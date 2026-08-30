<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMaterialRequestRequest;
use App\Http\Requests\UpdateMaterialRequestRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Services\DocumentEngine;
use App\Services\PdfGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class MaterialRequestController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();

        $requests = MaterialRequest::query()
            ->visibleTo($user)
            ->with('company:id,name', 'department:id,name', 'project:id,name', 'requester:id,name')
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $v) => $q->where('request_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('request_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('MaterialRequests/Index', [
            'requests' => $requests,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $user->canManageMaterialRequests()],
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        return Inertia::render('MaterialRequests/Form', [
            'materialRequest' => null,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'projects' => Project::orderBy('name')->get(['id', 'name', 'company_id']),
            'requestNumber' => MaterialRequest::generateRequestNumber(),
        ]);
    }

    public function store(StoreMaterialRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            $materialRequest = MaterialRequest::create([
                'request_number' => MaterialRequest::generateRequestNumber(),
                'request_date' => $data['request_date'],
                'company_id' => $data['company_id'],
                'project_id' => $data['project_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'requested_by' => $request->user()->id,
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $index => $item) {
                $imagePath = null;
                if ($request->hasFile("items.{$index}.reference_image")) {
                    $imagePath = $request->file("items.{$index}.reference_image")->store('uploads/material-requests', 'public');
                }

                $materialRequest->items()->create([
                    'item_name' => $item['item_name'],
                    'specification' => $item['specification'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'reference_image_path' => $imagePath,
                    'remarks' => $item['remarks'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            ActivityLog::record('created', "Created Material Request {$materialRequest->request_number}.", $materialRequest);

            // Universal Approval Engine (v1.6.9) -- a draft has nothing
            // to approve yet; submitting is what actually creates the
            // pending Approval record a Super Admin can act on.
            if ($materialRequest->status === MaterialRequest::STATUS_SUBMITTED) {
                $materialRequest->submitForApproval($request->user());
                ActivityLog::record('submitted', "Submitted Material Request {$materialRequest->request_number} for approval.", $materialRequest);
            }
        });

        return redirect()->route('material-requests.index')->with('flash', ['success' => 'Material Request created.']);
    }

    /**
     * v2.12.0 (Product Finalization pass, Part 26 -- Security). CONFIRMED
     * P0 via this pass's own audit: every method in this controller that
     * receives a route-bound MaterialRequest (show/edit/update/destroy/
     * process/complete/reopen/cancel/pdf) had NO tenant-ownership check
     * at all -- `MaterialRequest` carries no `TenantScope`, and none of
     * these methods ever verified the bound record actually belongs to
     * the current tenant before acting on it. A user could view, edit,
     * process, download the PDF of, or cancel another tenant's Material
     * Request purely by changing the `{materialRequest}` ID in the URL.
     * `index()`'s own `scopeVisibleTo()` also had a related gap (its
     * Super Admin bypass returned literally every tenant's rows, not
     * just the current tenant's -- fixed in the model, see
     * MaterialRequest::scopeVisibleTo()'s own updated doc comment).
     * Fixed here by adding the identical `assertInCurrentTenant()`
     * 404-not-403 guard this codebase already uses everywhere else
     * (WasteRecordController/EmployeeController/AssetController/
     * VendorController/PermitToWorkController, etc.) to every method
     * below that accepts a route-bound record.
     */
    private function assertInCurrentTenant(MaterialRequest $materialRequest): void
    {
        abort_unless(Company::query()->pluck('id')->contains($materialRequest->company_id), 404);
    }

    public function show(MaterialRequest $materialRequest, Request $request): InertiaResponse
    {
        $this->assertInCurrentTenant($materialRequest);
        $materialRequest->load('company:id,name', 'department:id,name', 'project:id,name', 'requester:id,name', 'items');
        $approval = $materialRequest->latestApproval()?->load('requester:id,name', 'approver:id,name');

        // Reusable across every future approvable module's Show page --
        // the same shape (an `approval` object + a `canDecide` flag) is
        // what any ApprovalActions-consuming page would pass.
        $activities = ActivityLog::where('subject_type', MaterialRequest::class)
            ->where('subject_id', $materialRequest->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('MaterialRequests/Show', [
            'materialRequest' => $materialRequest,
            'approval' => $approval,
            'activities' => $activities,
            'canDecide' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.approvers', []), true),
            'canProcess' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.processors', []), true),
            'canOverride' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.overriders', []), true),
        ]);
    }

    public function edit(MaterialRequest $materialRequest): InertiaResponse
    {
        $this->assertInCurrentTenant($materialRequest);
        $materialRequest->load('items');

        return Inertia::render('MaterialRequests/Form', [
            'materialRequest' => $materialRequest,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'projects' => Project::orderBy('name')->get(['id', 'name', 'company_id']),
            'requestNumber' => $materialRequest->request_number,
        ]);
    }

    public function update(UpdateMaterialRequestRequest $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertInCurrentTenant($materialRequest);
        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $materialRequest) {
            $materialRequest->update([
                'request_date' => $data['request_date'],
                'company_id' => $data['company_id'],
                'project_id' => $data['project_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ]);

            $keepIds = [];
            foreach ($data['items'] as $index => $item) {
                $imagePath = null;
                $existing = ! empty($item['id']) ? $materialRequest->items()->find($item['id']) : null;

                if ($request->hasFile("items.{$index}.reference_image")) {
                    if ($existing?->reference_image_path) {
                        Storage::disk('public')->delete($existing->reference_image_path);
                    }
                    $imagePath = $request->file("items.{$index}.reference_image")->store('uploads/material-requests', 'public');
                } elseif ($existing) {
                    $imagePath = $existing->reference_image_path;
                }

                $itemModel = $materialRequest->items()->updateOrCreate(
                    ['id' => $item['id'] ?? null],
                    [
                        'item_name' => $item['item_name'],
                        'specification' => $item['specification'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'reference_image_path' => $imagePath,
                        'remarks' => $item['remarks'] ?? null,
                        'sort_order' => $index,
                    ]
                );
                $keepIds[] = $itemModel->id;
            }

            // Remove rows for items the user deleted client-side, cleaning
            // up their stored images too so uploads don't accumulate as
            // orphaned files.
            $removed = $materialRequest->items()->whereNotIn('id', $keepIds)->get();
            foreach ($removed as $item) {
                if ($item->reference_image_path) {
                    Storage::disk('public')->delete($item->reference_image_path);
                }
            }
            $materialRequest->items()->whereNotIn('id', $keepIds)->delete();

            // Universal Approval Engine (v1.6.9) -- only fires on a
            // genuine draft->submitted transition (wasChanged), not on
            // every save of an already-submitted request. submitForApproval()'s
            // own idempotent guard against duplicate pending approvals
            // would prevent a double-submit either way, but checking
            // here avoids a redundant ActivityLog entry too.
            if ($materialRequest->wasChanged('status') && $materialRequest->status === MaterialRequest::STATUS_SUBMITTED) {
                $materialRequest->submitForApproval($request->user());
                ActivityLog::record('submitted', "Submitted Material Request {$materialRequest->request_number} for approval.", $materialRequest);
            } else {
                ActivityLog::record('updated', "Updated Material Request {$materialRequest->request_number}.", $materialRequest);
            }
        });

        return redirect()->route('material-requests.index')->with('flash', ['success' => 'Material Request updated.']);
    }

    public function destroy(MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertInCurrentTenant($materialRequest);

        // Only draft requests can be deleted -- once submitted, a request
        // is a real document that may already be printed/circulating.
        if ($materialRequest->status !== MaterialRequest::STATUS_DRAFT) {
            return back()->with('flash', ['error' => 'Only draft requests can be deleted.']);
        }

        foreach ($materialRequest->items as $item) {
            if ($item->reference_image_path) {
                Storage::disk('public')->delete($item->reference_image_path);
            }
        }

        $materialRequest->delete();

        return redirect()->route('material-requests.index')->with('flash', ['success' => 'Draft deleted.']);
    }

    /**
     * Warehouse (or Super Admin) starts processing an approved request.
     * Authorization reuses the same reusable config every future
     * workflow-driven module would -- see config/workflow.php.
     */
    public function process(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertInCurrentTenant($materialRequest);
        $this->authorizeWorkflowAction($request, 'processors');

        try {
            $materialRequest->transitionTo(MaterialRequest::STATUS_PROCESSING, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Marked as Processing.']);
    }

    public function complete(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertInCurrentTenant($materialRequest);
        $this->authorizeWorkflowAction($request, 'processors');

        try {
            $materialRequest->transitionTo(MaterialRequest::STATUS_COMPLETED, $request->user());
            $materialRequest->update(['completed_at' => now()]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Marked as Completed.']);
    }

    /**
     * Company Admin (Super Admin) override: reopens a Rejected request
     * back to Draft. Deliberately gated to 'overriders' specifically,
     * not 'approvers' -- matching "Rejected returns to Draft only if
     * business rules allow," which this app enforces as "only an
     * explicit override, never a standard user action."
     */
    public function reopen(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertInCurrentTenant($materialRequest);
        $this->authorizeWorkflowAction($request, 'overriders');

        try {
            $materialRequest->transitionTo(MaterialRequest::STATUS_DRAFT, $request->user(), 'Reopened to Draft by override.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Reopened to Draft.']);
    }

    public function cancel(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $this->assertInCurrentTenant($materialRequest);
        $this->authorizeWorkflowAction($request, 'overriders');

        try {
            $materialRequest->transitionTo(MaterialRequest::STATUS_CANCELLED, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Request cancelled.']);
    }

    private function authorizeWorkflowAction(Request $request, string $configKey): void
    {
        $allowed = config("workflow.{$configKey}", []);
        abort_unless($request->user()->isSuperAdmin() || in_array($request->user()->role, $allowed, true), 403);
    }

    public function pdf(MaterialRequest $materialRequest, PdfGeneratorService $pdf, DocumentEngine $documents): Response
    {
        $this->assertInCurrentTenant($materialRequest);
        $materialRequest->load('company', 'department', 'project', 'requester', 'items');

        // Milestone 3 (Dynamic Document Engine, Task #66): first real
        // consumer of DocumentEngine -- see its own doc comment on why
        // this is template RESOLUTION feeding the existing Blade view,
        // not a second rendering pipeline. documentTemplate is null (and
        // the view falls back to its original hardcoded chrome) until a
        // Company Admin actually creates one in Settings > Documents.
        return $pdf->streamInline('pdf.material-request', [
            'materialRequest' => $materialRequest,
            'company' => $materialRequest->company,
            'documentTemplate' => $documents->resolveTemplate('material_request', $materialRequest->company_id),
            'branding' => $documents->branding(),
        ], "{$materialRequest->request_number}.pdf");
    }
}
