<?php

namespace App\Models\Scopes;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * v2.62.0 -- THE SECOND HALF OF TENANT ISOLATION, AND THE INCIDENT THAT
 * PROVED IT WAS MISSING.
 *
 * Milestone 2 put a global scope on `Company` and reasoned that every
 * table beneath it was "safe transitively, because they can only ever
 * reference a Company this scope already filtered". That is true of the
 * DATA MODEL and false of the QUERIES. A row's `company_id` does point at
 * exactly one Company; nothing forced a query to go looking at Company at
 * all. Transitive safety only held for queries that voluntarily resolved
 * `Company::query()->pluck('id')` first, which was a convention, enforced
 * by review, in 66 models and several hundred call sites.
 *
 * IT FAILED IN PRODUCTION. A newly provisioned Starter tenant opened
 * Reports and saw another customer's departments and employee names. The
 * responsible line was a filter that is optional by design:
 *
 *     Department::where('is_active', true)
 *         ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
 *
 * With no company chosen -- the default -- `when()` is a no-op and the
 * query returns every department in the installation. A static sweep
 * found 72 sites of the same shape across controllers and services, and
 * `Employee::find($id)` reached another tenant's employee outright.
 *
 * WHAT THIS SCOPE DOES. It makes ownership structural rather than
 * conventional: a company-owned model can only ever return rows whose
 * `company_id` is one of the companies the CURRENT REQUEST may see. The
 * visible set is `Company::query()->select('id')` -- the same query the
 * convention used, so `TenantScope` (which tenant) and
 * `CompanyAuthorizationScope` (which operating units within it) both
 * still decide the answer. This class adds no policy of its own; it only
 * makes the existing policy impossible to forget.
 *
 * WHY A SUBQUERY, NOT `pluck()`. `whereIn('company_id', Company::select('id'))`
 * compiles to a single statement with a correlated subquery, so a scope
 * on 60 models does not become 60 extra round-trips per request, and the
 * database gets to use the index on `companies.tenant_id`.
 *
 * IT FAILS CLOSED, like TenantScope. With no resolved tenant the inner
 * query matches `tenant_id = -1`, the subquery is empty, and a
 * company-owned query returns nothing. Console paths that legitimately
 * operate on tenant data resolve their tenant explicitly first (see
 * DispatchScheduledReports); paths that legitimately operate ACROSS
 * tenants -- provisioning, the Platform Super Admin surface, migrations
 * -- ask for it by name with `withoutGlobalScope(CompanyOwnedScope::class)`,
 * which is greppable in a way that "forgot to add a where clause" is not.
 *
 * GLOBAL MASTER ROWS. Three tables use a null `company_id` to mean "the
 * built-in default, shared by everyone" rather than "unowned": KPI
 * categories, numbering formats and numbering sequences. A model opts
 * into that by returning true from `companyScopeAllowsGlobalRows()`, and
 * then null rows stay visible while owned rows are still restricted.
 * Every other table treats a null owner as unreachable, which is the
 * safe reading: a row nobody owns must not be a row everybody can see.
 */
class CompanyOwnedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->getTable().'.company_id';

        // Selected, not plucked: one statement, and the Company model's
        // own global scopes travel with it.
        $visible = Company::query()->select('id');

        if (method_exists($model, 'companyScopeAllowsGlobalRows') && $model->companyScopeAllowsGlobalRows()) {
            $builder->where(function (Builder $query) use ($column, $visible) {
                $query->whereNull($column)->orWhereIn($column, $visible);
            });

            return;
        }

        // A table where a null company_id is legitimate but the row is
        // still owned through some other column -- `tasks`, whose
        // company is optional but whose creator and assignee are
        // tenant-owned users. The model states that second path itself;
        // this class stays ignorant of it beyond calling the hook.
        if (method_exists($model, 'companyScopeNullOwnerQuery')) {
            $builder->where(function (Builder $query) use ($column, $visible, $model) {
                $query
                    ->whereIn($column, $visible)
                    ->orWhere(function (Builder $inner) use ($column, $model) {
                        $inner->whereNull($column);
                        $inner->where(fn (Builder $owned) => $model->companyScopeNullOwnerQuery($owned));
                    });
            });

            return;
        }

        $builder->whereIn($column, $visible);
    }
}
