<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\MaterialRequest;
use App\Models\Milestone;
use App\Models\PpeReplacementRequest;
use App\Models\Task;

/**
 * Milestone 3 (Analytics Framework, Task #64). The reusable dataset
 * registry every module registers into instead of each dashboard writing
 * its own bespoke aggregation query -- `App\Services\AnalyticsService`
 * is the only thing that reads this file. Adding a dataset for a new
 * module is a config entry here, nothing else; consumers (dashboards,
 * Report Center, future widgets) always go through
 * `AnalyticsService::dataset($key)` and never query these models
 * directly for chart data.
 *
 * Every dataset is scoped to the current tenant's companies by
 * AnalyticsService itself (via the already-tenant-scoped `Company`
 * model) -- a dataset definition here never needs its own tenant check.
 *
 * Shape of each entry:
 *   'label'      => Human label shown in the Analytics picker / chart title.
 *   'model'      => Fully-qualified Eloquent model class to aggregate.
 *   'group_by'   => Column to GROUP BY (must exist on the model's table).
 *   'chart'      => 'pie' | 'bar' | 'line' -- a hint for the frontend, not
 *                    enforced server-side.
 *   'module_key' => Module registry key this dataset belongs to (gates
 *                    visibility the same way a sidebar item would --
 *                    a dataset for a disabled module is hidden).
 *   'date_field' => Optional. When present, AnalyticsService can also
 *                    return a month-over-month trend for this dataset
 *                    (used by 'line' charts) instead of a single snapshot.
 *   'label_model' => Optional. Set when group_by is a foreign key
 *                    (e.g. department_id) -- AnalyticsService resolves
 *                    each bucket value through this model's `name`
 *                    column instead of showing the raw ID.
 *   'company_via' => Optional. Most modules have a direct `company_id`
 *                    column (the default, safe to omit). A few models
 *                    (Milestone, GoodsReceipt) are scoped only through a
 *                    parent belongsTo relation (Project, MaterialRequest)
 *                    -- set this to that relation's method name so
 *                    AnalyticsService scopes via whereHas() instead of a
 *                    column that doesn't exist. Caught live via browser
 *                    verification (500: "Unknown column 'company_id'"
 *                    on `milestones`) rather than assumed -- see
 *                    docs/ADR/019-analytics-framework.md.
 */
return [
    'material_requests_by_status' => [
        'label' => 'Material Requests by Status',
        'model' => MaterialRequest::class,
        'group_by' => 'status',
        'chart' => 'pie',
        'module_key' => 'material_requests',
        'date_field' => 'created_at',
    ],
    'employees_by_department' => [
        'label' => 'Employees by Department',
        'model' => Employee::class,
        'group_by' => 'department_id',
        'chart' => 'bar',
        'module_key' => 'employees',
        'date_field' => null,
        // group_by is a foreign key -- resolve bucket values to readable
        // names instead of raw IDs (caught during browser verification:
        // the chart/table originally showed department_id numbers).
        'label_model' => Department::class,
    ],
    'employees_by_status' => [
        'label' => 'Employees by Status',
        'model' => Employee::class,
        'group_by' => 'status',
        'chart' => 'pie',
        'module_key' => 'employees',
        'date_field' => null,
    ],
    // Incident, Task, and LeaveRequest are not gated by a module toggle
    // today (see resources/js/lib/workspaces.js -- none of these three
    // carry a moduleKey; they're department/role-gated instead), so
    // their datasets use module_key => null, meaning "always visible,
    // no module gate" rather than a key that would never match and
    // silently hide a real dataset.
    'incidents_by_severity' => [
        'label' => 'Incidents by Severity',
        'model' => Incident::class,
        'group_by' => 'severity',
        'chart' => 'pie',
        'module_key' => null,
        'date_field' => 'created_at',
    ],
    'incidents_by_status' => [
        'label' => 'Incidents by Status',
        'model' => Incident::class,
        'group_by' => 'status',
        'chart' => 'bar',
        'module_key' => null,
        'date_field' => 'created_at',
    ],
    'tasks_by_status' => [
        'label' => 'Tasks by Status',
        'model' => Task::class,
        'group_by' => 'status',
        'chart' => 'bar',
        'module_key' => null,
        'date_field' => 'created_at',
    ],
    'leave_requests_by_status' => [
        'label' => 'Leave Requests by Status',
        'model' => LeaveRequest::class,
        'group_by' => 'status',
        'chart' => 'pie',
        'module_key' => null,
        'date_field' => 'created_at',
    ],
    'milestones_by_status' => [
        'label' => 'Milestones by Status',
        'model' => Milestone::class,
        'group_by' => 'status',
        'chart' => 'bar',
        'module_key' => 'projects',
        'date_field' => null,
        'company_via' => 'project',
    ],
    'goods_receipts_by_month' => [
        'label' => 'Goods Receipts Trend',
        'model' => GoodsReceipt::class,
        'group_by' => null,
        'chart' => 'line',
        'module_key' => null,
        'date_field' => 'created_at',
        'company_via' => 'materialRequest',
    ],
    'ppe_replacement_by_status' => [
        'label' => 'PPE Replacement Requests by Status',
        'model' => PpeReplacementRequest::class,
        'group_by' => 'status',
        'chart' => 'pie',
        'module_key' => 'ppe',
        'date_field' => 'created_at',
    ],
];
