<?php

namespace App\Exports;

use App\Models\CompanySetting;
use App\Models\Employee;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithProperties;

class EmployeeExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithProperties
{
    public function __construct(
        private readonly ?int $departmentId = null,
        private readonly ?string $search = null,
        private readonly ?int $companyId = null,
    ) {}

    /**
     * Live company name from Branding Settings, not the static config
     * file -- see KpiReportExport::properties() for why config() values
     * alone aren't sufficient once config:cache is used in production.
     */
    public function properties(): array
    {
        $companyName = CompanySetting::get('company_name', 'Integrated Operations Management System');

        return [
            'creator' => $companyName,
            'lastModifiedBy' => $companyName,
            'title' => 'Employee List',
            'description' => "Exported from {$companyName}",
            'company' => $companyName,
        ];
    }

    public function collection()
    {
        return Employee::query()
            ->with('company', 'department', 'position')
            ->search($this->search)
            ->inCompany($this->companyId)
            ->inDepartment($this->departmentId)
            ->orderedForDisplay()
            ->get();
    }

    public function headings(): array
    {
        return ['Employee ID', 'Full Name', 'Company', 'Department', 'Position', 'Status', 'Join Date', 'Phone'];
    }

    public function map($employee): array
    {
        return [
            $employee->employee_id,
            $employee->full_name,
            $employee->company->name ?? '-',
            $employee->department->name ?? '-',
            $employee->position->name ?? '-',
            ucfirst($employee->status),
            $employee->join_date?->format('d M Y') ?? '-',
            $employee->phone ?? '-',
        ];
    }
}
