<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flat, filterable list of individual KPI records -- the destination for
 * the Dashboard's clickable KPI category cards (v1.5.2: "Click FAC → Open
 * KPI page → Automatically filter Category = FAC"). Distinct from the
 * Reports page, which shows an aggregated department x category matrix;
 * this shows one row per occurrence, which is what "show me every FAC
 * this month" actually needs.
 */
class KpiRecordController extends Controller
{
    /**
     * v2.12.0 (Product Finalization pass, Part 26 -- Security). CONFIRMED
     * P0 via this pass's own audit: the tenant-scoping join below was
     * only ever applied inside `when($companyId, ...)` -- on the
     * default landing state (no `?company_id=` selected, i.e. every
     * normal page load), this returned EVERY tenant's KPI records, not
     * just the current tenant's. `KpiRecord` carries no `TenantScope`.
     * Fixed by making the `departments` join + tenant-company
     * whereIn unconditional (the exact same
     * `Company::query()->pluck('id')` tenant-boundary pattern used
     * throughout this codebase), with the specific `$companyId`
     * selection (when present) narrowing further on top of it, not
     * replacing it.
     */
    public function index(Request $request): Response
    {
        $year = (int) $request->input('year', now()->format('Y'));
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $categoryId = $request->input('category_id') ? (int) $request->input('category_id') : null;
        $tenantCompanyIds = Company::query()->pluck('id');

        $records = KpiRecord::query()
            ->join('departments', 'departments.id', '=', 'kpi_records.department_id')
            ->whereIn('departments.company_id', $tenantCompanyIds)
            ->when($companyId, fn ($q) => $q->where('departments.company_id', $companyId))
            ->select('kpi_records.*')
            ->with('employee:id,full_name,company_id,department_id', 'employee.company:id,name', 'department:id,name', 'kpiCategory:id,name,short_label,is_negative')
            ->forPeriod($year, $month)
            ->when($categoryId, fn ($q) => $q->where('kpi_records.kpi_category_id', $categoryId))
            ->latest('kpi_records.record_date')
            ->latest('kpi_records.id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Kpi/Records', [
            'records' => $records,
            'categories' => KpiCategory::active()->visibleForCompany($companyId)->get(['id', 'name', 'short_label']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => ['year' => $year, 'month' => $month, 'company_id' => $companyId, 'category_id' => $categoryId],
            'availableYears' => range((int) now()->format('Y'), (int) now()->format('Y') - 4),
        ]);
    }
}
