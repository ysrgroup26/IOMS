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

    'version' => '2.3.0',

    // Tester / Beta / Stable -- tracks the release stage explicitly.
    // Previously only implied in conversation, never actually stored.
    'stage' => 'Beta',

    'edition' => 'Enterprise Edition',

    'build' => '2026.08.29.1',

    'release_date' => '2026-08-29',

    'developer' => 'Yofhanza Shultona Rizqi S.',

    'company' => 'YSR Systems',

    'copyright_year' => '2026',

    'license' => 'Commercial Enterprise License',

    'website' => 'www.iomsplatform.com',

    'support_email' => 'support@ioms.local',

    'documentation_url' => 'docs.iomsplatform.com',

    'whats_new' => [
        'New: Waste Container Inventory -- track physical waste containers (drums, IBC tanks, jumbo bags) as total/available/in-use/damaged stock, separate from Waste Records (the actual waste material)',
        'Re-verified the v2.2.0 Man-Hour HTTP 500 fix -- confirmed still correct and intact',
        'Audited PPE Master Edit visibility -- confirmed working as designed (Super Admin only, per the existing v1.3 spec); no change needed',
        'Quick Actions gained a "New Waste Record" entry for HSE',
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
        ['version' => '2.3.0', 'date' => '2026-08-29', 'summary' => 'HSE Operations pass: new Waste Container Inventory (physical drum/IBC/jumbo-bag stock, separate from waste material records), Man-Hour 500 fix re-verified, PPE Master Edit audited and confirmed correct, Quick Actions gained a Waste entry.'],
        ['version' => '2.2.0', 'date' => '2026-08-25', 'summary' => 'IOMS OS Ecosystem pass: Man-Hour 500 root-caused and fixed (ambiguous SQL column), Quick Actions and Work Center Action Center extended across every department, Global Search extended (PPE/CAPA/PTW/Assets/Vendors) and its cross-tenant scoping gap fixed, a second cross-tenant leak fixed in Work Center approvals/PPE-alerts, sidebar navigation language reverted to English.'],
        ['version' => '2.1.0', 'date' => '2026-08-25', 'summary' => 'HSE Starter package made genuinely operational: Man-Hour opened to HSE (entitlement-dependency-rule fix), Package-to-Workspace/Module grant mapping wired into tenant creation, PPE Dashboard "+" action fix, optional per-workspace entitlement enforcement (off by default).'],
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
