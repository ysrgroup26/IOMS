<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use Illuminate\Support\Collection;

/**
 * Builds the "Report Page" data structure: employees grouped by department,
 * each with a row of KPI totals across all categories (Fatality, LTI, FAC,
 * PPE Violation, BBS, Drill, Campaign, TBM) -- mirroring the Excel sheet
 * this application replaces. Shared by ReportController (web view),
 * KpiReportExport (Excel), and KpiReportPdfExport (PDF) so the exact same
 * numbers appear everywhere, single source of truth.
 */
class KpiReportService
{
    /**
     * @return array{
     *   categories: Collection<int, KpiCategory>,
     *   departments: array<int, array{department_name: string, rows: array}>
     * }
     */
    public function build(?int $year = null, ?int $month = null, ?int $departmentId = null, ?int $companyId = null): array
    {
        $year ??= (int) now()->format('Y');
        $categories = KpiCategory::active()->visibleForCompany($companyId)->get();

        $employeesQuery = Employee::query()
            ->with('department', 'position')
            ->active()
            ->inCompany($companyId)
            ->inDepartment($departmentId)
            ->orderedForDisplay();

        $employees = $employeesQuery->get();

        // Pull all matching KPI totals in one grouped query instead of N+1 per employee.
        $totals = KpiRecord::query()
            ->join('kpi_categories', 'kpi_categories.id', '=', 'kpi_records.kpi_category_id')
            ->when($companyId, function ($q) use ($companyId) {
                $q->join('departments', 'departments.id', '=', 'kpi_records.department_id')
                    ->where('departments.company_id', $companyId);
            })
            ->forPeriod($year, $month)
            ->forDepartment($departmentId)
            ->selectRaw('kpi_records.employee_id, kpi_categories.code, SUM(kpi_records.quantity) as total')
            ->groupBy('kpi_records.employee_id', 'kpi_categories.code')
            ->get()
            ->groupBy('employee_id');

        $departmentGroups = $employees->groupBy(fn (Employee $e) => $e->department->name ?? 'Unassigned');

        $result = [];
        foreach ($departmentGroups as $departmentName => $employeesInDept) {
            $rows = [];
            foreach ($employeesInDept as $employee) {
                $employeeTotals = ($totals->get($employee->id) ?? collect())
                    ->pluck('total', 'code')
                    ->map(fn ($v) => (int) $v);

                $row = [
                    'employee' => $employee,
                ];
                foreach ($categories as $category) {
                    $row[$category->code] = $employeeTotals->get($category->code, 0);
                }
                $rows[] = $row;
            }

            $result[] = [
                'department_name' => $departmentName,
                'rows' => $rows,
            ];
        }

        return [
            'categories' => $categories,
            'departments' => $result,
            'year' => $year,
            'month' => $month,
        ];
    }
}
