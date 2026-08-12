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
            // v1.10.9 (HSE Domain Hardening, Part H/I): JSA's risk matrix
            // fields -- previously missing here entirely, which meant
            // `$request->validated()` (Laravel only returns keys it was
            // told to validate, for wildcard array rules) silently
            // stripped them out of every save even after the frontend
            // started sending them. Mirrors RiskAssessment's own
            // likelihood/severity (1-5, same 5x5 matrix -- see
            // resources/js/lib/riskMatrix.js) and residual_* rules.
            'steps.*.consequence' => ['nullable', 'string', 'max:500'],
            'steps.*.control_measure' => ['nullable', 'string', 'max:500'],
            'steps.*.likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'steps.*.severity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'steps.*.additional_controls' => ['nullable', 'string', 'max:500'],
            'steps.*.residual_likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'steps.*.residual_severity' => ['nullable', 'integer', 'min:1', 'max:5'],
            'steps.*.pic' => ['nullable', 'string', 'max:255'],
        ];
    }
}
