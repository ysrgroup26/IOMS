<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\CompetencyType;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompetencyTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        // IDOR guard: plain `exists:companies,id` / `exists:positions,id`
        // rules run a raw DB query that does NOT go through Company's own
        // TenantScope (Laravel's `exists` rule never touches Eloquent
        // query scopes at all) -- a raw `exists` check alone would let a
        // tenant submit ANOTHER tenant's company_id/position_id and have
        // it validate as "exists". `Company::query()`/scoping positions
        // through it are what actually restrict these to the current
        // tenant's own records, same principle as
        // DashboardStatsService::resolveCompanyIds().
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantPositionIds = Position::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('competency_types', 'name')->where(fn ($q) => $q->where('company_id', $this->input('company_id'))),
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
