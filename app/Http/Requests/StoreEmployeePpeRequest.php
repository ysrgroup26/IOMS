<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeePpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeDistribution();
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,id'],
            'ppe_type_id' => ['required', 'exists:ppe_types,id'],
            'issued_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
