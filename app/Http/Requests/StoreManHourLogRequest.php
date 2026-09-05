<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class StoreManHourLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageManHour();
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', new InCurrentTenant('employees')],
            'project_id' => ['nullable', new InCurrentTenant('projects')],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'regular_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'overtime_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
