<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class StorePpeReplacementRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeDistribution();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:draft,submitted'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            // CONFIRMED cross-tenant WRITE before v2.40.0: a raw exists: accepted
            // ANY tenant's EmployeePpe row, and storeReplacementRequest() then
            // flipped it to `replacement_requested` and copied its owner's
            // company_id onto the new request. employee_ppe carries no
            // company_id of its own -- it is owned via employees.
            'items.*.employee_ppe_id' => ['required', new InCurrentTenant('employee_ppe', 'company_id', ['employee_id', 'employees'])],
            'items.*.project_id' => ['nullable', new InCurrentTenant('projects')],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
            'items.*.documentation_photo' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
