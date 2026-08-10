<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Department;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\Vendor;
use App\Models\VendorQuotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageProcurement();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantVendorIds = Vendor::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantPrIds = PurchaseRequisition::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantRfqIds = Rfq::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantQuotationIds = VendorQuotation::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantDepartmentIds = Department::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'vendor_id' => ['required', Rule::in($tenantVendorIds)],
            'purchase_requisition_id' => ['nullable', Rule::in($tenantPrIds)],
            'rfq_id' => ['nullable', Rule::in($tenantRfqIds)],
            'vendor_quotation_id' => ['nullable', Rule::in($tenantQuotationIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'department_id' => ['nullable', Rule::in($tenantDepartmentIds)],
            'cost_center' => ['nullable', 'string', 'max:100'],
            'po_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:po_date'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms_conditions' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.specification' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
