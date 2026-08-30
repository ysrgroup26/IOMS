<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeePpeBatchRequest;
use App\Http\Requests\StorePpeReplacementRequestRequest;
use App\Http\Requests\UpdateEmployeePpeRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\PpeReplacementRequest;
use App\Models\PpeType;
use App\Services\PdfGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PPE (APD) Management module: Master (types), Distribution (issuing),
 * History (same table as Distribution, viewed per employee), and Dashboard
 * (replacement-due summary). Master CRUD itself lives in PpeTypeController;
 * this controller renders the pages and handles employee-level assignment.
 *
 * v1.3.1: status display/filtering is now driven entirely by
 * EmployeePpe::scopeEffectiveStatus()/getEffectiveStatusAttribute()
 * (active / expiring_soon / expired, computed from expiry_date, plus the
 * manual replaced/returned lifecycle states) instead of the raw `status`
 * column alone -- see that model for the single source of truth on the
 * classification rules.
 */
class PpeController extends Controller
{
    /**
     * Employee PPE (v1.6.6 restructure): the new employee-centric entry
     * point into PPE management, replacing "search for an employee every
     * time you issue PPE" with "browse employees, click one, manage
     * their PPE from there." Reuses the existing Employee model/data
     * entirely -- no duplicate employee records, this is just a
     * different view over the same table plus a per-employee PPE
     * summary count.
     */
    public function employees(Request $request): Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        $search = $request->input('search');
        $effectiveStatus = $request->input('effective_status');
        $replacementDue = $request->boolean('replacement_due');
        $noPpeAssigned = $request->boolean('no_ppe_assigned');

