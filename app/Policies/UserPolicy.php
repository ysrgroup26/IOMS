<?php

namespace App\Policies;

use App\Models\User;

/**
 * User Management is Super-Admin-only in v1.2 (HSE does NOT get this,
 * per product decision -- HSE's operational scope is Departments/
 * Positions/Employees/Projects/KPI, not accounts). Uses isSuperAdmin()
 * explicitly rather than isAdmin(), since isAdmin() now also covers HSE.
 *
 * Milestone 3 (UAT #1/#7 -- found while verifying Task #61):
 * update()/delete() previously checked only the ACTING user's role, never
 * whether $target actually belongs to the acting user's own tenant. Any
 * Administrator could act on a user from a different tenant (or the
 * Master account) -- fixed by requiring the same tenant_id here too, as
 * defense-in-depth alongside SettingsController's own explicit checks.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isSuperAdmin() && $user->tenant_id === $target->tenant_id;
    }

    public function delete(User $user, User $target): bool
    {
        // Prevent a Super Admin from deleting their own account and
        // locking themselves out, and prevent acting across tenants.
        return $user->isSuperAdmin() && $user->id !== $target->id && $user->tenant_id === $target->tenant_id;
    }
}
