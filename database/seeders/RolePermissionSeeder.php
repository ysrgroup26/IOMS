<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    // Spatie's model_has_roles/model_has_permissions pivot tables require
    // a NON-NULL team id as part of their primary key even though
    // `roles.tenant_id` itself is nullable -- so a genuinely tenant-less
    // role (Platform Super Admin) can't use `null` as its team id the way
    // User::isPlatformAdmin() uses null. `0` is used as that sentinel
    // instead: not a real tenant id (auto-increment starts at 1) and
    // distinct from TenantScope's own `-1` "no tenant resolved" sentinel,
    // so the two can never collide.
    private const PLATFORM_TEAM_ID = 0;

    /**
     * Milestone 2 (RBAC Foundation, Task #41). Builds the permission
     * catalog (global, not tenant-scoped -- see config/permission_catalog.php)
     * and, for the current tenant, one spatie Role per existing `role`
     * column value with a permission set matching that role's EXISTING
     * isX()/canX() capabilities on the User model as closely as possible.
     * This does not change what any controller actually enforces today --
     * see docs/ADR/008-tenancy-foundation.md's RBAC decision for why that
     * migration is deliberately separate. What this DOES make real: every
     * seeded user gets ->assignRole() called, so ->hasRole()/->can() are
     * genuinely usable starting now, for whatever is built next on top of
     * this (Task #45's Role/Permission management UI, in particular).
     */
    public function run(): void
    {
        $permissions = config('permission_catalog.permissions', []);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $tenantId = app(CurrentTenant::class)->id();

        if (! $tenantId) {
            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $rolePermissions = [
            User::ROLE_SUPER_ADMIN => array_values(array_filter($permissions, fn ($p) => ! str_starts_with($p, 'platform.'))),

            User::ROLE_HSE => [
                'employees.view', 'employees.create', 'employees.edit', 'employees.delete', 'employees.import', 'employees.export',
                'ppe.view', 'ppe.issue', 'ppe.return',
                'projects.view', 'projects.create', 'projects.edit',
                'daily_reports.view', 'daily_reports.create', 'daily_reports.edit', 'daily_reports.delete',
                'material_requests.view', 'material_requests.create',
                'kpi_input.view', 'kpi_input.create', 'kpi_input.edit', 'kpi_input.delete',
                'leave.view', 'leave.manage',
                'incidents.view', 'incidents.manage',
                'milestones.view', 'milestones.manage',
                'reports.view', 'reports.export',
                'settings.manage_departments', 'settings.manage_positions',
            ],

            User::ROLE_HRD => [
                'employees.view', 'reports.view',
            ],

            User::ROLE_MANAGER => [
                'employees.view', 'reports.view', 'projects.view',
            ],

            User::ROLE_WAREHOUSE => [
                'material_requests.view', 'material_requests.process',
                'goods_receipts.view', 'goods_receipts.manage',
            ],

            User::ROLE_PLATFORM_ADMIN => [
                'platform.manage_tenants', 'platform.manage_packages', 'platform.manage_subscriptions',
            ],
        ];

        foreach ($rolePermissions as $roleName => $perms) {
            $teamIdForRole = $roleName === User::ROLE_PLATFORM_ADMIN ? self::PLATFORM_TEAM_ID : $tenantId;
            app(PermissionRegistrar::class)->setPermissionsTeamId($teamIdForRole);
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web', 'tenant_id' => $teamIdForRole]
            );
            $role->syncPermissions($perms);
        }

        // Assign each seeded user the Role matching their existing `role`
        // column, so ->hasRole()/->can() work immediately for every
        // account created by UserSeeder/PlatformAdminSeeder.
        User::query()->whereIn('role', array_keys($rolePermissions))->each(function (User $user) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id ?? self::PLATFORM_TEAM_ID);
            $user->syncRoles([$user->role]);
        });
    }
}
