<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * v2.63.0 -- OWNERSHIP FOR TABLES THAT HAVE NO `company_id` OF THEIR OWN.
 *
 * v2.62.0 made ownership structural for every table carrying a
 * `company_id`. The follow-up audit found the gap that left: a KPI record
 * has no `company_id` — it has an `employee_id` and a `department_id` —
 * so it received no scope, and `KpiRecord::latest()->limit(10)->get()` on
 * the KPI Input page returned the ten most recent records **in the
 * installation**. Same shape as the original incident, one join away.
 *
 * The ownership path always existed; nothing enforced it. This trait
 * enforces it by walking the relation the model already declares:
 *
 *     protected string $companyOwnerRelation = 'employee';
 *
 * and adding `whereHas('employee')`. The parent's own scope decides what
 * "exists" means, so a KPI record is visible exactly when its employee is
 * — no rule is restated, and a change to the parent's scoping propagates
 * for free. It is the same shape `EmployeePpe` already used by hand;
 * this is that generalised so the next table does not have to reinvent
 * it.
 *
 * A row whose parent is missing is unreachable. That is deliberate and
 * matches CompanyOwnedScope: a record whose owner cannot be resolved has
 * no owner, and a row nobody owns must not become a row everybody sees.
 *
 * WHEN NOT TO USE THIS. If the table has a `company_id`, use
 * `BelongsToCompany` — one predicate beats a subquery. If the table is
 * genuinely global reference data (`ppe_types`, `packages`, `modules`,
 * `workspaces`), it should have no scope at all, and
 * `TenantIsolationCoverageTest` records that decision explicitly rather
 * than leaving it to be inferred from an absence.
 */
trait BelongsToCompanyThrough
{
    public static function bootBelongsToCompanyThrough(): void
    {
        static::addGlobalScope('companyThrough', function (Builder $builder) {
            $relation = (new static)->companyOwnerRelation();

            $builder->whereHas($relation, function (Builder $parent) {
                // SOFT DELETION IS NOT AN OWNERSHIP QUESTION.
                //
                // Without this, scoping KPI records through their employee
                // would ALSO hide the records of every soft-deleted
                // employee -- silently changing historical HSE totals as a
                // side effect of a security fix. `withTrashed()` lifts only
                // SoftDeletingScope; the parent's CompanyOwnedScope stays
                // on, which is the part doing the isolation.
                if (method_exists($parent->getModel(), 'getDeletedAtColumn')) {
                    $parent->withTrashed();
                }
            });
        });
    }

    /** The relation whose model carries the ownership this record inherits. */
    public function companyOwnerRelation(): string
    {
        return property_exists($this, 'companyOwnerRelation')
            ? $this->companyOwnerRelation
            : 'company';
    }
}
