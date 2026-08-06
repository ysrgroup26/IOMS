<?php

namespace App\Services;

use App\Models\ApprovalFlow;
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
 */
class ApprovalFlowResolver
{
    public function resolve(string $moduleKey, Model $approvable): ?ApprovalFlow
    {
        $companyId = $approvable->company_id ?? null;

        $flows = ApprovalFlow::where('module_key', $moduleKey)
            ->where('is_active', true)
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
