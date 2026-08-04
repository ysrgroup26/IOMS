<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Creates one default account per role (Super Admin, HSE, HRD, Manager)
     * so the whole permission matrix is testable immediately after install.
     * CHANGE THESE PASSWORDS before deploying to production.
     *
     * v1.5.1 rebrand: default accounts now use @ioms.local instead of the
     * legacy @safetylog.local domain. This must stay idempotent across
     * THREE possible states, not just two:
     *   1. Fresh install (neither email exists)      -> create @ioms.local
     *   2. Upgrading, not yet re-seeded (old exists)  -> migrate old to new
     *   3. Already re-seeded once (new exists)        -> do nothing further
     * A naive updateOrCreate() keyed on the old email handles (1) and (2)
     * correctly, but on state (3) it can no longer find the old email, so
     * it would try to CREATE a duplicate of the new email and crash on the
     * unique constraint. seedAccount() below checks for the new email
     * first and skips entirely if it's already there.
     */
    public function run(): void
    {
        $this->seedAccount('admin@safetylog.local', 'admin@ioms.local', 'Super Admin', User::ROLE_SUPER_ADMIN);
        // department_key = 'hse' (v1.10.3): the seeded HSE account is the
        // canonical Department User example/test account -- restricted to
        // the HSE department's own sidebar, no Department Selector, no
        // Administration access. HRD/Manager are deliberately left
        // Administrator-like (department_key null) -- their documented
        // capability already spans multiple departments (HRD: Dashboard/
        // Employees/Reports; Manager: Dashboard/Reports/Employees/
        // Projects), so there's no single department to assign them to
        // without narrowing what they can already do; see
        // docs/ADR/007's v1.10.2 section for the same reasoning applied
        // to HSE before this account was actually restricted.
        $this->seedAccount('hse@safetylog.local', 'hse@ioms.local', 'HSE Officer', User::ROLE_HSE, 'hse');
        $this->seedAccount('hrd@safetylog.local', 'hrd@ioms.local', 'HRD Viewer', User::ROLE_HRD);
        $this->seedAccount('manager@safetylog.local', 'manager@ioms.local', 'Operations Manager', User::ROLE_MANAGER);
    }

    private function seedAccount(string $legacyEmail, string $newEmail, string $name, string $role, ?string $departmentKey = null): void
    {
        // Already migrated (or a fresh install that's already been seeded
        // once) -- nothing to do.
        if (User::where('email', $newEmail)->exists()) {
            return;
        }

        // Existing install still on the old domain -- migrate in place.
        $legacy = User::where('email', $legacyEmail)->first();
        if ($legacy) {
            $legacy->update(['email' => $newEmail, 'department_key' => $departmentKey]);

            return;
        }

        // Neither exists -- fresh install, create directly with the new domain.
        User::create([
            'name' => $name,
            'email' => $newEmail,
            'password' => Hash::make('password'),
            'role' => $role,
            'department_key' => $departmentKey,
            'is_active' => true,
        ]);
    }
}
