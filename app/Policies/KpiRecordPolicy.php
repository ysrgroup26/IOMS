<?php

namespace App\Policies;

use App\Models\KpiRecord;
use App\Models\User;

class KpiRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // reports are visible to all four roles
    }

    public function create(User $user): bool
    {
        return $user->isAdmin(); // Super Admin + HSE can input KPI
    }

    public function update(User $user, KpiRecord $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, KpiRecord $record): bool
    {
        return $user->isAdmin();
    }
}
