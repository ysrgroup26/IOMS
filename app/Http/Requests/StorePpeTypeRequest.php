<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePpeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeMaster();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer'],
            /*
             * v2.121.0 -- which completeness requirement this type
             * satisfies, or null for additional equipment. Validated
             * against the model's own list so a role cannot be invented
             * from a request body. See PpeComplianceService.
             */
            'compliance_role' => ['nullable', Rule::in(\App\Models\PpeType::ASSIGNABLE_COMPLIANCE_ROLES)],
            'replacement_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['boolean'],
        ];
    }
}
