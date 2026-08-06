<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Module Registry -- DEFAULT SEED DATA ONLY (Milestone 2, Task #42)
    |--------------------------------------------------------------------------
    |
    | As of Milestone 2, this array is NO LONGER read at runtime. The `modules`
    | DB table is the live registry now (see App\Models\Module,
    | database/seeders/ModuleSeeder.php, App\Http\Middleware\HandleInertiaRequests,
    | and SettingsController::updateModules) -- Super Admin manages it without a
    | code deploy. This file is kept only as ModuleSeeder's default data source
    | (a fresh install runs it once via `db:seed`), so this list isn't duplicated
    | a third time. Editing this array after the initial seed has already run
    | does nothing at runtime.
    |
    | `core` modules (Home, Dashboard, Settings) are never toggleable --
    | disabling them would leave the app unusable or unconfigurable, so
    | they're intentionally excluded from this registry entirely.
    |
    | HOW A FUTURE MODULE WOULD REGISTER (not built yet -- documented so
    | the mechanism doesn't need to change when one is added):
    |   1. Add a row via Settings (once a management UI exists) or directly
    |      via `App\Models\Module::create([...])` -- add it here too, only
    |      so a fresh install's default seed stays representative.
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
