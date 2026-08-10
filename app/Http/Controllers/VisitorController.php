<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Visitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 5 (Visitor Management). */
class VisitorController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $visitors = Visitor::whereIn('company_id', $tenantCompanyIds)
            ->with('hostEmployee:id,full_name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('visitor_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('visit_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Visitors/Index', [
            'visitors' => $visitors,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageVisitors()],
        ]);
    }

    public function create(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('Visitors/Form', [
            'employees' => Employee::whereIn('company_id', $tenantCompanyIds)->active()->orderBy('full_name')->get(['id', 'full_name', 'company_id']),
            'visitorNumber' => Visitor::generateNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantEmployeeIds = Employee::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visitor_company' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'host_employee_id' => ['required', Rule::in($tenantEmployeeIds)],
            'visit_date' => ['required', 'date'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $host = Employee::find($data['host_employee_id']);

        $visitor = Visitor::create([
            ...$data,
            'visitor_number' => Visitor::generateNumber(),
            'company_id' => $host->company_id,
            'status' => Visitor::STATUS_PENDING,
        ]);

        ActivityLog::record('created', "Registered visitor \"{$visitor->name}\" ({$visitor->visitor_number}).", $visitor);

        return redirect()->route('visitors.show', $visitor)->with('flash', ['success' => 'Visitor registered.']);
    }

    public function show(Visitor $visitor, Request $request): Response
    {
        $this->assertInCurrentTenant($visitor);
        $visitor->load('hostEmployee:id,full_name');

        return Inertia::render('Visitors/Show', [
            'visitor' => $visitor,
            'canManage' => $request->user()->canManageVisitors(),
        ]);
    }

    public function approve(Visitor $visitor, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageVisitors(), 403);
        $this->assertInCurrentTenant($visitor);
        abort_unless($visitor->status === Visitor::STATUS_PENDING, 422);

        $visitor->update(['status' => Visitor::STATUS_APPROVED]);
        ActivityLog::record('updated', "Approved visitor \"{$visitor->name}\".", $visitor);

        return back()->with('success', 'Visitor approved.');
    }

    public function reject(Visitor $visitor, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageVisitors(), 403);
        $this->assertInCurrentTenant($visitor);
        abort_unless($visitor->status === Visitor::STATUS_PENDING, 422);

        $visitor->update(['status' => Visitor::STATUS_REJECTED]);
        ActivityLog::record('updated', "Rejected visitor \"{$visitor->name}\".", $visitor);

        return back()->with('success', 'Visitor rejected.');
    }

    public function toggleInduction(Visitor $visitor, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageVisitors(), 403);
        $this->assertInCurrentTenant($visitor);

        $visitor->update(['hse_induction_completed' => ! $visitor->hse_induction_completed]);

        return back()->with('success', 'HSE induction status updated.');
    }

    public function checkIn(Visitor $visitor, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageVisitors(), 403);
        $this->assertInCurrentTenant($visitor);
        abort_unless($visitor->status === Visitor::STATUS_APPROVED, 422);

        if (! $visitor->hse_induction_completed) {
            return back()->with('error', 'Complete HSE induction before check-in.');
        }

        $visitor->update(['status' => Visitor::STATUS_CHECKED_IN, 'checked_in_at' => now()]);
        ActivityLog::record('updated', "Checked in visitor \"{$visitor->name}\".", $visitor);

        return back()->with('success', 'Visitor checked in.');
    }

    public function checkOut(Visitor $visitor, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageVisitors(), 403);
        $this->assertInCurrentTenant($visitor);
        abort_unless($visitor->status === Visitor::STATUS_CHECKED_IN, 422);

        $visitor->update(['status' => Visitor::STATUS_CHECKED_OUT, 'checked_out_at' => now()]);
        ActivityLog::record('updated', "Checked out visitor \"{$visitor->name}\".", $visitor);

        return back()->with('success', 'Visitor checked out.');
    }

    private function assertInCurrentTenant(Visitor $visitor): void
    {
        abort_unless(Company::query()->pluck('id')->contains($visitor->company_id), 404);
    }
}
