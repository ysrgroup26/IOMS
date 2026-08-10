<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\JobSafetyAnalysis;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\RiskAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermitToWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageHse();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantRaIds = RiskAssessment::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantJsaIds = JobSafetyAnalysis::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'risk_assessment_id' => ['nullable', Rule::in($tenantRaIds)],
            'jsa_id' => ['nullable', Rule::in($tenantJsaIds)],
            'permit_type' => ['required', Rule::in(PermitToWork::TYPES)],
            'work_description' => ['required', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            // Deliberately just a free-text label, never validated against
            // any employee's actual certificates -- see the migration's own
            // doc comment on why PTW must not auto-check qualifications.
            'required_qualification' => ['nullable', 'string', 'max:255'],
            'precautions' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
