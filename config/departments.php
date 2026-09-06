<?php

/**
 * Department Access Map (v1.10.3, hardened v1.10.5). The backend counterpart
 * to `resources/js/lib/workspaces.js`'s `PREFIX_TO_WORKSPACE` -- deliberately
 * a separate, explicit PHP map rather than trying to parse the JS file at
 * runtime. Keyed the same way (route-name prefix -> owning department
 * key), used by `App\Http\Middleware\RestrictDepartmentAccess` to actually
 * enforce what the frontend only ever hid from view.
 *
 * v1.10.5: this map is now treated as EXHAUSTIVE for every non-universal
 * route prefix in the app (cross-checked against `routes/web.php` directly,
 * not assumed) -- see the middleware's own doc comment for why a prefix
 * missing from here is now DENIED rather than allowed. A truly
 * cross-department route belongs in the middleware's own
 * `UNIVERSAL_PREFIXES` list instead, not omitted from this file.
 */
return [
    'hr' => [
        'employees', 'employee-competencies', 'employee-rosters',
        'employee-shift-assignments', 'leave-requests', 'shifts', 'rosters',
        'roster-patterns', 'competency', 'competency-types', 'hr',
        // v1.11.15: 'man-hour' moved OUT of this list -- see
        // RestrictDepartmentAccess::UNIVERSAL_PREFIXES for why (genuinely
        // shared HR+HSE data, same reasoning already applied to
        // 'calendar'; this map only supports one owning department per
        // prefix, and Man-Hour legitimately has two consumers now that
        // `canManageManHour()` also grants HSE).
    ],
    // v1.10.5: expanded from the pre-Workstream-B list (ppe, incidents,
    // kpi-input, kpi-records, hse) to cover every HSE route prefix that
    // actually exists today -- Safety Observation, HSE Inspection, HIRADC,
    // JSA, Permit To Work, LOTO, TBM, CAPA, HSE master data, and (placed
    // here per their own `canManage*()` gates reusing the HSE role)
    // Contractor/Visitor/Document Control.
    'hse' => [
        // v2.52.0: Regulations & Standards Register.
        'hse-regulations',
        'ppe', 'ppe-types', 'incidents', 'kpi-input', 'kpi-records', 'hse',
        'safety-observations', 'hse-inspections', 'risk-assessments',
        // v2.42.0: 'permits-to-work' moved to RestrictDepartmentAccess's
        // UNIVERSAL_PREFIXES -- PTW authoring is a cross-department
        // capability (User::canCreatePtw() unions canManageHse() with an
        // individually-granted ptw_access flag), so gating the ROUTE on
        // department membership 403'd granted non-HSE users before the
        // controller's own capability check could run. 'gas-test-records'
        // stays here: only its standalone register view uses that prefix --
        // the nested PTW gas-test actions are named permits-to-work.*.
        'job-safety-analyses', 'gas-test-records', 'loto-records',
        'tbm-meetings', 'corrective-actions', 'hazard-categories',
        'safety-equipment', 'hse-materials', 'p3k-boxes',
        'hse-equipment-types', 'hse-checklist-templates',
        'contractors', 'visitors', 'controlled-documents',
        // v1.11.4 (HSE Waste Management) -- every route-name prefix this
        // module introduced (routes/web.php: waste.master, waste.dashboard,
        // waste-records.*, waste-movements.*, waste-types.*,
        // waste-storage-locations.*), using this project's existing HSE
        // route-naming convention, not an invented one.
        'waste', 'waste-records', 'waste-movements', 'waste-types', 'waste-storage-locations',
        // v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 7) --
        // Waste Container Inventory, same 'hse' ownership as every other
        // waste-* prefix above.
        'waste-containers',
        // v2.46.0: PTW Access is granted from Settings > Users, and BOTH that
        // page (`settings.index`) and the write itself
        // (`settings.users.ptw-access`) are already gated `role:super_admin,hse`
        // in routes/web.php. Listing `settings` here too lets the HSE sidebar's
        // own "PTW Access" entry actually resolve for a department-scoped HSE
        // user, instead of being 403'd by the routing layer before the route's
        // role gate could allow them.
        //
        // This grants NO new capability: every mutating settings sub-route
        // (company/branding, modules, roles, companies, backup) sits in the
        // separate `role:super_admin` group and is unaffected, and a user with
        // department_key='hse' but a non-HSE ROLE is still stopped by that same
        // role gate. `settings` remains owned by administration as well.
        'settings',
    ],
    'project-management' => [
        'projects', 'daily-reports', 'milestones', 'project-management',
        // v2.52.0: BAST. Raised by whichever function completed the work
        // being handed over, so it has several legitimate owners -- the
        // prefix map has allowed multiple owners since v2.46.0 exactly
        // for this case, and there is no `management` department_key for
        // it to live under alone.
        'handover-records',
    ],
    // v1.10.5: Item Master/Warehouse/Stock added -- Warehouse stays inside
    // Logistics (not the separate, still-placeholder 'warehouse' department
    // below), matching `workspaces.js`'s own explicit note.
    'logistics' => [
        'material-requests', 'goods-receipts', 'logistics',
        'items', 'warehouses', 'stock',
    ],
    'warehouse' => ['warehouse'],
    'procurement' => [
        'procurement', 'purchase-requisitions', 'purchase-orders', 'rfqs', 'vendors',
        'handover-records',
    ],
    'asset-management' => ['assets', 'asset-management'],
    'maintenance' => ['maintenance-requests', 'work-orders', 'maintenance', 'handover-records'],
    'quality-control' => ['inspection-requests', 'ncrs', 'quality-control'],
    'finance' => ['finance'],

    // Not real departments a user can be assigned to -- listed here only
    // so their route prefixes are correctly DENIED to every Department
    // User rather than falling through as "universal". No department_key
    // will ever equal these, so they're effectively "Administrator only".
    'reports' => ['reports', 'analytics', 'report-center'],
    'administration' => ['settings', 'activity-center'],
];
