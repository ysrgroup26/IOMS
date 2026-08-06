<?php

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Milestone 2 (Tenancy Foundation) -- the actual isolation enforcement.
 * Applied to `Company` only (see Company::booted()); every table beneath
 * it (departments, positions, employees, ...) is safe transitively
 * because they can only ever reference a Company this scope already
 * filtered to the current tenant.
 *
 * Fails CLOSED, not open: if no tenant has been resolved for this
 * request (e.g. a Platform Super Admin with no tenant context, or the
 * resolver simply hasn't run yet), this returns ZERO rows rather than
 * every tenant's rows. A platform-level user who needs to see across
 * tenants does so through the separate Platform Super Admin surface
 * (Milestone 2 UI, `withoutGlobalScope`/explicit tenant selection there),
 * never through this scope silently opening up.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentTenant::class);

        $builder->where($model->getTable().'.tenant_id', $current->id() ?? -1);
    }
}
