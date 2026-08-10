<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageHse();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'assessment_date' => ['required', 'date'],
            'items' => ['nullable', 'array'],
            'items.*.activity' => ['nullable', 'string', 'max:500'],
            'items.*.hazard' => ['nullable', 'string', 'max:500'],
            'items.*.existing_control' => ['nullable', 'string', 'max:500'],
            'items.*.likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'items.*.severity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'items.*.additional_control' => ['nullable', 'string', 'max:500'],
            'items.*.residual_likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'items.*.residual_severity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'items.*.pic' => ['nullable', 'string', 'max:255'],
            'items.*.target_date' => ['nullable', 'date'],
        ];
    }
}
