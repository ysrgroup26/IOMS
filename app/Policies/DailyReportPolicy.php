<?php

namespace App\Policies;

use App\Models\DailyReport;
use App\Models\User;

class DailyReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DailyReport $report): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageDailyReports();
    }

    public function update(User $user, DailyReport $report): bool
    {
        return $user->canManageDailyReports();
    }

    public function delete(User $user, DailyReport $report): bool
    {
        return $user->canManageDailyReports();
    }
}
