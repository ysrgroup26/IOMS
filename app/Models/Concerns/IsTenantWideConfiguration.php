<?php

namespace App\Models\Concerns;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * v2.99.0 -- A CONFIGURATION ROW THAT APPLIES TO THE WHOLE TENANT, AND THE
 * SILENT FEATURE LOSS THAT PROVED IT NEEDED SAYING.
 *
 * Three Settings features deliberately store a null `company_id` to mean
 * "this applies to every Operating Unit in this tenant": Approval Flow,
 * Documents and Import/Export Mapping. That is not an accident of a
 * nullable column -- `ApprovalFlowResolver` says it outright, "company-
 * specific flows win over tenant-wide defaults", and queries
 * `whereNull('company_id')` to find them.
 *
 * v2.62.0's `CompanyOwnedScope` then gave a null owner its safe default
 * reading, "visible to nobody". That is correct for an ordinary table and
 * wrong for these three, and the damage was total and silent:
 *
 *   - `storeApprovalFlow`, `storeDocumentTemplate` and
 *     `FieldMappingService` all write `company_id => null`, so every flow,
 *     template and mapping a customer saved became unreachable the instant
 *     it was created. The success message appeared; the list stayed empty.
 *   - Route model binding for `{approvalFlow}` runs through the same
 *     scope, so the delete and save-steps endpoints answered 404 for a row
 *     that plainly existed.
 *   - Worst of all, `ApprovalFlowResolver`'s own `whereNull('company_id')`
 *     branch became dead code. `resolve()` returned null for every
 *     tenant-wide flow, so every module quietly fell back to legacy
 *     single-step approval while Settings presented the feature as
 *     configured.
 *
 * The second ownership path is `tenant_id`, which these rows always carry.
 * Declaring it here keeps isolation STRUCTURAL rather than conventional:
 * a tenant-wide configuration is visible inside its own tenant and nowhere
 * else, and with no resolved tenant the comparison against -1 matches
 * nothing, so this fails closed like every other scope in this codebase.
 *
 * WHY NOT `companyScopeAllowsGlobalRows()`. That hook makes null rows
 * visible to EVERYONE. It is right for the platform-wide defaults in
 * NumberingFormat, NumberingSequence and KpiCategory, whose null rows
 * carry no tenant at all. These rows DO carry a tenant, so "shared with
 * everyone" would be a cross-tenant config leak -- the same distinction
 * Task's own hook draws: "shared with everyone" and "owned through a
 * different column" are different answers, and only the second is safe
 * here.
 */
trait IsTenantWideConfiguration
{
    public function companyScopeNullOwnerQuery(Builder $query): void
    {
        $query->where(
            $this->getTable().'.tenant_id',
            app(CurrentTenant::class)->id() ?? -1
        );
    }
}
