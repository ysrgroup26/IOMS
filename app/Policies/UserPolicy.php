<?php

namespace App\Policies;

use App\Models\User;

/**
 * User Management is Super-Admin-only in v1.2 (HSE does NOT get this,
 * per product decision -- HSE's operational scope is Departments/
 * Positions/Employees/Projects/KPI, not accounts). Uses isSuperAdmin()
 * explicitly rather than isAdmin(), since isAdmin() now also covers HSE.
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
        return $user->isSuperAdmin();
    }

    public function delete(User $user, User $target): bool
    {
        // Prevent a Super Admin from deleting their own account and locking themselves out.
        return $user->isSuperAdmin() && $user->id !== $target->id;
    }
}
