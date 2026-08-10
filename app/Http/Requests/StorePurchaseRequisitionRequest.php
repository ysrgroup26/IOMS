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
        $tenantMaterialRequestIds = MaterialRequest::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'department_id' => ['nullable', Rule::in($tenantDepartmentIds)],
            'source_material_request_id' => ['nullable', Rule::in($tenantMaterialRequestIds)],
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
