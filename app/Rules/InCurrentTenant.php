<?php

namespace App\Rules;

use App\Models\Company;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.37.0 (Master Audit, P0-4). Laravel's own `exists:table,id` rule
 * builds a raw query builder -- it never passes through Eloquent, so it
 * never applies `App\Models\Scopes\TenantScope`. Every
 * `exists:companies,id` / `exists:employees,id` / `exists:projects,id`
 * in this codebase therefore accepted ANY tenant's id, letting a user
 * attach their record to another tenant's company/project/employee (and,
 * for `company_id`, write a record INTO another tenant outright).
 *
 * This was already understood and fixed one module at a time -- see the
 * doc comments in `StoreEmployeeRequest`, `StoreCompetencyTypeRequest`,
 * `StoreShiftRequest`, `GoodsReceiptController`, `IncidentController`
 * and `MilestoneController`, each of which hand-rolled the same
 * `Rule::in(Company::query()->pluck('id'))` fix locally. The piecemeal
 * approach left ~25 sites unfixed and offered nothing to stop the next
 * one being written the same way. This rule is that fix expressed once.
 *
 * Two shapes, resolved automatically:
 *   - `companies` itself      -> the id must be one of THIS tenant's
 *                                companies (Company::query() applies
 *                                TenantScope, so this is the authority).
 *   - any company-owned table -> the row's own `company_id` must be one
 *                                of this tenant's companies.
 *
 * Deliberately fails CLOSED, matching TenantScope's own stance: an
 * unknown id, a soft-deleted/absent row, or a table without the expected
 * ownership column all fail validation rather than passing silently.
 *
 * Usage (replaces `'exists:projects,id'`):
 *   'project_id' => ['required', new InCurrentTenant('projects')],
 *
 * NOT a replacement for the controller-level `abort_unless(...)` guards
 * on route-model-bound records -- that is a separate layer (this rule
 * covers request *input*, those guard record *access*). Both are wanted;
 * see docs/CONVENTIONS.md.
 */
class InCurrentTenant implements ValidationRule
{
    /**
     * Memoised per rule instance. Laravel reuses ONE rule object across
     * every element of a wildcard rule (`employee_ids.*`), so without
     * this a 100-employee bulk submit issued 100 identical
     * "which companies does this tenant own" queries on top of the 100
     * unavoidable per-row lookups.
     */
    private ?\Illuminate\Support\Collection $tenantCompanyIds = null;

    public function __construct(
        private readonly string $table,
        private readonly string $ownerColumn = 'company_id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // `nullable` runs before this rule and short-circuits on null;
        // an explicitly-sent empty string is treated the same way so a
        // cleared optional select doesn't produce a confusing failure.
        if ($value === null || $value === '') {
            return;
        }

        $tenantCompanyIds = $this->tenantCompanyIds ??= Company::query()->pluck('id');

        if ($this->table === 'companies') {
            if (! $tenantCompanyIds->contains($value)) {
                $fail('The selected :attribute is invalid.');
            }

            return;
        }

        if (! Schema::hasColumn($this->table, $this->ownerColumn)) {
            $fail('The selected :attribute is invalid.');

            return;
        }

        $ownerId = DB::table($this->table)->where('id', $value)->value($this->ownerColumn);

        // A null owner column means the row is not company-owned at all
        // (some tables allow a global/shared row). Those are only
        // acceptable where the caller explicitly opts in, which no
        // current caller does -- so treat null as a failure rather than
        // silently allowing a shared row to cross a tenant boundary.
        if ($ownerId === null || ! $tenantCompanyIds->contains($ownerId)) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
