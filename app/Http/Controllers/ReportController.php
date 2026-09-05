<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Services\KpiReportService;
use App\Services\ReportTemplateResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(private readonly KpiReportService $reportService) {}

    public function index(Request $request): Response
    {
        $year = (int) $request->input('year', now()->format('Y'));
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $report = $this->reportService->build($year, $month, $departmentId, $companyId);

        return Inertia::render('Reports/Index', [
            'report' => $report,
            'filters' => ['year' => $year, 'month' => $month, 'department_id' => $departmentId, 'company_id' => $companyId],
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->ordered()
                ->get(['id', 'name', 'company_id']),
            'availableYears' => range((int) now()->format('Y'), (int) now()->format('Y') - 4),
        ]);
    }

    public function exportExcel(Request $request, ReportTemplateResolver $templateResolver)
    {
        $year = (int) $request->input('year', now()->format('Y'));
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        ActivityLog::record('exported', "Exported KPI report to Excel (year {$year}).", null, compact('year', 'month', 'departmentId', 'companyId'));

        $filename = "hse-kpi-report-{$year}".($month ? "-{$month}" : '').'.xlsx';

        $export = $templateResolver->resolve($companyId, $this->reportService, $year, $month, $departmentId);

        return Excel::download($export, $filename);
    }

    public function exportPdf(Request $request)
    {
        $year = (int) $request->input('year', now()->format('Y'));
        $month = $request->input('month') ? (int) $request->input('month') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $report = $this->reportService->build($year, $month, $departmentId, $companyId);

        ActivityLog::record('exported', "Exported KPI report to PDF (year {$year}).", null, compact('year', 'month', 'departmentId', 'companyId'));

        $pdf = Pdf::loadView('exports.kpi-report-pdf', [
            'report' => $report,
            'year' => $year,
            'month' => $month,
            'companyName' => CompanySetting::get('company_name', config('ioms.name')),
        ])->setPaper('a4', 'landscape');

        $filename = "hse-kpi-report-{$year}".($month ? "-{$month}" : '').'.pdf';

        return $pdf->download($filename);
    }
}
