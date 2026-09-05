<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class StoreEmployeePpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeDistribution();
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', new InCurrentTenant('employees')],
            'ppe_type_id' => ['required', 'exists:ppe_types,id'],
            'issued_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
