<?php

namespace App\Services;

use App\Models\ApprovalFlow;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Universal Approval Engine v2). Finds the ApprovalFlow that
 * applies to a given approvable record, if any -- company-specific flows
 * win over tenant-wide defaults; among same-scope flows, the first whose
 * `conditions` match the record (evaluated in `priority` order) wins. A
 * flow with null/empty conditions is a catch-all/default. Returns null
 * when nothing is configured, which is the ENTIRE trigger for
 * `ApprovalEngine` to use the legacy single-step path -- see that
 * class's own doc comment.
 *
 * Milestone 3 (Task #62): every flow considered is now filtered to
 * `tenant_id` = the CURRENT tenant (or the platform-wide `tenant_id`
 * null fallback) -- `approval_flows` originally had no tenant_id at all,
 * meaning one tenant's catch-all flow for a module would silently apply
 * to every other tenant's records of that module too. See the adding
 * migration's own doc comment.
 */
class ApprovalFlowResolver
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function resolve(string $moduleKey, Model $approvable): ?ApprovalFlow
    {
        $companyId = $approvable->company_id ?? null;
        $tenantId = $this->currentTenant->id();

        $flows = ApprovalFlow::where('module_key', $moduleKey)
            ->where('is_active', true)
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id');
                if ($tenantId) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->where(function ($query) use ($companyId) {
                $query->whereNull('company_id');
                if ($companyId) {
                    $query->orWhere('company_id', $companyId);
                }
            })
            ->with('steps')
            ->orderByRaw('company_id IS NULL') // company-specific rows first
            ->orderBy('priority')
            ->get();

        foreach ($flows as $flow) {
            if ($this->matchesConditions($flow->conditions, $approvable)) {
                return $flow;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{field:string, operator:string, value:mixed}>|null  $conditions
     */
    private function matchesConditions(?array $conditions, Model $approvable): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $actual = data_get($approvable, $condition['field'] ?? '');
            $expected = $condition['value'] ?? null;

            $matches = match ($condition['operator'] ?? '=') {
                '>' => $actual > $expected,
                '>=' => $actual >= $expected,
                '<' => $actual < $expected,
                '<=' => $actual <= $expected,
                '!=' => $actual != $expected,
                'in' => in_array($actual, (array) $expected),
                default => $actual == $expected,
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }
}
