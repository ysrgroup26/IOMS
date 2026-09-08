<?php

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * v2.62.0 -- USERS ARE TENANT-OWNED, AND NOTHING SAID SO.
 *
 * `users` carries both `tenant_id` and `company_id`, and had no global
 * scope on either. Three controllers built assignee pickers with
 *
 *     User::where('is_active', true)->orderBy('name')->get(['id', 'name'])
 *
 * which returned every user of every customer -- names, and on other
 * surfaces email addresses -- to anybody who could open a task form.
 *
 * WHY THIS IS NOT `CompanyOwnedScope`. A user's `company_id` is nullable
 * and legitimately null: a tenant's own administrators are not attached
 * to one Operating Unit. Ownership for this table is `tenant_id`, which
 * matches how EntitlementService already counts seats
 * (`User::where('tenant_id', ...)`) and how ResolveTenant resolves the
 * request in the first place.
 *
 * IT MUST NOT RUN BEFORE A TENANT EXISTS, and that is the whole subtlety.
 * Authentication looks a user up BY EMAIL before any tenant is resolved;
 * a scope that filtered on an unresolved tenant would match `tenant_id =
 * -1`, find nobody, and lock every customer out of the product. So the
 * scope applies only once ResolveTenant has actually put a tenant in
 * place:
 *
 *   - login / password reset / guest  -> not resolved yet, scope is off
 *   - Platform Super Admin            -> resolved, but tenant is null;
 *                                        they work across tenants on the
 *                                        platform surface, scope is off
 *   - any tenant request              -> scope is on
 *
 * The first two are exactly the cases where no tenant-owned data is being
 * listed, and both sit behind their own authorization. Anything that
 * genuinely needs to read across tenants -- provisioning, the platform
 * surface -- asks for it by name with `withoutGlobalScope(UserTenantScope::class)`.
 */
class UserTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentTenant::class);

        if (! $current->isResolved() || $current->id() === null) {
            return;
        }

        $builder->where($model->getTable().'.tenant_id', $current->id());
    }
}
