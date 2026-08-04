<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddManpowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageProjects();
    }

    public function rules(): array
    {
        return [
            // Employees are always CHOSEN from Employee Master, never typed --
            // this validates against real employee IDs only.
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['exists:employees,id'],
        ];
    }
}
