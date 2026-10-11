<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // all four roles can view the employee list
    }

    public function view(User $user, Employee $employee): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->isAdmin();
    }

    /**
     * v2.108.0 -- the CAPABILITY to remove employees in bulk, which is
     * not a question about any one record.
     *
     * Same authority as deleting one (`isAdmin()`): bulk deletion is not
     * a wider power than single deletion, it is the same power applied
     * to a set, and the set is bounded and tenant-checked in
     * BulkDestroyEmployeesRequest. It is a separate method because
     * `delete` needs an Employee instance to answer, and the bulk action
     * has to be authorized BEFORE any record is resolved -- the request
     * is refused on authority first, so an unauthorized caller never
     * learns whether the ids they sent exist.
     */
    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
