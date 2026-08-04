<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Module Registry
    |--------------------------------------------------------------------------
    |
    | Single source of truth for which sidebar modules EXIST and can be
    | toggled on/off by Super Admin (Settings > Modules). This is the
    | architecture the spec asked for: "the sidebar navigation should be
    | designed so modules can be enabled or disabled by Super Admin in the
    | future" -- implemented here for every module that actually exists
    | today.
    |
    | `core` modules (Home, Dashboard, Settings) are never toggleable --
    | disabling them would leave the app unusable or unconfigurable, so
    | they're intentionally excluded from this registry entirely.
    |
    | HOW A FUTURE MODULE WOULD REGISTER (not built yet -- documented so
    | the mechanism doesn't need to change when one is added):
    |   1. Add its key/label here, e.g. 'fleet' => 'Fleet Management'.
    |   2. Add the corresponding nav item + icon in
    |      resources/js/Layouts/AuthenticatedLayout.jsx (with a
    |      `moduleKey` matching the key used here).
    |   3. Nothing else changes -- the toggle UI, the enabled_modules
    |      CompanySetting, and the nav-filtering logic are all already
    |      generic and will pick it up automatically.
    |
    | See ROADMAP.md for the list of modules planned for future versions
    | (HR, Asset, Fleet, Marine Operations, Procurement, Warehouse,
    | Maintenance, QC, Document Control, Visitor Management, Contractor
    | Management, Permit to Work, Risk Assessment, Incident Management) --
    | none of those are implemented; only the toggle architecture is ready
    | for them.
    |
    */

    'available' => [
        'employees' => 'Employees',
        'kpi_input' => 'Input KPI',
        'projects' => 'Projects',
        'ppe' => 'PPE Management',
        'daily_reports' => 'Daily Reports',
        'material_requests' => 'Material Requests',
        'reports' => 'Reports',
    ],

];
