<?php

namespace App\Exports;

use App\Exports\Concerns\IomsSheetFormatting;
use App\Models\PpeType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * v2.120.0 -- the file a customer fills in to issue PPE in bulk.
 *
 * Column order and names must stay in sync with `EmployeePpeImport`'s
 * field keys and with `config/mapping_fields.php`'s `employee_ppe.import`
 * entry -- all three read the same snake_cased heading keys, so a change
 * to one is a change to all three. The same contract
 * `EmployeeImportTemplateExport` documents for its own columns.
 *
 * THE EXAMPLE ROWS NAME REAL PPE TYPES FROM THIS COMPANY'S CATALOGUE,
 * not invented ones. A template whose sample values are refused by the
 * import it belongs to teaches the customer the wrong format in the one
 * place they are most likely to copy from, and "PPE Type must match PPE
 * Master exactly" is precisely the rule this import enforces.
 */
class EmployeePpeImportTemplateExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithProperties, WithStyles
{
    use IomsSheetFormatting;

    public function __construct(private readonly ?int $companyId = null) {}

    public function properties(): array
    {
        return $this->iomsProperties(
            'Employee PPE Import Template',
            'Fill this template and upload it in IOMS > PPE Management > Employee PPE > Import PPE.'
        );
    }

    /** The same frozen, filterable header every IOMS export opens with. */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->applyIomsSheetFormatting($event->sheet->getDelegate()),
        ];
    }

    public function headings(): array
    {
        return ['Employee ID', 'PPE Type', 'Issued Date', 'Expiry Date', 'Remarks'];
    }

    public function array(): array
    {
        $issued = now()->subMonths(2)->format('d/m/Y');

        /*
         * Up to three types the customer can actually select today, read
         * through the same `effectiveForCompany()` catalogue the import
         * matches against -- so an overridden built-in shows the
         * customer's own name for it, not the shipped one.
         *
         * Expiry is left blank on purpose in every example: it is derived
         * from the type's replacement interval unless the customer
         * deliberately overrides it, and a template that fills it in
         * invites them to hand-maintain a date the product maintains for
         * them.
         */
        $names = $this->companyId
            ? PpeType::withoutGlobalScopes()
                ->effectiveForCompany($this->companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(3)
                ->pluck('name')
                ->all()
            : [];

        if ($names === []) {
            $names = ['Safety Helmet', 'Safety Shoes', 'Coverall'];
        }

        $rows = [];
        foreach ($names as $index => $name) {
            $rows[] = ['EMP-000'.($index + 1), $name, $issued, '', ''];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
