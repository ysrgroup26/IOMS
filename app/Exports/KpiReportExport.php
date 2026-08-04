<?php

namespace App\Exports;

use App\Contracts\ReportExportInterface;
use App\Models\CompanySetting;
use App\Services\KpiReportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Renders the KPI report exactly like the Excel sheet it replaces:
 * grouped by department, one row per employee, one column per KPI category.
 *
 * Also the generic/default implementation of ReportExportInterface
 * (v1.6.8) -- what ReportTemplateResolver falls back to for any company
 * that doesn't have a specific template registered.
 */
class KpiReportExport implements FromArray, ReportExportInterface, ShouldAutoSize, WithEvents, WithProperties, WithStyles
{
    private array $reportData;

    private array $headerRowIndexes = [];

    public function build(): static
    {
        return $this;
    }

    public function __construct(
        private readonly KpiReportService $reportService,
        private readonly int $year,
        private readonly ?int $month,
        private readonly ?int $departmentId,
        private readonly ?int $companyId = null,
    ) {
        $this->reportData = $this->reportService->build($year, $month, $departmentId, $companyId);
    }

    public function array(): array
    {
        $rows = [];
        $categories = $this->reportData['categories'];

        $title = 'HSE KPI REPORT - '.$this->year.($this->month ? ' / Month '.$this->month : '');
        $rows[] = [$title];
        $rows[] = [];

        foreach ($this->reportData['departments'] as $group) {
            $this->headerRowIndexes[] = count($rows);
            $rows[] = array_merge(
                ['Employee', 'Employee ID', 'Department'],
                $categories->pluck('short_label')->toArray()
            );

            foreach ($group['rows'] as $row) {
                $employee = $row['employee'];
                $line = [$employee->full_name, $employee->employee_id, $group['department_name']];
                foreach ($categories as $category) {
                    $line[] = $row[$category->code];
                }
                $rows[] = $line;
            }

            $rows[] = []; // spacer between departments
        }

        return $rows;
    }

    /**
     * Overrides config('excel.exports.properties') with the LIVE company
     * name from Branding Settings (v1.5.2). Reading this from config()
     * directly wouldn't work correctly in production: config:cache
     * freezes every config value into a single static file, so a
     * CompanySetting-backed value embedded there would never update
     * again after the first cache. Computing it here, at export time,
     * keeps it genuinely dynamic regardless of config caching.
     */
    public function properties(): array
    {
        $companyName = CompanySetting::get('company_name', 'Integrated Operations Management System');

        return [
            'creator' => $companyName,
            'lastModifiedBy' => $companyName,
            'title' => 'HSE KPI Report',
            'description' => "Exported from {$companyName}",
            'subject' => 'HSE KPI Report',
            'keywords' => 'hse,kpi,operations,report',
            'category' => 'HSE',
            'company' => $companyName,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                foreach ($this->headerRowIndexes as $rowIndex) {
                    $excelRow = $rowIndex + 1; // 1-indexed in PhpSpreadsheet
                    $event->sheet->getDelegate()->getStyle("A{$excelRow}:K{$excelRow}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => '2563EB'], // brand blue
                        ],
                    ]);
                }
            },
        ];
    }
}
