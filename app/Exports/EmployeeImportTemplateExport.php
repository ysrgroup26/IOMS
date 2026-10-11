<?php

namespace App\Exports;

use App\Exports\Concerns\IomsSheetFormatting;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Downloadable Employee Import template (v1.6.8). Column order and
 * names here must stay in sync with EmployeesImport's own field
 * mapping -- both read the same heading keys (snake_cased by
 * Maatwebsite\Excel's WithHeadingRow), so a change to one requires the
 * same change to the other.
 */
class EmployeeImportTemplateExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithProperties, WithStyles
{
    use IomsSheetFormatting;

    /**
     * v2.52.0: the template now opens like every other IOMS export --
     * frozen, filterable header carrying the tenant's own document
     * properties. It matters more here than elsewhere: this is the file a
     * customer fills in and sends back, so the clearer the column
     * structure is, the fewer rows come back malformed.
     *
     * `styles()` below is UNCHANGED. It marks which columns are required,
     * which is template-specific meaning the shared foundation has no
     * business overwriting.
     */
    public function properties(): array
    {
        return $this->iomsProperties('Employee Import Template', 'Fill this template and upload it in IOMS > Employees > Import.');
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->applyIomsSheetFormatting($event->sheet->getDelegate()),
        ];
    }

    public function headings(): array
    {
        return [
            'Employee ID', 'NIK (KTP)', 'Full Name', 'Department', 'Position', 'Workforce Type', 'Project',
            'Phone', 'Email', 'Address', 'Emergency Contact Name', 'Emergency Contact Phone',
            'Join Date', 'Employment Status',
        ];
    }

    public function array(): array
    {
        /*
         * v2.108.1: NIK and Workforce Type are new columns. Both are
         * OPTIONAL -- a file saved from the previous template, with
         * neither column, imports exactly as before (no national ID, and
         * Workforce Type takes its `pkwtt` default). NIK here is a
         * correctly shaped example, not a real number.
         *
         * Workforce Type accepts the stored keys (pkwtt, pkwt, daily,
         * intern, pkl, contractor, outsource) and the words people
         * actually write -- "tetap", "kontrak", "harian", "magang" --
         * see EmployeesImport::EMPLOYMENT_TYPE_ALIASES.
         */
        // One example row so the expected format (especially the date)
        // is obvious, not just column names with no guidance. Project
        // is optional -- if it matches an existing project by name, the
        // employee is added to that project's manpower; if left blank
        // or unmatched, the employee is simply not assigned to a
        // project yet (not a completion-blocking field). Photo is
        // deliberately not a column here -- Excel rows can't carry an
        // uploadable image file, so photos are still added per-employee
        // after import, same as manual employee creation already works.
        return [
            ['EMP-0001', '3201010101900001', 'John Doe', 'HSE', 'HSE Officer', 'pkwtt', '',
                '081234567890', 'john.doe@example.com', '', '', '', '2026-01-15', 'active'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
