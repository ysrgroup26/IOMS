<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave (v1.10.0) -- HR's first real module beyond Employees. Validation
 * is inline ($request->validate()) rather than a dedicated FormRequest
 * class: unlike Material Request there's no dynamic item table here, so
 * a separate Request class would just be indirection for a handful of
 * fields.
 *
 * v1.11.7 tenant-isolation fix (Production Readiness Follow-Up, Part 5):
 * `index()` had NO company/tenant scoping at all -- every tenant's leave
 * requests were returned to every other tenant. `show()`/`cancel()` had
 * no per-record tenant check either -- a LeaveRequest id from any tenant
 * could be viewed or cancelled by a user in a completely different
 * tenant. Fixed with the same `abort_unless(Company::query()->pluck('id')
 * ->contains(...), 404)` pattern already used throughout HSE/Logistics/
 * Procurement controllers (see IncidentController's own doc comment for
 * the convention this follows) -- not a new pattern, just applied here
 * where it had been missed.
 */
class LeaveRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $leaveRequests = LeaveRequest::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('employee:id,full_name,employee_id', 'requester:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('leave_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('start_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Leave/Index', [
            'leaveRequests' => $leaveRequests,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageLeaveRequests()],
        ]);
    }

    public function create(): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('Leave/Form', [
            'leaveRequest' => null,
            'employees' => Employee::active()->whereIn('company_id', $tenantCompanyIds)->orderBy('full_name')->get(['id', 'full_name', 'employee_id']),
            'leaveNumber' => LeaveRequest::generateLeaveNumber(),
            'types' => LeaveRequest::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageLeaveRequests(), 403);

        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'leave_type' => ['required', 'in:'.implode(',', LeaveRequest::TYPES)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:draft,submitted'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        abort_unless(Company::query()->pluck('id')->contains($employee->company_id), 404);

        $leaveRequest = LeaveRequest::create([
            'leave_number' => LeaveRequest::generateLeaveNumber(),
            'employee_id' => $data['employee_id'],
            'company_id' => $employee->company_id,
            'leave_type' => $data['leave_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days' => Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1,
            'reason' => $data['reason'] ?? null,
            'status' => $data['status'],
            'requested_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Created Leave Request {$leaveRequest->leave_number}.", $leaveRequest);

        if ($leaveRequest->status === LeaveRequest::STATUS_SUBMITTED) {
            $leaveRequest->submitForApproval($request->user());
            ActivityLog::record('submitted', "Submitted Leave Request {$leaveRequest->leave_number} for approval.", $leaveRequest);
        }

        return redirect()->route('leave-requests.index')->with('flash', ['success' => 'Leave Request created.']);
    }

    public function show(LeaveRequest $leaveRequest, Request $request): Response
    {
        $this->assertInCurrentTenant($leaveRequest);

        $leaveRequest->load('employee:id,full_name,employee_id', 'requester:id,name');
        $approval = $leaveRequest->latestApproval()?->load('requester:id,name', 'approver:id,name');

        $activities = ActivityLog::where('subject_type', LeaveRequest::class)
            ->where('subject_id', $leaveRequest->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('Leave/Show', [
            'leaveRequest' => $leaveRequest,
            'approval' => $approval,
            'activities' => $activities,
            'canDecide' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.approvers', []), true),
            'canManage' => $request->user()->canManageLeaveRequests(),
        ]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        abort_unless($request->user()->canManageLeaveRequests(), 403);
        $this->assertInCurrentTenant($leaveRequest);

        try {
            $leaveRequest->transitionTo(LeaveRequest::STATUS_CANCELLED, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Leave Request cancelled.']);
    }

    /** Same 404-not-403 tenant-isolation convention used throughout HSE/Logistics/Procurement -- see IncidentController's own doc comment. */
    private function assertInCurrentTenant(LeaveRequest $leaveRequest): void
    {
        abort_unless(Company::query()->pluck('id')->contains($leaveRequest->company_id), 404);
    }
}
