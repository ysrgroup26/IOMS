<?php

namespace App\Http\Requests;

use App\Models\Employee;
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
        return [
            'employee_id' => ['required', 'string', 'max:50', 'unique:employees,employee_id'],
            'nik' => ['nullable', 'string', 'max:20'],
            'full_name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'exists:companies,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
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
