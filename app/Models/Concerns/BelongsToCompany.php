<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\CompanyOwnedScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * v2.62.0 -- "this record belongs to one Operating Unit", stated once.
 *
 * Applying this trait is the whole opt-in: the model gains
 * `CompanyOwnedScope` (see that class for the incident it exists to
 * prevent) and the `company()` relation, and from then on no query on it
 * can return another tenant's rows -- including `find()`, `all()`,
 * pagination, search, exports and reports, because none of those can
 * escape a global scope.
 *
 * ADDING A NEW COMPANY-OWNED TABLE. Put `company_id` on it, make it NOT
 * NULL, add this trait, and stop thinking about isolation. A test
 * (TenantIsolationCoverageTest) asserts that every model carrying a
 * `company_id` has this trait, so the next table cannot be added without
 * it by accident -- which is the failure this whole change is fixing.
 *
 * WHEN A NULL OWNER IS LEGITIMATE. Override
 * `companyScopeAllowsGlobalRows()` to return true. Only three tables do:
 * KPI categories, numbering formats and numbering sequences, where a null
 * `company_id` means "the built-in default every customer starts from"
 * and a non-null one means "this customer's override". Do not reach for
 * it to make an ordinary table's null rows visible; a row nobody owns
 * must not become a row everybody can see.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyOwnedScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Does a null `company_id` on this table mean "shared default"
     * (visible to everyone) rather than "unowned" (visible to no one)?
     */
    public function companyScopeAllowsGlobalRows(): bool
    {
        return false;
    }
}
