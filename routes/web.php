<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HrDashboardController;
use App\Http\Controllers\HseDashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\KpiInputController;
use App\Http\Controllers\KpiRecordController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LogisticsDashboardController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\PpeController;
use App\Http\Controllers\PpeTypeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectManagementDashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WorkCenterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes (login)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated routes (all four roles: Super Admin, HSE, HRD, Manager)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    // Dashboard is the landing page (v1.9.0) -- Home was retired, its
    // unique real feeds folded into Dashboard/Index.jsx. `home` is kept
    // as a route NAME (redirecting to `dashboard`) purely so nothing that
    // still calls route('home') -- old bookmarks, external links to `/`
    // -- breaks; there is no HomeController/Home page anymore.
    Route::redirect('/', '/dashboard')->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Work Center (v1.8.0): the global cross-department "what needs my
    // attention" surface -- see WorkCenterController's own doc comment.
    Route::get('/work-center', [WorkCenterController::class, 'index'])->name('work-center.index');

    // Employees: viewable by all roles; mutation actions are policy-gated inside the controller/routes below.
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/export', [EmployeeController::class, 'export'])->name('employees.export');
    Route::get('/employees/import-template', [EmployeeController::class, 'importTemplate'])->name('employees.import-template');
    Route::post('/employees/import', [EmployeeController::class, 'import'])->name('employees.import');
    Route::post('/employees/import/preview', [EmployeeController::class, 'previewImport'])->name('employees.import.preview');
    Route::post('/employees/import/create-missing-and-import', [EmployeeController::class, 'createMissingMasterDataAndImport'])->name('employees.import.create-missing-and-import');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');

    // Reports: viewable + exportable by all roles per spec.
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/kpi-records', [KpiRecordController::class, 'index'])->name('kpi-records.index');
    Route::get('/search', [GlobalSearchController::class, 'search'])->name('search');
    Route::resource('tasks', TaskController::class);
    Route::get('/reports/export/excel', [ReportController::class, 'exportExcel'])->name('reports.export.excel');
    Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');

    // Projects: viewable by all roles (Manager + HRD are view-only here); mutation below is admin-scoped.
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

    // PPE: Master, Distribution/History, and Dashboard are all viewable by every role;
    // mutation routes below are permission-gated.
    Route::get('/ppe', [PpeController::class, 'index'])->name('ppe.index');
    Route::get('/ppe/employees', [PpeController::class, 'employees'])->name('ppe.employees');
    Route::get('/ppe/employees/{employee}', [PpeController::class, 'employeeProfile'])->name('ppe.employees.show');
    Route::get('/ppe/dashboard', [PpeController::class, 'dashboard'])->name('ppe.dashboard');
    Route::get('/ppe/master', [PpeController::class, 'master'])->name('ppe.master');
    Route::get('/ppe/search-employees', [PpeController::class, 'searchEmployees'])->name('ppe.search-employees');

    // Daily HSE Report: viewable by all roles; mutation below is admin-scoped.
    Route::get('/daily-reports', [DailyReportController::class, 'index'])->name('daily-reports.index');
    Route::get('/daily-reports/{dailyReport}', [DailyReportController::class, 'show'])->name('daily-reports.show');

    /*
    |--------------------------------------------------------------------------
    | Super Admin + HSE: Input KPI, Employee CRUD, Project management,
    | operational Settings (Departments/Positions).
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:super_admin,hse')->group(function () {
        Route::get('/employees-create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy');

        Route::get('/kpi-input', [KpiInputController::class, 'index'])->name('kpi-input.index');
        Route::get('/kpi-input/attendance-employees', [KpiInputController::class, 'employeesForAttendance'])->name('kpi-input.attendance-employees');
        Route::post('/kpi-input/single', [KpiInputController::class, 'storeSingle'])->name('kpi-input.single');
        Route::post('/kpi-input/quick-attendance', [KpiInputController::class, 'storeQuickAttendance'])->name('kpi-input.quick-attendance');

        Route::get('/projects-create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::post('/projects/{project}/manpower', [ProjectController::class, 'addManpower'])->name('projects.manpower.add');
        Route::delete('/projects/{project}/manpower/{employee}', [ProjectController::class, 'removeManpower'])->name('projects.manpower.remove');

        Route::post('/ppe', [PpeController::class, 'store'])->name('ppe.store');
        Route::put('/ppe/{employeePpe}', [PpeController::class, 'update'])->name('ppe.update');
        Route::post('/ppe/{employeePpe}/complete-replacement', [PpeController::class, 'completeReplacement'])->name('ppe.complete-replacement');
        Route::delete('/ppe/{employeePpe}', [PpeController::class, 'destroy'])->name('ppe.destroy');

        Route::get('/daily-reports-create', [DailyReportController::class, 'create'])->name('daily-reports.create');
        Route::post('/daily-reports', [DailyReportController::class, 'store'])->name('daily-reports.store');
        Route::get('/daily-reports/{dailyReport}/edit', [DailyReportController::class, 'edit'])->name('daily-reports.edit');
        Route::put('/daily-reports/{dailyReport}', [DailyReportController::class, 'update'])->name('daily-reports.update');
        Route::delete('/daily-reports/{dailyReport}/photos/{photo}', [DailyReportController::class, 'destroyPhoto'])->name('daily-reports.photos.destroy');
        Route::delete('/daily-reports/{dailyReport}', [DailyReportController::class, 'destroy'])->name('daily-reports.destroy');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

        Route::post('/settings/departments', [SettingsController::class, 'storeDepartment'])->name('settings.departments.store');
        Route::put('/settings/departments/{department}', [SettingsController::class, 'updateDepartment'])->name('settings.departments.update');
        Route::delete('/settings/departments/{department}', [SettingsController::class, 'destroyDepartment'])->name('settings.departments.destroy');

        Route::post('/settings/positions', [SettingsController::class, 'storePosition'])->name('settings.positions.store');
        Route::put('/settings/positions/{position}', [SettingsController::class, 'updatePosition'])->name('settings.positions.update');
        Route::delete('/settings/positions/{position}', [SettingsController::class, 'destroyPosition'])->name('settings.positions.destroy');

        Route::post('/settings/kpi-categories', [SettingsController::class, 'storeKpiCategory'])->name('settings.kpi-categories.store');
        Route::put('/settings/kpi-categories/{kpiCategory}', [SettingsController::class, 'updateKpiCategory'])->name('settings.kpi-categories.update');
        Route::delete('/settings/kpi-categories/{kpiCategory}', [SettingsController::class, 'destroyKpiCategory'])->name('settings.kpi-categories.destroy');

        // Self-service credential change: both Super Admin and HSE can
        // change their OWN email/password (not other users' -- that's the
        // separate, Super-Admin-only User Management below).
        Route::post('/settings/authentication', [SettingsController::class, 'updateAuthentication'])->name('settings.authentication');
    });

    /*
    |--------------------------------------------------------------------------
    | Super Admin only: Company management, User management, company
    | branding, backup/restore.
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:super_admin')->group(function () {
        Route::post('/settings/company', [SettingsController::class, 'updateCompany'])->name('settings.company');
        Route::post('/settings/modules', [SettingsController::class, 'updateModules'])->name('settings.modules');

        Route::post('/ppe-types', [PpeTypeController::class, 'store'])->name('ppe-types.store');
        Route::put('/ppe-types/{ppeType}', [PpeTypeController::class, 'update'])->name('ppe-types.update');
        Route::delete('/ppe-types/{ppeType}', [PpeTypeController::class, 'destroy'])->name('ppe-types.destroy');

        Route::post('/settings/companies', [SettingsController::class, 'storeCompanyEntity'])->name('settings.companies.store');
        Route::put('/settings/companies/{company}', [SettingsController::class, 'updateCompanyEntity'])->name('settings.companies.update');
        Route::delete('/settings/companies/{company}', [SettingsController::class, 'destroyCompanyEntity'])->name('settings.companies.destroy');

        Route::post('/settings/users', [SettingsController::class, 'storeUser'])->name('settings.users.store');
        Route::put('/settings/users/{user}', [SettingsController::class, 'updateUser'])->name('settings.users.update');
        Route::delete('/settings/users/{user}', [SettingsController::class, 'destroyUser'])->name('settings.users.destroy');

        Route::get('/settings/backup', [SettingsController::class, 'backupDatabase'])->name('settings.backup');
        Route::post('/settings/restore', [SettingsController::class, 'restoreDatabase'])->name('settings.restore');
    });

    // v1.6.8 fix: these were previously (incorrectly) nested inside the
    // role:super_admin group above, meaning every non-super-admin user
    // (including HSE, the actual intended user base for both of these
    // modules) got a 403 on every single route -- this is very likely
    // the real reason "the application does not display the Material
    // Request module" despite every other piece (sidebar, config
    // registration, controller, pages) being correctly in place. Real
    // authorization already happens inside the controllers/pages via
    // canManageMaterialRequests()/canManagePpeDistribution(); these
    // routes were never meant to be role-restricted at the routing
    // layer on top of that.
    Route::get('/material-requests', [MaterialRequestController::class, 'index'])->name('material-requests.index');
    Route::get('/material-requests/create', [MaterialRequestController::class, 'create'])->name('material-requests.create');
    Route::post('/material-requests', [MaterialRequestController::class, 'store'])->name('material-requests.store');
    Route::get('/material-requests/{materialRequest}', [MaterialRequestController::class, 'show'])->name('material-requests.show');
    Route::get('/material-requests/{materialRequest}/edit', [MaterialRequestController::class, 'edit'])->name('material-requests.edit');
    Route::put('/material-requests/{materialRequest}', [MaterialRequestController::class, 'update'])->name('material-requests.update');
    Route::delete('/material-requests/{materialRequest}', [MaterialRequestController::class, 'destroy'])->name('material-requests.destroy');
    Route::get('/material-requests/{materialRequest}/pdf', [MaterialRequestController::class, 'pdf'])->name('material-requests.pdf');
    Route::post('/material-requests/{materialRequest}/process', [MaterialRequestController::class, 'process'])->name('material-requests.process');
    Route::post('/material-requests/{materialRequest}/complete', [MaterialRequestController::class, 'complete'])->name('material-requests.complete');
    Route::post('/material-requests/{materialRequest}/reopen', [MaterialRequestController::class, 'reopen'])->name('material-requests.reopen');
    Route::post('/material-requests/{materialRequest}/cancel', [MaterialRequestController::class, 'cancel'])->name('material-requests.cancel');

    // Universal Approval Engine (v1.6.9) -- generic, not scoped under
    // /material-requests, since these two routes work against any
    // approvable model via Approval's own polymorphic relationship.
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('/ppe/replacement-due', [PpeController::class, 'replacementDue'])->name('ppe.replacement-due');
    Route::post('/ppe/replacement-requests', [PpeController::class, 'storeReplacementRequest'])->name('ppe.replacement-requests.store');
    Route::get('/ppe/replacement-requests', [PpeController::class, 'replacementRequestsIndex'])->name('ppe.replacement-requests.index');
    Route::get('/ppe/replacement-requests/{replacementRequest}', [PpeController::class, 'showReplacementRequest'])->name('ppe.replacement-requests.show');
    Route::get('/ppe/replacement-requests/{replacementRequest}/pdf', [PpeController::class, 'replacementRequestPdf'])->name('ppe.replacement-requests.pdf');

    /*
    |----------------------------------------------------------------
    | Core Departments build-out (v1.10.0) -- one real module each
    | for HR/HSE/Project Management/Logistics, plus each department's
    | own Dashboard. Not role-restricted at the routing layer (same
    | v1.6.8 fix noted above) -- authorization happens in controllers.
    |----------------------------------------------------------------
    */
    Route::get('/hr/dashboard', [HrDashboardController::class, 'index'])->name('hr.dashboard');
    Route::get('/leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::get('/leave-requests/create', [LeaveRequestController::class, 'create'])->name('leave-requests.create');
    Route::post('/leave-requests', [LeaveRequestController::class, 'store'])->name('leave-requests.store');
    Route::get('/leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->name('leave-requests.show');
    Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');

    Route::get('/hse/dashboard', [HseDashboardController::class, 'index'])->name('hse.dashboard');
    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/incidents/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
    Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('/incidents/{incident}/transition', [IncidentController::class, 'transition'])->name('incidents.transition');

    Route::get('/project-management/dashboard', [ProjectManagementDashboardController::class, 'index'])->name('project-management.dashboard');
    Route::get('/milestones', [MilestoneController::class, 'index'])->name('milestones.index');
    Route::post('/milestones', [MilestoneController::class, 'store'])->name('milestones.store');
    Route::put('/milestones/{milestone}', [MilestoneController::class, 'update'])->name('milestones.update');
    Route::delete('/milestones/{milestone}', [MilestoneController::class, 'destroy'])->name('milestones.destroy');

    Route::get('/logistics/dashboard', [LogisticsDashboardController::class, 'index'])->name('logistics.dashboard');
    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
    Route::get('/goods-receipts/create', [GoodsReceiptController::class, 'create'])->name('goods-receipts.create');
    Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
    Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('goods-receipts.show');

    // Future Departments (v1.9.0/v1.10.0): Warehouse, Procurement, Asset
    // Management, Maintenance, Quality Control, Finance -- kept visible in
    // the Department selector, no sidebar build-out yet. One shared
    // controller/page, but a DISTINCT route name per department (not one
    // shared `coming-soon.show`) -- workspaces.js's active-department
    // detection keys off the route-name prefix (the segment before the
    // first `.`), so six items sharing one route name would all collide
    // onto whichever department happened to be last in that lookup.
    // Naming each route `{department-key}.coming-soon` keeps every
    // department's prefix unique and matching its own workspace `key`,
    // the same convention every other department's routes already follow.
    foreach (['warehouse', 'procurement', 'asset-management', 'maintenance', 'quality-control', 'finance'] as $futureDepartment) {
        Route::get("/{$futureDepartment}/coming-soon", [ComingSoonController::class, 'show'])
            ->defaults('department', $futureDepartment)
            ->name("{$futureDepartment}.coming-soon");
    }
});
