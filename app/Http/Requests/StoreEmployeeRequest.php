<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        // v1.10.5 security fix: these three were plain `exists:` --
        // Laravel's `exists` rule never goes through Company's own
        // TenantScope, so a raw `exists:companies,id` (etc.) accepts ANY
        // tenant's id, not just the current tenant's own. Same IDOR guard
        // already used throughout every Milestone 4 controller (see
        // StoreCompetencyTypeRequest's own doc comment for the fuller
        // explanation) -- now applied to Employee, the app's original,
        // pre-Milestone-4 resource.
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantDepartmentIds = Department::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantPositionIds = Position::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'employee_id' => ['required', 'string', 'max:50', 'unique:employees,employee_id'],
            'nik' => ['nullable', 'string', 'max:20'],
            'full_name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'department_id' => ['required', Rule::in($tenantDepartmentIds)],
            'position_id' => ['nullable', Rule::in($tenantPositionIds)],
            'status' => ['required', 'in:active,inactive,resigned'],
            // Milestone 4, Workstream A (Workforce Classification).
            'employment_type' => ['required', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'join_date' => ['nullable', 'date'],
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photo' => ['nullable', 'image', 'max:2048'],
            // Intern/PKL detail (App\Models\EmployeeInternship) -- only
            // meaningfully required when employment_type is intern/pkl.
            // `required_if` on the nested key still validates correctly
            // against a `internship.*` array input from the frontend.
            'internship' => ['nullable', 'array'],
            'internship.institution' => ['required_if:employment_type,intern,pkl', 'nullable', 'string', 'max:255'],
            'internship.program' => ['nullable', 'string', 'max:255'],
            'internship.mentor_name' => ['nullable', 'string', 'max:255'],
            'internship.agreement_number' => ['nullable', 'string', 'max:255'],
            'internship.start_date' => ['nullable', 'date'],
            'internship.end_date' => ['nullable', 'date', 'after_or_equal:internship.start_date'],
            'internship.work_location' => ['nullable', 'string', 'max:255'],
            'internship.induction_completed' => ['nullable', 'boolean'],
            'internship.insurance_coverage' => ['nullable', 'string', 'max:255'],
            'internship.evaluation' => ['nullable', 'string'],
            'internship.completion_status' => ['nullable', Rule::in(['ongoing', 'completed', 'terminated'])],
        ];
    }
}
