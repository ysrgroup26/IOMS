<?php

/**
 * Milestone 2 (RBAC Foundation, Task #41). Flat catalog of `module.action`
 * permission strings, mirroring how `config/modules.php` already
 * represents modules as a flat set of keys -- the same convention, one
 * layer more granular. NOT the live authorization path yet: every
 * existing controller still runs on the `role` column + isX()/canX()
 * (see docs/ADR/008-tenancy-foundation.md's RBAC decision) -- this
 * catalog and the roles it's assigned to (RolePermissionSeeder) exist so
 * that migration is additive later, not a redesign. A "scope" suffix
 * (module.action.own / module.action.company / module.action.all) is
 * deliberately deferred -- nothing in this app currently has row-level
 * ownership finer than company_id, so a scope layer would be speculative
 * right now; add it when a real feature needs it.
 *
 * Grouped by module to match `config/modules.php`'s existing key names
 * wherever one exists, so the two registries stay easy to cross-reference.
 */
return [

    'permissions' => [
        // Employees
        'employees.view', 'employees.create', 'employees.edit', 'employees.delete', 'employees.import', 'employees.export',

        // PPE
        'ppe.view', 'ppe.manage_master', 'ppe.issue', 'ppe.return',

        // Projects
        'projects.view', 'projects.create', 'projects.edit', 'projects.delete',

        // Daily Reports
        'daily_reports.view', 'daily_reports.create', 'daily_reports.edit', 'daily_reports.delete',

        // Material Requests
        'material_requests.view', 'material_requests.create', 'material_requests.approve', 'material_requests.process', 'material_requests.override',

        // KPI
        'kpi_input.view', 'kpi_input.create', 'kpi_input.edit', 'kpi_input.delete',

        // Leave
        'leave.view', 'leave.manage',

        // Incidents
        'incidents.view', 'incidents.manage',

        // Milestones
        'milestones.view', 'milestones.manage',

        // Goods Receipts
        'goods_receipts.view', 'goods_receipts.manage',

        // Reports
        'reports.view', 'reports.export',

        // Settings (tenant-side administration)
        'settings.manage_companies', 'settings.manage_departments', 'settings.manage_positions',
        'settings.manage_users', 'settings.manage_modules', 'settings.manage_branding',

        // Platform-level (Task #44's own surface -- listed here now so the
        // full catalog is in one place; not assigned to any tenant-side role)
        'platform.manage_tenants', 'platform.manage_packages', 'platform.manage_subscriptions',
    ],

];
