<?php

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * v2.40.0 -- fail-closed tenant isolation for `company_settings`.
 *
 * WHY A SCOPE AND NOT JUST A FIXED ACCESSOR: settings are read through
 * TWO paths in this codebase. Most callers use CompanySetting::get(),
 * but five sites deliberately bypass it with raw Eloquent
 * (`CompanySetting::where('key', ...)->value('value')`) -- see
 * HandleInertiaRequests' documented reason for not caching
 * `enabled_modules`. Fixing only the accessor would have left those raw
 * paths reading across tenants. A global scope covers every path,
 * including any added later by someone who has not read this comment.
 *
 * "Your own bucket only", never a union: a resolved tenant sees only its
 * own rows; an unresolved request (guest on the login/landing page, a
 * Platform Super Admin, an artisan command) sees only the platform tier
 * (tenant_id IS NULL). It deliberately does NOT fall back from tenant to
 * platform, because `->value()` on a two-row match would pick a row
 * arbitrarily. That two-tier resolution is done explicitly and in a
 * defined order inside CompanySetting::get() instead.
 *
 * Unlike TenantScope this does not need a -1 sentinel: "no tenant" is a
 * real, meaningful bucket here (the platform defaults), not an error.
 */
class CompanySettingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        $column = $model->getTable().'.tenant_id';

        $tenantId === null
            ? $builder->whereNull($column)
            : $builder->where($column, $tenantId);
    }
}
