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
        return $user->canManagePpeDistribution();
    }

    public function delete(User $user, EmployeePpe $record): bool
    {
        return $user->canManagePpeDistribution();
    }
}
