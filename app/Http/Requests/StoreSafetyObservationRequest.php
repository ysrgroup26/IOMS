<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\HazardCategory;
use App\Models\Project;
use App\Models\SafetyObservation;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSafetyObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageSafetyObservations();
    }

    public function rules(): array
    {
        // IDOR guard throughout -- same principle as
        // StoreCompetencyTypeRequest's own doc comment: every foreign id
        // below is scoped through Company::query() (TenantScope-safe) or
        // the current tenant's own user list, never a raw `exists:` rule.
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantHazardCategoryIds = HazardCategory::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantId = app(CurrentTenant::class)->id();
        $tenantUserIds = User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'hazard_category_id' => ['nullable', Rule::in($tenantHazardCategoryIds)],
            'observed_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(SafetyObservation::TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'immediate_action' => ['nullable', 'string', 'max:2000'],
            'severity' => ['nullable', Rule::in(SafetyObservation::SEVERITIES)],
            'assigned_to' => ['nullable', Rule::in($tenantUserIds)],
            'due_date' => ['nullable', 'date'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }
}
