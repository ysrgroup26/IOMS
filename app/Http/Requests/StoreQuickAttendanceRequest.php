<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class StoreQuickAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'kpi_category_id' => ['required', 'exists:kpi_categories,id'],
            'record_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => [new InCurrentTenant('employees')],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_ids.required' => 'Please check at least one employee.',
            'employee_ids.min' => 'Please check at least one employee.',
        ];
    }
}
