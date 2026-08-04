<?php

/**
 * Department Access Map (v1.10.3). The backend counterpart to
 * `resources/js/lib/workspaces.js`'s `PREFIX_TO_WORKSPACE` -- deliberately
 * a separate, explicit PHP map rather than trying to parse the JS file at
 * runtime. Keyed the same way (route-name prefix -> owning department
 * key), used by `App\Http\Middleware\RestrictDepartmentAccess` to
 * actually enforce what the frontend only ever hid from view.
 *
 * A prefix not listed here at all is treated as universal (never denied
 * for a Department User) -- see the middleware's own `$universal` list
 * for the small set of routes every authenticated user needs regardless
 * of department (dashboard, work center, search, logout).
 */
return [
    // v1.10.4: kpi-input/kpi-records moved here from 'hr' -- the KPI
    // module's routes were already role:super_admin,hse-gated at the
    // route level, so this map was actually wrong before, not just the
    // frontend nav; see workspaces.js's own v1.10.4 note.
    'hr' => ['employees', 'leave-requests', 'hr'],
    'hse' => ['ppe', 'incidents', 'kpi-input', 'kpi-records', 'hse'],
    'project-management' => ['projects', 'daily-reports', 'milestones', 'project-management'],
    'logistics' => ['material-requests', 'goods-receipts', 'logistics'],
    'warehouse' => ['warehouse'],
    'procurement' => ['procurement'],
    'asset-management' => ['asset-management'],
    'maintenance' => ['maintenance'],
    'quality-control' => ['quality-control'],
    'finance' => ['finance'],

    // Not real departments a user can be assigned to -- listed here only
    // so their route prefixes are correctly DENIED to every Department
    // User rather than falling through as "universal". No department_key
    // will ever equal these, so they're effectively "Administrator only".
    'reports' => ['reports'],
    'administration' => ['settings'],
];
