<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Master -> Tenant Management. Edits an existing tenant's name/slug/
 * status in place -- never recreates the row, so the tenant's `id` and
 * every relationship keyed on it (companies, users, modules, workspaces,
 * subscriptions) stay attached automatically. This is also how the
 * seeded "Default Tenant" gets renamed in production: it is not special,
 * it is just a Tenant row like any other (see PlatformController::
 * updateTenant()'s own doc comment).
 */
class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() ?? false;
    }

    public function rules(): array
    {
        // Same bound-model-via-request-property pattern as
        // UpdatePpeTypeRequest/UpdateKpiCategoryRequest -- route('tenant')
        // doesn't match the actual {tenant} route parameter used for
        // Rule::unique(...)->ignore(), so the bound model is reached via
        // $this->tenant instead (the controller already type-hints
        // `Tenant $tenant`).
        $tenant = $this->tenant;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'status' => ['required', Rule::in([
                Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED, Tenant::STATUS_EXPIRED,
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may only contain lowercase letters, numbers, and hyphens (e.g. pt-galangan-aliran-jaya).',
        ];
    }
}
