<?php

namespace App\Exports;

use App\Exports\Concerns\IomsSheetFormatting;
use App\Models\Employee;
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
 * v2.121.0 -- THE WIDE TEMPLATE: one row per person, one dated column per
 * kind of PPE.
 *
 * The columns are NOT a fixed list. They are this company's own effective
 * catalogue (ADR 050/058), so a customer who renamed a type through an
 * override, or who added "Work Uniform" themselves, downloads a template
 * whose headings already match what the importer will accept. A template
 * that names columns the import then refuses is the worst kind of
 * template, because it teaches the wrong format in the place people copy
 * from.
 *
 * Column ORDER follows the completeness rule rather than the alphabet:
 * work clothing, then Safety Shoes, then Safety Helmet, then everything
 * additional. The three that decide whether somebody may go to work sit
 * together on the left where they are read first.
 *
 * It is also PRE-FILLED with the company's active employees -- their
 * number and name, with every PPE cell blank. Asking an HSE officer to
 * retype three hundred employee numbers into a blank sheet is how a file
 * comes back with numbers that match nobody.
 */
class EmployeePpeImportTemplateExport implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithProperties, WithStyles
{
    use IomsSheetFormatting;

    /** @var list<string> */
    private array $ppeColumns;

    public function __construct(private readonly ?int $companyId = null)
    {
        $this->ppeColumns = $this->resolvePpeColumns();
    }

    public function properties(): array
    {
        return $this->iomsProperties(
            'Employee PPE Import Template',
            'One row per employee. Put the date each item was handed over; leave a cell blank when there is no record.'
        );
    }

    public function headings(): array
    {
        return ['Employee ID', 'Employee Name', ...$this->ppeColumns];
    }

    /**
     * The company's catalogue, required groups first.
     *
     * @return list<string>
     */
    private function resolvePpeColumns(): array
    {
        if (! $this->companyId) {
            // No company context (a bare download): the shipped catalogue's
            // own shape, so the file is still usable and still honest.
            return ['Coverall', 'Safety Shoes', 'Safety Helmet', 'Safety Glasses', 'Harness', 'Headlamp'];
        }

        $types = PpeType::withoutGlobalScopes()
            ->effectiveForCompany($this->companyId)
            ->where('is_active', true)
            ->with('sourceDefault:id,compliance_role')
            ->orderBy('name')
            ->get(['id', 'company_id', 'source_default_id', 'name', 'compliance_role']);

        $order = array_flip(PpeType::COMPLIANCE_ROLES);

        return $types
            ->sortBy(fn (PpeType $type) => $order[$type->effectiveComplianceRole()] ?? count($order))
            ->values()
            ->pluck('name')
            ->all();
    }

    public function array(): array
    {
        $blanks = array_fill(0, count($this->ppeColumns), '');

        if (! $this->companyId) {
            return [['EMP-0001', 'Nama Karyawan', ...$blanks]];
        }

        /*
         * Every active employee, so the file only needs dates typed into
         * it. Capped, because a template is a working file and not an
         * export -- a customer with more people than this has a roster to
         * paste from, and the Document Library publishes the full
         * register.
         */
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('status', 'active')
            ->orderBy('employee_id')
            ->limit(2000)
            ->get(['employee_id', 'full_name']);

        if ($employees->isEmpty()) {
            return [['EMP-0001', 'Nama Karyawan', ...$blanks]];
        }

        return $employees
            ->map(fn (Employee $e) => [(string) $e->employee_id, (string) $e->full_name, ...$blanks])
            ->all();
    }

    /** The same frozen, filterable header every IOMS export opens with. */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->applyIomsSheetFormatting($event->sheet->getDelegate()),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
