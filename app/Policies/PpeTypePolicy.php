<?php

namespace App\Policies;

use App\Models\User;

class PpeTypePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // needed for dropdowns/dashboard across all roles
    }

    public function create(User $user): bool
    {
        return $user->canManagePpeMaster();
    }

    public function update(User $user): bool
    {
        return $user->canManagePpeMaster();
    }

    public function delete(User $user): bool
    {
        return $user->canManagePpeMaster();
    }
}
