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
    public function index(Request $request): Response
    {
        $year = (int) $request->input('year', now()->format('Y'));
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $categoryId = $request->input('category_id') ? (int) $request->input('category_id') : null;

        $records = KpiRecord::query()
            ->with('employee:id,full_name,company_id,department_id', 'employee.company:id,name', 'department:id,name', 'kpiCategory:id,name,short_label,is_negative')
            ->forPeriod($year, $month)
            ->when($categoryId, fn ($q) => $q->where('kpi_category_id', $categoryId))
            ->when($companyId, function ($q) use ($companyId) {
                $q->join('departments', 'departments.id', '=', 'kpi_records.department_id')
                    ->where('departments.company_id', $companyId)
                    ->select('kpi_records.*');
            })
            ->latest('record_date')
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
