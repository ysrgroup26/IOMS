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
        'ppe', 'ppe-types', 'incidents', 'kpi-input', 'kpi-records', 'hse',
        'safety-observations', 'hse-inspections', 'risk-assessments',
        'job-safety-analyses', 'permits-to-work', 'gas-test-records', 'loto-records',
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
    ],
    'project-management' => [
        'projects', 'daily-reports', 'milestones', 'project-management',
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
    ],
    'asset-management' => ['assets', 'asset-management'],
    'maintenance' => ['maintenance-requests', 'work-orders', 'maintenance'],
    'quality-control' => ['inspection-requests', 'ncrs', 'quality-control'],
    'finance' => ['finance'],

    // Not real departments a user can be assigned to -- listed here only
    // so their route prefixes are correctly DENIED to every Department
    // User rather than falling through as "universal". No department_key
    // will ever equal these, so they're effectively "Administrator only".
    'reports' => ['reports', 'analytics', 'report-center'],
    'administration' => ['settings', 'activity-center'],
];
