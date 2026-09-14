<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Department;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageProcurement();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantDepartmentIds = Department::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        /*
         * v2.69.0 -- TENANT-SCOPED *AND* STATE-SCOPED.
         *
         * This previously allowed any Material Request in the tenant,
         * including a draft, a rejected one or a cancelled one, even
         * though the form only ever offered approved/processing. A form
         * that narrows a list is not a rule; the request body is what
         * arrives, and it can say anything.
         *
         * Only demand that is genuinely waiting to be bought can be
         * attached to a purchase: approved, deliberately held for
         * consolidation, or already being processed (so that editing an
         * existing PR, whose requests this controller has already moved
         * to `processing`, does not fail its own validation).
         *
         * The tenant floor is unchanged and still applies first.
         */
        $sourceableMaterialRequestIds = MaterialRequest::whereIn('company_id', $tenantCompanyIds)
            ->whereIn('status', [
                MaterialRequest::STATUS_APPROVED,
                MaterialRequest::STATUS_CONSOLIDATING,
                MaterialRequest::STATUS_PROCESSING,
            ])
            ->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'department_id' => ['nullable', Rule::in($tenantDepartmentIds)],
            // Several requests, because one purchase may consolidate the
            // demand of many. See PurchaseRequisition::materialRequests().
            'material_request_ids' => ['nullable', 'array'],
            'material_request_ids.*' => [Rule::in($sourceableMaterialRequestIds)],
            'cost_center' => ['nullable', 'string', 'max:100'],
            'request_date' => ['required', 'date'],
            'priority' => ['required', Rule::in(PurchaseRequisition::PRIORITIES)],
            'required_date' => ['nullable', 'date'],
            'justification' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.specification' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
