<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * v1.10.9 (HSE Domain Hardening, Part A). A safe, explicit alternative to
 * a raw `php artisan tinker` one-liner for the exact operational gap
 * found in production: an existing user (created before Settings > Users
 * gained the Department Restriction field in v1.10.7) has
 * `department_key = null` and needs it set to a real department.
 *
 * Deliberately NOT a migration/seeder -- this is a per-account, admin-
 * invoked action (one user, one department, explicitly named on the
 * command line), never something that runs automatically or touches
 * every row. Never assign department_key to `platform_admin` or
 * `super_admin` roles automatically -- those bypass RestrictDepartmentAccess
 * entirely by having no assigned department, and this command refuses to
 * change that for them unless --force is passed, so this tool cannot be
 * used to accidentally lock out an administrator.
 */
class AssignUserDepartment extends Command
{
    protected $signature = 'users:assign-department {email : The user\'s email address} {department : One of config(\'departments\') keys, e.g. hse} {--force : Allow assigning a department to a super_admin/platform_admin account}';

    protected $description = 'Set department_key on one existing user, restricting their navigation/route access to that department.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $department = $this->argument('department');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email \"{$email}\".");

            return self::FAILURE;
        }

        $assignable = collect(config('departments', []))
            ->keys()
            ->reject(fn (string $key) => in_array($key, ['reports', 'administration'], true))
            ->values();

        if (! $assignable->contains($department)) {
            $this->error("\"{$department}\" is not a valid department key. Valid: ".$assignable->implode(', '));

            return self::FAILURE;
        }

        if (in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_PLATFORM_ADMIN], true) && ! $this->option('force')) {
            $this->error(
                "\"{$user->email}\" has role \"{$user->role}\" -- restricting an administrator's own department ".
                'access is very likely a mistake (they typically need cross-department reach). Re-run with --force if this is genuinely intended.'
            );

            return self::FAILURE;
        }

        $previous = $user->department_key ?? '(none -- Administrator)';
        $user->update(['department_key' => $department]);

        $this->info("Updated \"{$user->email}\": department_key {$previous} -> {$department}.");
        $this->line('This account is now restricted to that department\'s routes -- verify with a direct URL to another department, which should now 403.');

        return self::SUCCESS;
    }
}
