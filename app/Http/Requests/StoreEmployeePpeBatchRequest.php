<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;
use App\Models\Employee;
use Illuminate\Validation\Rule;

class StoreEmployeePpeBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeDistribution();
    }

    /**
     * One employee, many PPE items, one submission -- replaces the old
     * one-item-at-a-time StoreEmployeePpeRequest flow (v1.3.1). Each item
     * still becomes its own employee_ppe row (expiry auto-computed per
     * row from its ppe_type's interval, unchanged from before); only the
     * form/endpoint shape changed, not the underlying data model.
     */
    public function rules(): array
    {
        $employee = Employee::find($this->input('employee_id'));
        $ppeTypeIds = $employee
            ? \App\Models\PpeType::effectiveForCompany($employee->company_id)->active()->pluck('id')
            : collect();

        return [
            'employee_id' => ['required', new InCurrentTenant('employees')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ppe_type_id' => ['required', Rule::in($ppeTypeIds)],
            'items.*.issued_date' => ['required', 'date'],
            // Optional manual override (v1.6.6) -- when omitted, the
            // EmployeePpe model's boot hook still auto-computes this from
            // the PPE type's replacement_interval_months, unchanged from
            // before. This just lets someone override it when needed.
            'items.*.expiry_date' => ['nullable', 'date', 'after_or_equal:items.*.issued_date'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Please add at least one PPE item.',
        ];
    }
}
