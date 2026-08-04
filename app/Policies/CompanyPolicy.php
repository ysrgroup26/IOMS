<?php

namespace App\Policies;

use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Company filter is visible to all roles
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
