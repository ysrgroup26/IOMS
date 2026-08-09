<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\CompetencyType;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompetencyTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $competencyType = $this->route('competencyType');

        // Same IDOR guard as StoreCompetencyTypeRequest -- see its own
        // doc comment.
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantPositionIds = Position::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('competency_types', 'name')
                    ->where(fn ($q) => $q->where('company_id', $this->input('company_id')))
                    ->ignore($competencyType->id),
            ],
            'type' => ['required', Rule::in(CompetencyType::TYPES)],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'required_position_ids' => ['array'],
            'required_position_ids.*' => ['integer', Rule::in($tenantPositionIds)],
        ];
    }
}
