<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $employee = $this->route('employee');

        // v1.10.5 security fix -- same IDOR guard as StoreEmployeeRequest,
        // see its own doc comment. (The route-bound `$employee` itself is
        // separately checked for tenant ownership in
        // EmployeeController::update() via assertInCurrentTenant() -- this
        // only guards the SUBMITTED company/department/position ids.)
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantDepartmentIds = Department::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantPositionIds = Position::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'employee_id' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_id')->ignore($employee->id)],
            'nik' => ['nullable', 'string', 'max:20'],
            'full_name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'department_id' => ['required', Rule::in($tenantDepartmentIds)],
            'position_id' => ['nullable', Rule::in($tenantPositionIds)],
            'status' => ['required', 'in:active,inactive,resigned'],
            'employment_type' => ['required', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'join_date' => ['nullable', 'date'],
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photo' => ['nullable', 'image', 'max:2048'],
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
