<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Reference/lookup rows only (see Role model docblock -- users.role
     * is the actual authorization source of truth). Updated for v1.2's
     * four-role system: Super Admin, HSE, HRD, Manager.
     */
    public function run(): void
    {
        Role::updateOrCreate(['code' => 'super_admin'], [
            'label' => 'Super Admin',
            'description' => 'Full access: everything, including Company & User Management.',
            'permissions' => [
                'dashboard.view', 'employees.view', 'employees.manage',
                'kpi.input', 'reports.view', 'reports.export',
                'projects.view', 'projects.manage',
                'settings.operational', 'settings.system', 'users.manage', 'companies.manage',
            ],
        ]);

        Role::updateOrCreate(['code' => 'hse'], [
            'label' => 'HSE',
            'description' => 'Can input KPI, manage employees, manage operations (Departments/Positions). Cannot manage Companies or Users.',
            'permissions' => [
                'dashboard.view', 'employees.view', 'employees.manage',
                'kpi.input', 'reports.view', 'reports.export',
                'projects.view', 'projects.manage',
                'settings.operational',
            ],
        ]);

        Role::updateOrCreate(['code' => 'hrd'], [
            'label' => 'HRD',
            'description' => 'Read-only access: dashboard, employees, reports.',
            'permissions' => ['dashboard.view', 'employees.view', 'reports.view', 'reports.export'],
        ]);

        Role::updateOrCreate(['code' => 'manager'], [
            'label' => 'Manager',
            'description' => 'View-only: Dashboard, Reports, Employees, Projects.',
            'permissions' => ['dashboard.view', 'employees.view', 'reports.view', 'projects.view'],
        ]);
    }
}
