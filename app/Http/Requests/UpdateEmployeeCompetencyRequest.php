<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\CompetencyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeCompetencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        // Same IDOR guard as StoreEmployeeCompetencyRequest -- see its
        // own doc comment.
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantCompetencyTypeIds = CompetencyType::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'competency_type_id' => ['required', Rule::in($tenantCompetencyTypeIds)],
            'certificate_number' => ['nullable', 'string', 'max:255'],
            'issuer' => ['nullable', 'string', 'max:255'],
            'achieved_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:achieved_date'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }
}
