<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompetencyType;
use App\Models\EmployeeCompetency;
use App\Models\Position;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream A2 (Training & Competency Management). The
 * Master (catalog) page and the cross-employee "Expiring Soon" list --
 * mirrors PpeController's master()/replacementDue() shape.
 *
 * Tenant safety: every query here filters through `Company::query()`
 * (which already respects App\Models\Scopes\TenantScope) rather than
 * the `when($companyId, ...)` "no filter if nothing selected" pattern --
 * see DashboardStatsService::resolveCompanyIds()'s own doc comment for
 * why that pattern is unsafe once multiple tenants share one database.
 * `company_id` here is never a raw, unvalidated request input passed
 * straight into a query.
 */
class CompetencyController extends Controller
{
    public function master(): Response
    {
        $companyIds = Company::query()->pluck('id');

        return Inertia::render('Competency/Master', [
            'competencyTypes' => CompetencyType::whereIn('company_id', $companyIds)
                ->withCount('employeeCompetencies')
                ->with('requiredByPositions:id,name')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'positions' => Position::where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'can' => ['manage' => request()->user()->isAdmin()],
        ]);
    }

    /**
     * Cross-employee expiry monitoring -- the real, data-backed answer to
     * the Milestone 4 spec's "HR: Competency Expiry" reporting KPI (see
     * Workstream J). Only ever scoped to companies the CURRENT tenant
     * actually owns, never a raw company_id-or-nothing filter.
     */
    public function expiringSoon(Request $request): Response
    {
        $companyIds = Company::query()->pluck('id')->all();
        $requestedCompanyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $effectiveCompanyIds = ($requestedCompanyId && in_array($requestedCompanyId, $companyIds, true))
            ? [$requestedCompanyId]
            : $companyIds;

        // effective_status is a computed accessor, not a stored column --
        // filtered here via the model's own scopeEffectiveStatus(), kept
        // logically identical to the accessor by design (see that
        // method's own doc comment).
        $items = EmployeeCompetency::query()
            ->whereHas('employee', fn ($q) => $q->whereIn('company_id', $effectiveCompanyIds))
            ->where(function ($q) {
                $q->effectiveStatus('expiring_soon')->orWhere(fn ($qq) => $qq->effectiveStatus('expired'));
            })
            ->with('employee:id,employee_id,full_name,company_id,department_id', 'employee.company:id,name', 'employee.department:id,name', 'competencyType:id,name,type')
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (EmployeeCompetency $c) => [
                'id' => $c->id,
                'employee_id' => $c->employee->id,
                'employee_name' => $c->employee->full_name,
                'employee_code' => $c->employee->employee_id,
                'company' => $c->employee->company?->name,
                'department' => $c->employee->department?->name,
                'competency_name' => $c->competencyType->name,
                'competency_type' => $c->competencyType->type,
                'expiry_date' => $c->expiry_date?->format('d M Y'),
                'days_remaining' => $c->days_remaining,
                'effective_status' => $c->effective_status,
            ]);

        return Inertia::render('Competency/ExpiringSoon', [
            'items' => $items,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => ['company_id' => $requestedCompanyId],
        ]);
    }
}
