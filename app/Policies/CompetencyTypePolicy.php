<?php

namespace App\Policies;

use App\Models\User;

/**
 * Milestone 4, Workstream A2. Mirrors PpeTypePolicy -- viewAny() is open
 * (needed for dropdowns everywhere a competency type is selected), write
 * actions gated the same way Employee master data already is.
 */
class CompetencyTypePolicy
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