        $employees = Employee::query()
            ->active()
            ->when($companyId, fn ($q) => $q->where('employees.company_id', $companyId))
            ->when($departmentId, fn ($q) => $q->where('employees.department_id', $departmentId))
            ->when($search, fn ($q, $v) => $q->search($v))
            // Dashboard click-through filters (v1.6.7): each of these
            // narrows WHICH employees appear, not what the list item
            // displays -- per the explicit "keep this list minimal, no
            // per-status badges" instruction, the item itself only ever
            // shows a single Total Assigned PPE count regardless of which
            // filter got you here.
            ->when($effectiveStatus, fn ($q, $v) => $q->whereHas('employeePpes', fn ($p) => $p->effectiveStatus($v)))
            ->when($replacementDue, fn ($q) => $q->whereHas('employeePpes', fn ($p) => $p->whereIn(
                'status', [EmployeePpe::STATUS_REPLACEMENT_REQUESTED, EmployeePpe::STATUS_REPLACEMENT_APPROVED]
            )))
            ->when($noPpeAssigned, fn ($q) => $q->doesntHave('employeePpes'))
            ->with('company:id,name', 'department:id,name')
            ->withCount('employeePpes as total_ppe_count')
            ->orderedForDisplay()
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('Ppe/Employees', [
            'employees' => $employees,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->ordered()->get(['id', 'name', 'company_id']),
            'filters' => $request->only('company_id', 'department_id', 'search', 'effective_status', 'replacement_due', 'no_ppe_assigned'),
        ]);
    }

    /**
     * Employee PPE Profile: everything about one employee's PPE in one
     * place -- current/expiring/expired items, full issue/replacement
     * history. Issue PPE from here already knows the employee, so that
     * dialog no longer needs an employee search step.
     *
     * v1.11.6 (Production Readiness pass, Part 2 -- PPE filter/context
     * loss): this route now accepts the SAME filter query params as
     * employees() (company_id/department_id/search/page) purely to hand
     * them back to the frontend as `backUrl`, so the "Back to Employee
     * PPE" link (and the post-Add-PPE redirect, which uses `back()` and
     * therefore returns to whatever URL the browser was actually on)
     * reconstructs the exact list the user came from instead of a blank
     * one. No new persistence layer -- this is the existing
     * employees()'s own filter contract, just round-tripped through the
     * URL.
     */
    public function employeeProfile(Request $request, Employee $employee): Response
    {
        $employee->load('company:id,name', 'department:id,name');

        $assignments = EmployeePpe::query()
            ->where('employee_id', $employee->id)
            ->with('ppeType', 'issuedBy:id,name')
            ->latest('issued_date')
            ->get();

        $listFilters = $request->only('company_id', 'department_id', 'search', 'page');

        return Inertia::render('Ppe/EmployeeProfile', [
            'employee' => $employee,
            'assignments' => $assignments,
            'ppeTypes' => PpeType::active()->get(),
            'can' => ['manage' => request()->user()->canManagePpeDistribution()],
            'backUrl' => route('ppe.employees').(count(array_filter($listFilters)) ? '?'.http_build_query(array_filter($listFilters)) : ''),
        ]);
    }

    public function master(): Response
    {
        return Inertia::render('Ppe/Master', [
            'ppeTypes' => PpeType::withCount('assignments')->orderBy('name')->get(),
            'can' => ['manage' => request()->user()->canManagePpeMaster()],
        ]);
    }

    /**
     * PPE Distribution + History: one page, filterable by employee/company/
     * department/PPE type/effective status. Also the destination for the
     * PPE Dashboard's clickable Active/Expiring Soon/Expired cards (via
     * the `effective_status` query param).
     */
    public function index(Request $request): Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;

        $assignments = EmployeePpe::query()
            ->with(
                'employee:id,employee_id,full_name,company_id,department_id',
                'employee.company:id,name',
                'employee.department:id,name',
                'ppeType',
                'issuedBy:id,name'
            )
            ->when($companyId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('company_id', $companyId)))
            ->when($departmentId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $departmentId)))
            ->when($request->input('ppe_type_id'), fn ($q, $v) => $q->where('ppe_type_id', $v))
            ->when($request->input('effective_status'), fn ($q, $v) => $q->effectiveStatus($v))
            ->when($request->input('employee_id'), fn ($q, $v) => $q->where('employee_id', $v))
            ->latest('issued_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Ppe/Index', [
            'assignments' => $assignments,
            'ppeTypes' => PpeType::active()->get(),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->ordered()->get(['id', 'name', 'company_id']),
            'filters' => $request->only('company_id', 'department_id', 'ppe_type_id', 'effective_status', 'employee_id'),
            'can' => ['manage' => $request->user()->canManagePpeDistribution()],
        ]);
    }

    /**
     * Employee search for the "issue PPE" form -- employees are always
     * chosen from Employee Master, never typed, matching the pattern used
     * for Project Manpower and Quick Attendance.
     */
    public function searchEmployees(Request $request)
    {
        $employees = Employee::query()
            ->active()
            ->search($request->input('search'))
            ->with('department:id,name', 'company:id,name')
            ->orderedForDisplay()
            ->limit(20)
            ->get(['employees.id', 'employees.employee_id', 'employees.full_name', 'employees.company_id', 'employees.department_id']);

        return response()->json($employees);
    }

    /**
     * Issues one or more PPE items to a single employee in one submission
     * (v1.3.1 -- previously one item per submit). Each item still becomes
     * its own employee_ppe row with its own auto-computed expiry.
     */
    public function store(StoreEmployeePpeBatchRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail($data['employee_id']);

        $created = DB::transaction(function () use ($data, $request) {
            $rows = [];
            foreach ($data['items'] as $item) {
                $assignment = EmployeePpe::create([
                    'employee_id' => $data['employee_id'],
                    'ppe_type_id' => $item['ppe_type_id'],
                    'issued_date' => $item['issued_date'],
                    'expiry_date' => $item['expiry_date'] ?? null,
                    'remarks' => $item['remarks'] ?? null,
                    'issued_by' => $request->user()->id,
                ]);
                $rows[] = $assignment;
            }

            return $rows;
        });

        $count = count($created);
        $typeNames = collect($created)->map(fn (EmployeePpe $a) => $a->ppeType->name)->implode(', ');

        ActivityLog::record(
            'created',
            "{$count} PPE item(s) issued to {$employee->full_name}: {$typeNames}."
        );

        return back()->with('success', "{$count} PPE item(s) issued to {$employee->full_name}.");
    }

    /**
     * Lifecycle transition (issued/in_use/replacement_requested/
     * replacement_approved/replacement_completed/archived) -- never used
     * for expiry-based classification, which is fully automatic (see
     * EmployeePpe::getEffectiveStatusAttribute()). For the special
     * "Replacement Completed" transition specifically, use
     * completeReplacement() below instead of this generic update -- that
     * one also creates the new issuance record.
     *
     * v1.11.6: now also accepts optional ppe_type_id/issued_date/
     * expiry_date corrections (see UpdateEmployeePpeRequest's own doc
     * comment for why). `$this->authorize()` was previously missing here
     * entirely -- the FormRequest's own authorize() only ever checked the
     * role capability, never EmployeePpePolicy's tenant-isolation check
     * (added this same pass), so that check was dead code until this
     * call was added.
     */
    public function update(UpdateEmployeePpeRequest $request, EmployeePpe $employeePpe): RedirectResponse
    {
        $this->authorize('update', $employeePpe);

        $employeePpe->update($request->validated());
        $employeePpe->load('employee', 'ppeType');

        ActivityLog::record(
            'updated',
            "{$employeePpe->ppeType->name} for {$employeePpe->employee->full_name} marked as {$employeePpe->status}.",
            $employeePpe
        );

        return back()->with('success', 'PPE record updated.');
    }

    /**
     * "Replacement Completed" is never a plain status flip -- per spec,
     * "A replaced PPE must NEVER automatically become Active again...
     * the old PPE becomes Archived, the new PPE receives a brand-new
     * issue record." This archives $employeePpe and creates a fresh
     * EmployeePpe row (status: issued) for the same employee + PPE type,
     * with its own new issued_date and freshly-computed expiry.
     */
    public function completeReplacement(Request $request, EmployeePpe $employeePpe): RedirectResponse
    {
        $this->authorize('update', $employeePpe);

        $validated = $request->validate([
            'issued_date' => ['required', 'date'],
        ]);

        $newRecord = DB::transaction(function () use ($employeePpe, $validated, $request) {
            $employeePpe->update(['status' => EmployeePpe::STATUS_ARCHIVED]);

            return EmployeePpe::create([
                'employee_id' => $employeePpe->employee_id,
                'ppe_type_id' => $employeePpe->ppe_type_id,
                'issued_date' => $validated['issued_date'],
                'status' => EmployeePpe::STATUS_ISSUED,
                'issued_by' => $request->user()->id,
            ]);
        });

        $newRecord->load('employee', 'ppeType');

        ActivityLog::record(
            'created',
            "Replacement completed: {$newRecord->ppeType->name} re-issued to {$newRecord->employee->full_name}. Previous item archived.",
            $newRecord
        );

        return back()->with('success', 'Replacement completed. The old item is archived and a new one has been issued.');
    }

    public function destroy(EmployeePpe $employeePpe): RedirectResponse
    {
        $this->authorize('delete', $employeePpe);

        $employeePpe->delete();

        return back()->with('success', 'PPE record removed.');
    }

    /**
     * PPE Dashboard: replacement-due summary, counts by type, expiring
     * soon list -- read-only, visible to all four roles. Cards here link
     * to index() with an `effective_status` filter (frontend-only change).
     */
    public function dashboard(Request $request): Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $scopedQuery = fn () => EmployeePpe::query()
            ->when($companyId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('company_id', $companyId)));

        $totalActive = $scopedQuery()->effectiveStatus('active')->count();
        $expiringSoonCount = $scopedQuery()->effectiveStatus('expiring_soon')->count();
        $expiredCount = $scopedQuery()->effectiveStatus('expired')->count();

        // Replacement Due: items already in the replacement workflow --
        // distinct from "expired" (which may not have had a replacement
        // requested yet). New KPI (v1.6.7).
        $replacementDueCount = $scopedQuery()
            ->whereIn('status', [EmployeePpe::STATUS_REPLACEMENT_REQUESTED, EmployeePpe::STATUS_REPLACEMENT_APPROVED])
            ->count();

        // No PPE Assigned: active employees with zero EmployeePpe rows at
        // all, not zero *active* ones -- this is meant to surface people
        // who have literally never been issued anything, a distinct
        // problem from "their PPE expired." New KPI (v1.6.7).
        $noPpeAssignedCount = Employee::query()
            ->active()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->doesntHave('employeePpes')
            ->count();

        $countsByType = PpeType::active()
            ->withCount(['assignments' => function ($q) use ($companyId) {
                $q->whereIn('status', EmployeePpe::IN_SERVICE_STATUSES);
                if ($companyId) {
                    $q->whereHas('employee', fn ($e) => $e->where('company_id', $companyId));
                }
            }])
            ->get()
            ->map(fn (PpeType $t) => ['name' => $t->name, 'total' => $t->assignments_count])
            ->values();

        $expiringSoon = $scopedQuery()
            ->effectiveStatus('expiring_soon')
            ->with('employee:id,full_name,company_id,department_id', 'employee.company:id,name', 'employee.department:id,name', 'ppeType:id,name')
            ->orderBy('expiry_date')
            ->limit(10)
            ->get()
            ->map(fn (EmployeePpe $a) => [
                'id' => $a->id,
                'employee_name' => $a->employee->full_name,
                'company' => $a->employee->company?->name,
                'department' => $a->employee->department?->name,
                'ppe_type' => $a->ppeType->name,
                'expiry_date' => $a->expiry_date->format('d M Y'),
                'days_left' => $a->days_remaining,
            ]);

        return Inertia::render('Ppe/Dashboard', [
            'totalActive' => $totalActive,
            'expiringSoonCount' => $expiringSoonCount,
            'expiredCount' => $expiredCount,
            'replacementDueCount' => $replacementDueCount,
            'noPpeAssignedCount' => $noPpeAssignedCount,
            'countsByType' => $countsByType,
            'expiringSoon' => $expiringSoon,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => ['company_id' => $companyId],
        ]);
    }

    /**
     * PPE Replacement Request MVP (v1.6.8): item-level list (one row per
     * EmployeePpe record needing replacement), distinct from Employee
     * PPE's employee-level list -- multi-selecting *individual PPE
     * items* to bundle into a request isn't something the employee-level
     * list can support without breaking its own "employee selector only"
     * scope. This is the dedicated page requests get created from.
     */
    public function replacementDue(Request $request): Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $items = EmployeePpe::query()
            ->whereIn('status', [EmployeePpe::STATUS_ISSUED, EmployeePpe::STATUS_IN_USE])
            ->where(function ($q) {
                $q->whereNotNull('expiry_date')->where('expiry_date', '<', now());
            })
            ->whereHas('employee', fn ($q) => $q->when($companyId, fn ($qq) => $qq->where('company_id', $companyId)))
            ->with('employee.company:id,name', 'employee.department:id,name', 'ppeType:id,name')
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (EmployeePpe $e) => [
                'id' => $e->id,
                'employee_name' => $e->employee->full_name,
                'employee_code' => $e->employee->employee_id,
                'nik' => $e->employee->nik,
                'department' => $e->employee->department?->name,
                'ppe_type' => $e->ppeType->name,
                'expiry_date' => $e->expiry_date->format('d M Y'),
                'days_overdue' => now()->diffInDays($e->expiry_date),
            ]);

        return Inertia::render('Ppe/ReplacementDue', [
            'items' => $items,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => ['company_id' => $companyId],
        ]);
    }

    /**
     * Creates one Replacement Request bundling several selected
     * EmployeePpe records, and flips each one's status to
     * replacement_requested so the existing lifecycle and this new
     * record stay in sync -- an item can't be requested twice while
     * already mid-request, since the query above only surfaces
     * issued/in_use items.
     */
    public function storeReplacementRequest(StorePpeReplacementRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            $firstItem = EmployeePpe::with('employee')->findOrFail($data['items'][0]['employee_ppe_id']);

            $replacementRequest = PpeReplacementRequest::create([
                'request_number' => PpeReplacementRequest::generateRequestNumber(),
                'request_date' => now()->toDateString(),
                'company_id' => $firstItem->employee->company_id,
                'requested_by' => $request->user()->id,
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $index => $item) {
                $photoPath = null;
                if ($request->hasFile("items.{$index}.documentation_photo")) {
                    $photoPath = $request->file("items.{$index}.documentation_photo")->store('uploads/ppe-replacement-requests', 'public');
                }

                $replacementRequest->items()->create([
                    'employee_ppe_id' => $item['employee_ppe_id'],
                    'project_id' => $item['project_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'documentation_photo_path' => $photoPath,
                    'remarks' => $item['remarks'] ?? null,
                ]);

                EmployeePpe::where('id', $item['employee_ppe_id'])->update(['status' => EmployeePpe::STATUS_REPLACEMENT_REQUESTED]);
            }
        });

        return redirect()->route('ppe.replacement-requests.index')->with('flash', ['success' => 'Replacement Request created.']);
    }

    public function replacementRequestsIndex(Request $request): Response
    {
        $user = $request->user();

        $requests = PpeReplacementRequest::query()
            ->visibleTo($user)
            ->with('company:id,name', 'requester:id,name')
            ->withCount('items')
            ->latest('request_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Ppe/ReplacementRequests', [
            'requests' => $requests,
        ]);
    }

    /**
     * v2.12.0 (Product Finalization pass, Part 26 -- Security). CONFIRMED
     * P0 via this pass's own audit: `showReplacementRequest()` and
     * `replacementRequestPdf()` had NO tenant-ownership check at all --
     * a user could view or download the PDF of another tenant's PPE
     * Replacement Request purely by changing the ID in the URL. Same
     * `assertInCurrentTenant()` 404 guard this codebase uses everywhere
     * else, added here.
     */
    private function assertReplacementRequestInCurrentTenant(PpeReplacementRequest $replacementRequest): void
    {
        abort_unless(Company::query()->pluck('id')->contains($replacementRequest->company_id), 404);
    }

    public function showReplacementRequest(PpeReplacementRequest $replacementRequest): Response
    {
        $this->assertReplacementRequestInCurrentTenant($replacementRequest);
        $replacementRequest->load(
            'company:id,name',
            'requester:id,name',
            'items.employeePpe.employee.department:id,name',
            'items.employeePpe.ppeType:id,name',
            'items.project:id,name'
        );

        return Inertia::render('Ppe/ReplacementRequestShow', [
            'replacementRequest' => $replacementRequest,
        ]);
    }

    public function replacementRequestPdf(PpeReplacementRequest $replacementRequest, PdfGeneratorService $pdf): HttpResponse
    {
        $this->assertReplacementRequestInCurrentTenant($replacementRequest);
        $replacementRequest->load(
            'company',
            'requester',
            'items.employeePpe.employee.department',
            'items.employeePpe.ppeType',
            'items.project'
        );

        return $pdf->streamInline('pdf.ppe-replacement-request', [
            'replacementRequest' => $replacementRequest,
            'company' => $replacementRequest->company,
        ], "{$replacementRequest->request_number}.pdf");
    }
}
