<?php

namespace App\Exports;

use App\Models\CompanySetting;
use App\Models\Employee;
use App\Services\FieldMappingService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithProperties;

/**
 * Milestone 3 (Export Mapping, Task #67): `$fields` -- an ordered
 * subset of ['employee_id','full_name','company','department',
 * 'position','status','join_date','phone'] plus custom column labels,
 * resolved by `App\Services\FieldMappingService::exportFields()` --
 * lets a tenant reorder, rename, or omit columns without code changes.
 * Null (the default) falls back to the original fixed 8-column layout,
 * byte-for-byte, for every caller that hasn't been updated to pass one.
 */
class EmployeeExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithProperties
{
    private const ALL_FIELDS = ['employee_id', 'full_name', 'company', 'department', 'position', 'status', 'join_date', 'phone'];

    /**
     * @param  array<string,string>|null  $fields  field_key => column label, in export order
     * @param  \Illuminate\Support\Collection<int,int>|array<int,int>|null  $tenantCompanyIds  the
     *         current tenant's own company ids (`Company::query()->pluck('id')`) -- v1.10.5
     *         security fix, see the class doc comment.
     */
    public function __construct(
        private readonly ?int $departmentId = null,
        private readonly ?string $search = null,
        private readonly ?int $companyId = null,
        private readonly ?array $fields = null,
        private readonly array|\Illuminate\Support\Collection|null $tenantCompanyIds = null,
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
        // v1.10.5 security fix: this previously had no base tenant filter
        // at all -- exporting with no `?company_id=` picked (the default
        // "export everyone" action) downloaded every tenant's employee
        // roster into one Excel file, not just the current tenant's. A
        // null `$tenantCompanyIds` (only possible if a caller forgets to
        // pass it) intentionally returns zero rows rather than silently
        // falling back to "everyone" -- fail closed, not open.
        return Employee::query()
            ->whereIn('employees.company_id', $this->tenantCompanyIds ?? [])
            ->with('company', 'department', 'position')
            ->search($this->search)
            ->inCompany($this->companyId)
            ->inDepartment($this->departmentId)
            ->orderedForDisplay()
            ->get();
    }

    public function headings(): array
    {
        return $this->fields ? array_values($this->fields) : ['Employee ID', 'Full Name', 'Company', 'Department', 'Position', 'Status', 'Join Date', 'Phone'];
    }

    public function map($employee): array
    {
        $values = [
            'employee_id' => $employee->employee_id,
            'full_name' => $employee->full_name,
            'company' => $employee->company->name ?? '-',
            'department' => $employee->department->name ?? '-',
            'position' => $employee->position->name ?? '-',
            'status' => ucfirst($employee->status),
            'join_date' => $employee->join_date?->format('d M Y') ?? '-',
            'phone' => $employee->phone ?? '-',
        ];

        $keys = $this->fields ? array_keys($this->fields) : self::ALL_FIELDS;

        return array_map(fn ($key) => $values[$key] ?? '-', $keys);
    }
}
