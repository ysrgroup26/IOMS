<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Employee;
use App\Models\JobSafetyAnalysis;
use App\Models\PermitToWork;
use App\Models\PpeType;
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
            // v2.53.0. FOUR DISTINCT CONCEPTS, and a permit needs the
            // last three of them:
            //   project_id       optional Project Master reference
            //   work_reference   the operational identity of THIS work
            //   location         where it physically happens
            //   work_description what is actually being done
            // `work_reference` means HSE is never blocked on Management
            // creating a project row first, and the printed permit never
            // reads "No Project" for work that plainly has a name.
            'work_reference' => ['nullable', 'string', 'max:255'],
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

            /*
             * v2.73.0 -- PPE, FROM THE EXISTING MASTER.
             *
             * `ppe_types` is installation-wide reference data with no
             * `company_id` (see PpeType's own doc comment on why that is
             * deliberate), so unlike every other id list on this request
             * there is no tenant to scope it to -- the allow-list is the
             * active types themselves. Still Rule::in rather than
             * `exists`, so an inactive or deleted type cannot be posted.
             *
             * `confirmed_ppe_ids` is NOT accepted here. What PPE was
             * actually verified present is recorded at authorisation, by
             * the person doing the verifying -- accepting it on the
             * create request would let the requester assert their own
             * compliance, which is the one thing the required/confirmed
             * split exists to prevent.
             */
            'required_ppe_ids' => ['nullable', 'array', 'max:40'],
            'required_ppe_ids.*' => [Rule::in(PpeType::where('is_active', true)->pluck('id'))],
            'additional_ppe' => ['nullable', 'array', 'max:20'],
            'additional_ppe.*' => ['string', 'max:120'],
            'hazards' => ['nullable', 'array', 'max:30'],
            'hazards.*' => ['string', 'max:160'],
        ];
    }
}
