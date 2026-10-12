<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.110.0 -- People / HRD: who is on the books.
 *
 * The Employee Register already had an Excel export and a PDF, written
 * months apart from two separate queries. This definition does not
 * replace either -- both routes still answer, and both are still the
 * fastest path from the Employees screen -- but it puts the SAME list in
 * the document library beside the other registers, and adds the DOCX a
 * customer needs when the register goes into a tender or a letter.
 *
 * TENANT SCOPING IS THE SAME FAIL-CLOSED RULE the Excel export carries:
 * the base filter is the current tenant's own company ids, never
 * "everyone". `Company::query()` is already tenant-scoped by
 * CompanyScope, so the pluck is the boundary.
 */
class EmployeeRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'employee-register';
    }

    public function title(): string
    {
        return 'Employee Register';
    }

    public function description(): string
    {
        return 'Daftar karyawan aktif beserta unit, departemen dan jabatannya.';
    }

    public function workspace(): string
    {
        return 'hr';
    }

    public function formats(): array
    {
        return DocumentRenderer::FORMATS;
    }

    public function columns(): array
    {
        return [
            // `text`, explicitly: an employee number or a 16-digit NIK
            // written as a number loses its leading zeros and, past 15
            // digits, its last digits.
            ['key' => 'employee_id', 'label' => 'Employee ID', 'type' => 'text', 'width' => 16],
            ['key' => 'nik', 'label' => 'NIK (KTP)', 'type' => 'text', 'width' => 20],
            ['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'width' => 28],
            ['key' => 'company', 'label' => 'Operating Unit', 'type' => 'text', 'width' => 22],
            ['key' => 'department', 'label' => 'Department', 'type' => 'text', 'width' => 20],
            ['key' => 'position', 'label' => 'Position', 'type' => 'text', 'width' => 22],
            ['key' => 'employment_type', 'label' => 'Employment Type', 'type' => 'text', 'width' => 16],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 12],
            ['key' => 'join_date', 'label' => 'Join Date', 'type' => 'date', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = Employee::query()
            ->whereIn('employees.company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'department:id,name', 'position:id,name')
            ->search($filters['search'] ?? null)
            ->inCompany(isset($filters['company_id']) ? (int) $filters['company_id'] : null)
            ->inDepartment(isset($filters['department_id']) ? (int) $filters['department_id'] : null)
            ->orderedForDisplay();

        foreach ($query->cursor() as $employee) {
            yield [
                'employee_id' => $employee->employee_id,
                'nik' => $employee->nik,
                'full_name' => $employee->full_name,
                'company' => $employee->company?->name,
                'department' => $employee->department?->name,
                'position' => $employee->position?->name,
                // The stored token, made readable. `permanent` and
                // `active` are enum VALUES, not labels, and a document a
                // client reads should not show the database's spelling.
                'employment_type' => DocumentLabel::humanize($employee->employment_type),
                'status' => DocumentLabel::humanize($employee->status),
                'join_date' => $employee->join_date,
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Unit' => isset($filters['company_id']) ? Company::find($filters['company_id'])?->name : null,
            'Departemen' => isset($filters['department_id']) ? Department::find($filters['department_id'])?->name : null,
            'Pencarian' => $filters['search'] ?? null,
        ];
    }

    public function authorize(User $user): bool
    {
        // The Employees list itself has no role gate beyond
        // authentication; a document of a list someone may already read
        // on screen must not be harder to reach than the screen.
        return true;
    }
}
