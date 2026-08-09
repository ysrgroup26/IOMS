<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Master -> Tenant Management. `authorize()` checks
 * `User::isPlatformAdmin()` (tenant_id === null) rather than duplicating
 * the route's own `role:platform_admin` middleware check by string --
 * same belt-and-suspenders pattern every other Store*Request in this
 * codebase already follows, and the actual mechanism the rest of the
 * platform surface is built on (see docs/ADR/008-tenancy-foundation.md).
 *
 * Also carries the new tenant's Initial Administrator fields -- creating
 * a tenant with no way to log into it isn't a usable tenant, so this is
 * one form/request, not a separate "add first user" step. `Password::min(8)`
 * matches the existing project-wide minimum (UpdateAuthenticationRequest,
 * NewPasswordController) -- not a new policy invented for this feature.
 */
class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Slug uniqueness is already enforced at the DB level
            // (tenants.slug is a unique column, see
            // 2026_08_14_100042_create_tenants_table) -- this rule gives a
            // friendly validation error instead of letting that surface as
            // a raw SQL constraint violation.
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'unique:tenants,slug'],
            // Package assignment lives on Subscription, not Tenant itself
            // (Tenant::subscription(), Package::subscriptions()) -- this
            // form field drives creating that tenant's first Subscription
            // row in the controller, it is not a tenants table column.
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'status' => ['required', Rule::in([
                Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED, Tenant::STATUS_EXPIRED,
            ])],
            // Initial Administrator -- same shape/uniqueness rule as
            // SettingsController::storeUser() (a tenant's own Super Admin
            // creating another user), just prefixed `admin_*` to keep this
            // one request's two record types unambiguous.
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Slug may only contain lowercase letters, numbers, and hyphens (e.g. pt-galangan-aliran-jaya).',
        ];
    }
}
