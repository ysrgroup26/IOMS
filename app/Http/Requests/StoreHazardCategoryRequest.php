<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHazardCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        // IDOR guard -- see StoreCompetencyTypeRequest's own doc comment.
        $tenantCompanyIds = Company::query()->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('hazard_categories', 'name')->where(fn ($q) => $q->where('company_id', $this->input('company_id'))),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
