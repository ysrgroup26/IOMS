<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageDailyReports();
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'report_date' => ['required', 'date'],
            'department_name' => ['required', 'string', 'max:255'],
            'report_type' => ['required', 'in:normal,overtime'],
            'findings' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'activities' => ['required', 'array', 'min:1'],
            'activities.*' => ['required', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['image', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'activities.required' => 'Please add at least one activity.',
            'department_name.required' => 'Please enter the department for this report.',
        ];
    }
}
