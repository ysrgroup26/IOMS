<?php

namespace App\Policies;

use App\Models\EmployeePpe;
use App\Models\User;

class EmployeePpePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManagePpeDistribution();
    }

    public function update(User $user, EmployeePpe $record): bool
    {
        return $user->canManagePpeDistribution() && $this->belongsToCurrentTenant($record);
    }

    public function delete(User $user, EmployeePpe $record): bool
    {
        return $user->canManagePpeDistribution() && $this->belongsToCurrentTenant($record);
    }

    /**
     * v1.11.6 tenant-isolation fix (Production Readiness pass, Part 22).
     * Route-model-bound single-record endpoints (`{employeePpe}` on
     * update/destroy) skip `TenantScope` entirely: `EmployeePpe` has no
     * tenant_id of its own and no global scope, so
     * `EmployeePpe::findOrFail($id)` previously succeeded for ANY
     * tenant's record given a guessable/enumerable id -- this policy
     * only ever checked the role capability, never which tenant the
     * record belongs to. `Company`'s own `TenantScope` (the app's single
     * tenant-isolation anchor, see App\Models\Scopes\TenantScope) DOES
     * apply automatically the moment a `Company` row is queried -- so
     * resolving the record's employee's company and confirming it still
     * resolves (rather than returning null, which is what TenantScope
     * does for a cross-tenant company id) is enough to detect and reject
     * a cross-tenant record, with no new scope or duplicated logic.
     */
    private function belongsToCurrentTenant(EmployeePpe $record): bool
    {
        return (bool) $record->employee?->company()->exists();
    }
}
