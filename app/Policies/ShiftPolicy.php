<?php

namespace App\Policies;

use App\Models\User;

/**
 * Milestone 4, Workstream A3. Mirrors CompetencyTypePolicy exactly.
 */
class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }
}
