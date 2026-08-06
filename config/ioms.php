<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IOMS Application Configuration
    |--------------------------------------------------------------------------
    |
    | Single source of truth for version/release/developer information
    | shown throughout the UI -- About dialog, sidebar footer, login
    | footer, and the Home page "What's New" banner all read this via the
    | shared Inertia prop (see HandleInertiaRequests::share()). Bump
    | `version` and update `release_date` + `whats_new` together whenever
    | a release ships, and add the previous release to `version_history`.
    | Nothing else in the codebase needs to change.
    |
    */

    'version' => '2.0.0',

    // Tester / Beta / Stable -- tracks the release stage explicitly.
    // Previously only implied in conversation, never actually stored.
    'stage' => 'Beta',

    'edition' => 'Enterprise Edition',

    'build' => '2026.08.16.1',

    'release_date' => '2026-08-16',

    'developer' => 'Yofhanza Shultona Rizqi S.',

    'company' => 'YSR Systems',

    'copyright_year' => '2026',

    'license' => 'Commercial Enterprise License',

    'website' => 'www.iomsplatform.com',

    'support_email' => 'support@ioms.local',

    'documentation_url' => 'docs.iomsplatform.com',

    'whats_new' => [
        'Multi-tenant SaaS foundation: Tenant model above Company, tenant-scoped isolation via TenantScope, Platform Super Admin distinction',
        'RBAC infrastructure via spatie/laravel-permission -- tenant-scoped roles/permissions, editable from Settings > Roles & Permissions',
        'Package + Subscription structure for future billing, seeded with Starter/Professional/Enterprise tiers',
        'Module and Department (Workspace) catalogs moved from config files into the database -- Super Admin can rename/reorder/enable without a deploy',
        'New Platform Super Admin console (/platform) for cross-tenant Tenant/Package/Subscription management',
    ],

    /*
    |--------------------------------------------------------------------------
    | Version History
    |--------------------------------------------------------------------------
    |
    | Shown on the About page below "What's New" as a scrollable release
    | history. Newest first. Kept intentionally short (a one-line summary
    | per release) -- CHANGELOG.md remains the detailed record.
    |
    */

    'version_history' => [
        ['version' => '2.0.0', 'date' => '2026-08-16', 'summary' => 'Milestone 2: Tenancy Foundation, Platform Super Admin, Package/Subscription structure, RBAC (spatie/laravel-permission), DB-driven Module and Workspace catalogs, Platform Super Admin console.'],
        ['version' => '1.6.10', 'date' => '2026-08-11', 'summary' => 'Material Request complete workflow (HasWorkflow trait); RBAC options evaluated (Spatie Permission recommended, docs/ADR/006); new Warehouse role.'],
        ['version' => '1.6.9', 'date' => '2026-08-09', 'summary' => 'Universal Approval Engine and Activity Timeline viewer -- verified ActivityLog already existed before building anything new.'],
        ['version' => '1.6.8', 'date' => '2026-08-08', 'summary' => 'Employee Import from Excel; Report Export architecture prepared; two severe runtime bugs found and fixed via verification, not assumption.'],
        ['version' => '1.6.7', 'date' => '2026-08-06', 'summary' => 'ModuleTabNav reusable navigation; several desktop density passes; Material Request and PPE Replacement Request MVPs; reusable PdfGeneratorService.'],
        ['version' => '1.6.6', 'date' => '2026-08-03', 'summary' => 'PPE employee-centric restructure; navigation redesign; Browser QA stabilization (white flash, Select bug sweep).'],
        ['version' => '1.6.5', 'date' => '2026-08-02', 'summary' => 'About Dialog rebuilt from scratch; sidebar branding hierarchy fixed; six shared foundation components.'],
        ['version' => '1.6.4', 'date' => '2026-08-01', 'summary' => 'Universal Task Engine foundation; QA stabilization pass across Dashboard, Sidebar, Dark Mode.'],
        ['version' => '1.6.3', 'date' => '2026-07-28', 'summary' => 'Dashboard/Sidebar/TopNav/Theme/Company Branding usability pass; real Global Search; Forgot/Reset Password.'],
        ['version' => '1.6.2', 'date' => '2026-07-27', 'summary' => 'Root-cause fix for watermark clipping; exact 240px/70px/16px/24px branding specs; searchable Combobox.'],
        ['version' => '1.6.1', 'date' => '2026-07-26', 'summary' => 'Fixed a real PHP parse error; Dashboard Today\'s Summary; Top Department Workload; About fields expanded.'],
        ['version' => '1.6.0', 'date' => '2026-07-25', 'summary' => 'Enterprise UI refresh: centralized config/ioms.php, About dialog scrolling fixed, branding sizing pass.'],
    ],

];
