<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobSafetyAnalysisRequest extends FormRequest
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
            'job_title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'jsa_date' => ['required', 'date'],
            'required_ppe' => ['nullable', 'array'],
            'required_ppe.*' => ['string', 'max:100'],
            'steps' => ['nullable', 'array'],
            'steps.*.task_step' => ['nullable', 'string', 'max:500'],
            'steps.*.potential_hazard' => ['nullable', 'string', 'max:500'],
            'steps.*.control_measure' => ['nullable', 'string', 'max:500'],
        ];
    }
}
