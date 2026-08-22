<?php

namespace App\Policies;

use App\Models\Company;
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
        return $this->belongsToCurrentTenant($project);
    }

    public function create(User $user): bool
    {
        return $user->canManageProjects();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->canManageProjects() && $this->belongsToCurrentTenant($project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->canManageProjects() && $this->belongsToCurrentTenant($project);
    }

    public function manageManpower(User $user, Project $project): bool
    {
        return $user->canManageProjects() && $this->belongsToCurrentTenant($project);
    }

    /**
     * v1.11.7 tenant-isolation fix (Production Readiness Follow-Up,
     * Part 5). This policy only ever checked the role capability, never
     * which tenant the Project belongs to -- {project} route-model-
     * binding has no automatic TenantScope of its own (that scope
     * applies only to Company, see App\Models\Scopes\TenantScope's own
     * doc comment), so any user with canManageProjects() could
     * previously update/delete ANY tenant's project given a guessable
     * id. Same fix pattern already applied to EmployeePpePolicy earlier
     * this pass.
     */
    private function belongsToCurrentTenant(Project $project): bool
    {
        return Company::query()->pluck('id')->contains($project->company_id);
    }
}
