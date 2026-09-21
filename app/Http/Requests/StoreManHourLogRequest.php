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

    /**
     * v2.77.0 -- a day has 24 hours. Each field was capped at 24 on its
     * own, so 24 regular + 24 overtime (48h in one day) was accepted and
     * went straight into every total built on this table.
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                $total = (float) $this->input('regular_hours', 0) + (float) $this->input('overtime_hours', 0);

                if ($total > 24) {
                    $validator->errors()->add('overtime_hours', 'Regular and overtime hours together cannot exceed 24 hours in one day.');
                }

                if ($total <= 0 && ! $validator->errors()->has('regular_hours')) {
                    $validator->errors()->add('regular_hours', 'Enter the hours actually worked. A day with no hours is not a man-hour record.');
                }
            },
        ];
    }
}
