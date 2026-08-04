<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'full_name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'exists:companies,id'],
            'department_id' => ['required', 'exists:departments,id'],
            'position_id' => ['nullable', 'exists:positions,id'],
            'status' => ['required', 'in:active,inactive,resigned'],
            'join_date' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
