<?php

namespace App\Policies;

use App\Models\ManHourLog;
use App\Models\User;

class ManHourLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageManHour();
    }

    public function update(User $user, ManHourLog $record): bool
    {
        return $user->canManageManHour() && $this->belongsToCurrentTenant($record);
    }

    public function delete(User $user, ManHourLog $record): bool
    {
        return $user->canManageManHour() && $this->belongsToCurrentTenant($record);
    }

    /** Same tenant-isolation pattern applied to EmployeePpePolicy this pass -- see that class's own doc comment. */
    private function belongsToCurrentTenant(ManHourLog $record): bool
    {
        return (bool) \App\Models\Company::whereKey($record->company_id)->exists();
    }
}
