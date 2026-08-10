<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\PurchaseRequisition;
use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageProcurement();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantPrIds = PurchaseRequisition::whereIn('company_id', $tenantCompanyIds)
            ->where('status', PurchaseRequisition::STATUS_APPROVED)
            ->pluck('id');
        $tenantVendorIds = Vendor::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'purchase_requisition_id' => ['required', Rule::in($tenantPrIds)],
            'issue_date' => ['required', 'date'],
            'quotation_deadline' => ['required', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'max:10'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'delivery_requirement' => ['nullable', 'string', 'max:1000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'vendor_ids' => ['required', 'array', 'min:1'],
            'vendor_ids.*' => ['integer', Rule::in($tenantVendorIds)],
        ];
    }
}
