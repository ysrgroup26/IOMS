<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Employee;
use App\Models\JobSafetyAnalysis;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\RiskAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePermitToWorkRequest extends FormRequest
{
    /**
     * v2.17.0 (PTW Field Workflow Foundation + Controlled PTW Access):
     * was `canManageHse()` only -- see `User::canCreatePtw()`'s own doc
     * comment. This is the actual server-side enforcement Part 4/21
     * requires: even a direct POST to this route with a forged/no
     * frontend cannot create a PTW without the authenticated user
     * satisfying this check.
     */
    public function authorize(): bool
    {
        return $this->user()->canCreatePtw();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantRaIds = RiskAssessment::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantJsaIds = JobSafetyAnalysis::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        // v2.17.0 (Part 8/9/11/21): tenant-scoped, active-only -- the
        // same allow-list backs both the optional PIC and the Workforce
        // list below, so neither can ever reference another tenant's
        // Employee record (IDOR-safe: Rule::in() rejects anything not in
        // this exact set, regardless of what ID the client sends).
        $tenantActiveEmployeeIds = Employee::whereIn('company_id', $tenantCompanyIds)->active()->pluck('id');

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
            // v2.17.0 (Part 8): PIC / Supervisor Lapangan -- optional.
            'pic_employee_id' => ['nullable', Rule::in($tenantActiveEmployeeIds)],
            // v2.17.0 (Part 9): overall planned Workforce -- optional,
            // any length, each entry must be one of this tenant's own
            // active employees.
            'personnel_ids' => ['nullable', 'array'],
            'personnel_ids.*' => [Rule::in($tenantActiveEmployeeIds)],
        ];
    }
}
