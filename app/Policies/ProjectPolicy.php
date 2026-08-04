<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Dashboard/Reports/Employees/Projects viewable by all four roles
    }

    public function view(User $user, Project $project): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->canManageProjects();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->canManageProjects();
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->canManageProjects();
    }

    public function manageManpower(User $user, Project $project): bool
    {
        return $user->canManageProjects();
    }
}
