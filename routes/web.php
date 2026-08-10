<?php

use App\Http\Controllers\ActivityCenterController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\CompetencyTypeController;
use App\Http\Controllers\CorrectiveActionController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeCompetencyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeRosterController;
use App\Http\Controllers\EmployeeShiftAssignmentController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GasTestRecordController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HazardCategoryController;
use App\Http\Controllers\HseMaterialController;
use App\Http\Controllers\HrDashboardController;
use App\Http\Controllers\HseDashboardController;
use App\Http\Controllers\HseInspectionController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\JobSafetyAnalysisController;
use App\Http\Controllers\KpiInputController;
use App\Http\Controllers\KpiRecordController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LogisticsDashboardController;
use App\Http\Controllers\LotoRecordController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\P3kBoxController;
use App\Http\Controllers\PermitToWorkController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\RfqController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\SafetyEquipmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PpeController;
use App\Http\Controllers\PpeTypeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectManagementDashboardController;
use App\Http\Controllers\ReportCenterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\RosterPatternController;
use App\Http\Controllers\SafetyObservationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TbmMeetingController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorQuotationController;
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
// M3 FINAL verification (Task #70): 'restrict.platform-admin' redirects a
// Platform Super Admin (no tenant) to /platform -- see that middleware's
// own doc comment for the bug this closes.
Route::middleware(['auth', 'restrict.platform-admin'])->group(function () {

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

    // Analytics Framework (Milestone 3, Task #64) -- index() renders every
    // dataset visible to the current tenant's enabled modules; show()
    // returns a single dataset as JSON for dashboard widgets to fetch.
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/analytics/{key}', [AnalyticsController::class, 'show'])->name('analytics.show');

    // Notification Center (Milestone 3) -- the list itself is shared via
    // HandleInertiaRequests, these are just the two write actions.
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

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

    // Report Center (Milestone 3, Task #65) -- generic PDF/Excel/CSV
    // download + Scheduled Report over any Analytics Framework dataset.
    Route::get('/report-center', [ReportCenterController::class, 'index'])->name('report-center.index');
    Route::get('/report-center/{key}/preview', [ReportCenterController::class, 'preview'])->name('report-center.preview');
    Route::get('/report-center/{key}/export/csv', [ReportCenterController::class, 'exportCsv'])->name('report-center.export.csv');
    Route::get('/report-center/{key}/export/excel', [ReportCenterController::class, 'exportExcel'])->name('report-center.export.excel');
    Route::get('/report-center/{key}/export/pdf', [ReportCenterController::class, 'exportPdf'])->name('report-center.export.pdf');
    Route::post('/report-center/schedules', [ReportCenterController::class, 'storeSchedule'])->name('report-center.schedules.store');
    Route::delete('/report-center/schedules/{reportSchedule}', [ReportCenterController::class, 'destroySchedule'])->name('report-center.schedules.destroy');

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

    // Competency & Certification (Milestone 4, Workstream A2): viewable by
    // all roles, matching PPE's own viewable-by-all / mutation-gated split.
    Route::get('/competency/master', [CompetencyController::class, 'master'])->name('competency.master');
    Route::get('/competency/expiring', [CompetencyController::class, 'expiringSoon'])->name('competency.expiring-soon');

    // Shift & Roster Management (Milestone 4, Workstream A3): viewable by
    // all roles, matching PPE/Competency's own viewable-by-all pattern.
    Route::get('/shifts/master', [ShiftController::class, 'master'])->name('shifts.master');
    Route::get('/rosters/overview', [RosterController::class, 'overview'])->name('rosters.overview');

    // HSE Master Data (Milestone 4, Workstream B0): viewable by all roles,
    // same pattern.
    Route::get('/hse/master', [HazardCategoryController::class, 'master'])->name('hse.master');

    // Safety Observation (Milestone 4, Workstream B1): list/detail viewable
    // by all roles; create/store/transition are canManageSafetyObservations()
    // gated inside the controller, same shape as Incident.
    Route::get('/safety-observations', [SafetyObservationController::class, 'index'])->name('safety-observations.index');
    Route::get('/safety-observations/create', [SafetyObservationController::class, 'create'])->name('safety-observations.create');
    Route::post('/safety-observations', [SafetyObservationController::class, 'store'])->name('safety-observations.store');
    Route::get('/safety-observations/{safetyObservation}', [SafetyObservationController::class, 'show'])->name('safety-observations.show');
    Route::post('/safety-observations/{safetyObservation}/transition', [SafetyObservationController::class, 'transition'])->name('safety-observations.transition');
    Route::delete('/safety-observations/{safetyObservation}/photos/{photo}', [SafetyObservationController::class, 'destroyPhoto'])->name('safety-observations.photos.destroy');

    // HIRADC / Risk Assessment (Milestone 4, Workstream B4): list/detail
    // viewable by all roles; write actions gated by canManageHse() inside
    // the controller, same shape as Safety Observation.
    Route::get('/risk-assessments', [RiskAssessmentController::class, 'index'])->name('risk-assessments.index');
    Route::get('/risk-assessments/create', [RiskAssessmentController::class, 'create'])->name('risk-assessments.create');
    Route::post('/risk-assessments', [RiskAssessmentController::class, 'store'])->name('risk-assessments.store');
    Route::get('/risk-assessments/{riskAssessment}/edit', [RiskAssessmentController::class, 'edit'])->name('risk-assessments.edit');
    Route::put('/risk-assessments/{riskAssessment}', [RiskAssessmentController::class, 'update'])->name('risk-assessments.update');
    Route::get('/risk-assessments/{riskAssessment}', [RiskAssessmentController::class, 'show'])->name('risk-assessments.show');
    Route::post('/risk-assessments/{riskAssessment}/transition', [RiskAssessmentController::class, 'transition'])->name('risk-assessments.transition');

    // JSA (Milestone 4, Workstream B5): same pattern.
    Route::get('/job-safety-analyses', [JobSafetyAnalysisController::class, 'index'])->name('job-safety-analyses.index');
    Route::get('/job-safety-analyses/create', [JobSafetyAnalysisController::class, 'create'])->name('job-safety-analyses.create');
    Route::post('/job-safety-analyses', [JobSafetyAnalysisController::class, 'store'])->name('job-safety-analyses.store');
    Route::get('/job-safety-analyses/{jobSafetyAnalysis}/edit', [JobSafetyAnalysisController::class, 'edit'])->name('job-safety-analyses.edit');
    Route::put('/job-safety-analyses/{jobSafetyAnalysis}', [JobSafetyAnalysisController::class, 'update'])->name('job-safety-analyses.update');
    Route::get('/job-safety-analyses/{jobSafetyAnalysis}', [JobSafetyAnalysisController::class, 'show'])->name('job-safety-analyses.show');
    Route::post('/job-safety-analyses/{jobSafetyAnalysis}/transition', [JobSafetyAnalysisController::class, 'transition'])->name('job-safety-analyses.transition');

    // Permit To Work + Gas Test + LOTO (Milestone 4, Workstream B6/B7/B8).
    Route::get('/permits-to-work', [PermitToWorkController::class, 'index'])->name('permits-to-work.index');
    Route::get('/permits-to-work/create', [PermitToWorkController::class, 'create'])->name('permits-to-work.create');
    Route::post('/permits-to-work', [PermitToWorkController::class, 'store'])->name('permits-to-work.store');
    Route::get('/permits-to-work/{permitToWork}', [PermitToWorkController::class, 'show'])->name('permits-to-work.show');
    Route::post('/permits-to-work/{permitToWork}/transition', [PermitToWorkController::class, 'transition'])->name('permits-to-work.transition');
    Route::post('/permits-to-work/{permitToWork}/gas-tests', [GasTestRecordController::class, 'store'])->name('permits-to-work.gas-tests.store');
    Route::delete('/permits-to-work/{permitToWork}/gas-tests/{gasTest}', [GasTestRecordController::class, 'destroy'])->name('permits-to-work.gas-tests.destroy');

    Route::get('/loto-records', [LotoRecordController::class, 'index'])->name('loto-records.index');
    Route::get('/loto-records/create', [LotoRecordController::class, 'create'])->name('loto-records.create');
    Route::post('/loto-records', [LotoRecordController::class, 'store'])->name('loto-records.store');
    Route::post('/loto-records/{lotoRecord}/release', [LotoRecordController::class, 'release'])->name('loto-records.release');

    // TBM / Toolbox Meeting (Milestone 4, Workstream B3).
    Route::get('/tbm-meetings', [TbmMeetingController::class, 'index'])->name('tbm-meetings.index');
    Route::get('/tbm-meetings/create', [TbmMeetingController::class, 'create'])->name('tbm-meetings.create');
    Route::post('/tbm-meetings', [TbmMeetingController::class, 'store'])->name('tbm-meetings.store');
    Route::get('/tbm-meetings/{tbmMeeting}', [TbmMeetingController::class, 'show'])->name('tbm-meetings.show');

    // HSE Inspection (Milestone 4, Workstream B2).
    Route::get('/hse-inspections', [HseInspectionController::class, 'index'])->name('hse-inspections.index');
    Route::get('/hse-inspections/create', [HseInspectionController::class, 'create'])->name('hse-inspections.create');
    Route::post('/hse-inspections', [HseInspectionController::class, 'store'])->name('hse-inspections.store');
    Route::get('/hse-inspections/{hseInspection}', [HseInspectionController::class, 'show'])->name('hse-inspections.show');
    Route::post('/hse-inspections/{hseInspection}/raise-finding', [HseInspectionController::class, 'raiseFinding'])->name('hse-inspections.raise-finding');

    // Corrective Actions / CAPA (Milestone 4, Workstream B15) -- standalone
    // cross-source view over the SAME rows Safety Observation/HSE
    // Inspection/Incident already create.
    Route::get('/corrective-actions', [CorrectiveActionController::class, 'index'])->name('corrective-actions.index');
    Route::post('/corrective-actions/{correctiveAction}/status', [CorrectiveActionController::class, 'updateStatus'])->name('corrective-actions.update-status');

    // Vendor / Supplier Master (Milestone 4, Workstream C1): list/detail
    // viewable by all roles; write actions gated by canManageProcurement()
    // inside the controller, same shape as Safety Observation/HSE.
    Route::get('/vendors', [VendorController::class, 'index'])->name('vendors.index');
    Route::get('/vendors/create', [VendorController::class, 'create'])->name('vendors.create');
    Route::post('/vendors', [VendorController::class, 'store'])->name('vendors.store');
    Route::get('/vendors/{vendor}/edit', [VendorController::class, 'edit'])->name('vendors.edit');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');
    Route::get('/vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
    Route::post('/vendors/{vendor}/qualification', [VendorController::class, 'reviewQualification'])->name('vendors.qualification');
    Route::post('/vendors/{vendor}/documents', [VendorController::class, 'storeDocument'])->name('vendors.documents.store');
    Route::delete('/vendors/{vendor}/documents/{document}', [VendorController::class, 'destroyDocument'])->name('vendors.documents.destroy');

    // Purchase Requisition (Milestone 4, Workstream C2): create/submit
    // gated to canManageProcurement() inside the controller; review/
    // approve/reject/cancel gated to config('workflow.approvers'/
    // 'overriders') -- same segregation-of-duties split as
    // MaterialRequestController.
    Route::get('/purchase-requisitions', [PurchaseRequisitionController::class, 'index'])->name('purchase-requisitions.index');
    Route::get('/purchase-requisitions/create', [PurchaseRequisitionController::class, 'create'])->name('purchase-requisitions.create');
    Route::post('/purchase-requisitions', [PurchaseRequisitionController::class, 'store'])->name('purchase-requisitions.store');
    Route::get('/purchase-requisitions/{purchaseRequisition}/edit', [PurchaseRequisitionController::class, 'edit'])->name('purchase-requisitions.edit');
    Route::put('/purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'update'])->name('purchase-requisitions.update');
    Route::get('/purchase-requisitions/{purchaseRequisition}', [PurchaseRequisitionController::class, 'show'])->name('purchase-requisitions.show');
    Route::post('/purchase-requisitions/{purchaseRequisition}/submit', [PurchaseRequisitionController::class, 'submit'])->name('purchase-requisitions.submit');
    Route::post('/purchase-requisitions/{purchaseRequisition}/start-review', [PurchaseRequisitionController::class, 'startReview'])->name('purchase-requisitions.start-review');
    Route::post('/purchase-requisitions/{purchaseRequisition}/approve', [PurchaseRequisitionController::class, 'approve'])->name('purchase-requisitions.approve');
    Route::post('/purchase-requisitions/{purchaseRequisition}/reject', [PurchaseRequisitionController::class, 'reject'])->name('purchase-requisitions.reject');
    Route::post('/purchase-requisitions/{purchaseRequisition}/cancel', [PurchaseRequisitionController::class, 'cancel'])->name('purchase-requisitions.cancel');

    // RFQ + Vendor Quotation (Milestone 4, Workstream C3).
    Route::get('/rfqs', [RfqController::class, 'index'])->name('rfqs.index');
    Route::get('/rfqs/create', [RfqController::class, 'create'])->name('rfqs.create');
    Route::post('/rfqs', [RfqController::class, 'store'])->name('rfqs.store');
    Route::get('/rfqs/{rfq}', [RfqController::class, 'show'])->name('rfqs.show');
    Route::post('/rfqs/{rfq}/close', [RfqController::class, 'close'])->name('rfqs.close');
    Route::post('/rfqs/{rfq}/select-vendor', [RfqController::class, 'selectVendor'])->name('rfqs.select-vendor');
    Route::post('/rfqs/{rfq}/quotations', [VendorQuotationController::class, 'store'])->name('rfqs.quotations.store');
    Route::delete('/rfqs/{rfq}/quotations/{quotation}', [VendorQuotationController::class, 'destroy'])->name('rfqs.quotations.destroy');

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

        Route::post('/competency-types', [CompetencyTypeController::class, 'store'])->name('competency-types.store');
        Route::put('/competency-types/{competencyType}', [CompetencyTypeController::class, 'update'])->name('competency-types.update');
        Route::delete('/competency-types/{competencyType}', [CompetencyTypeController::class, 'destroy'])->name('competency-types.destroy');

        Route::post('/employees/{employee}/competencies', [EmployeeCompetencyController::class, 'store'])->name('employees.competencies.store');
        Route::put('/employee-competencies/{employeeCompetency}', [EmployeeCompetencyController::class, 'update'])->name('employee-competencies.update');
        Route::delete('/employee-competencies/{employeeCompetency}', [EmployeeCompetencyController::class, 'destroy'])->name('employee-competencies.destroy');

        Route::post('/shifts', [ShiftController::class, 'store'])->name('shifts.store');
        Route::put('/shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
        Route::delete('/shifts/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');

        Route::post('/roster-patterns', [RosterPatternController::class, 'store'])->name('roster-patterns.store');
        Route::put('/roster-patterns/{rosterPattern}', [RosterPatternController::class, 'update'])->name('roster-patterns.update');
        Route::delete('/roster-patterns/{rosterPattern}', [RosterPatternController::class, 'destroy'])->name('roster-patterns.destroy');

        Route::post('/employees/{employee}/shift-assignments', [EmployeeShiftAssignmentController::class, 'store'])->name('employees.shift-assignments.store');
        Route::put('/employee-shift-assignments/{employeeShiftAssignment}', [EmployeeShiftAssignmentController::class, 'update'])->name('employee-shift-assignments.update');
        Route::delete('/employee-shift-assignments/{employeeShiftAssignment}', [EmployeeShiftAssignmentController::class, 'destroy'])->name('employee-shift-assignments.destroy');

        Route::post('/employees/{employee}/rosters', [EmployeeRosterController::class, 'store'])->name('employees.rosters.store');
        Route::put('/employee-rosters/{employeeRoster}', [EmployeeRosterController::class, 'update'])->name('employee-rosters.update');
        Route::delete('/employee-rosters/{employeeRoster}', [EmployeeRosterController::class, 'destroy'])->name('employee-rosters.destroy');

        Route::post('/hazard-categories', [HazardCategoryController::class, 'store'])->name('hazard-categories.store');
        Route::put('/hazard-categories/{hazardCategory}', [HazardCategoryController::class, 'update'])->name('hazard-categories.update');
        Route::delete('/hazard-categories/{hazardCategory}', [HazardCategoryController::class, 'destroy'])->name('hazard-categories.destroy');

        Route::post('/safety-equipment', [SafetyEquipmentController::class, 'store'])->name('safety-equipment.store');
        Route::put('/safety-equipment/{safetyEquipment}', [SafetyEquipmentController::class, 'update'])->name('safety-equipment.update');
        Route::delete('/safety-equipment/{safetyEquipment}', [SafetyEquipmentController::class, 'destroy'])->name('safety-equipment.destroy');

        Route::post('/hse-materials', [HseMaterialController::class, 'store'])->name('hse-materials.store');
        Route::put('/hse-materials/{hseMaterial}', [HseMaterialController::class, 'update'])->name('hse-materials.update');
        Route::delete('/hse-materials/{hseMaterial}', [HseMaterialController::class, 'destroy'])->name('hse-materials.destroy');

        Route::post('/p3k-boxes', [P3kBoxController::class, 'store'])->name('p3k-boxes.store');
        Route::put('/p3k-boxes/{p3kBox}', [P3kBoxController::class, 'update'])->name('p3k-boxes.update');
        Route::delete('/p3k-boxes/{p3kBox}', [P3kBoxController::class, 'destroy'])->name('p3k-boxes.destroy');

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
        // Activity Center (Milestone 3, Task #50) -- was a disabled
        // "Audit Logs" placeholder in the Administration workspace since
        // v1.9.0; this is that placeholder becoming real.
        Route::get('/activity-center', [ActivityCenterController::class, 'index'])->name('activity-center.index');

        Route::post('/settings/company', [SettingsController::class, 'updateCompany'])->name('settings.company');
        Route::post('/settings/modules', [SettingsController::class, 'updateModules'])->name('settings.modules');
        Route::post('/settings/workspaces', [SettingsController::class, 'updateWorkspaces'])->name('settings.workspaces');
        Route::put('/settings/roles/{role}', [SettingsController::class, 'updateRolePermissions'])->name('settings.roles.update');
        Route::post('/settings/roles', [SettingsController::class, 'storeRole'])->name('settings.roles.store');
        Route::delete('/settings/roles/{role}', [SettingsController::class, 'destroyRole'])->name('settings.roles.destroy');
        Route::put('/settings/users/{user}/roles', [SettingsController::class, 'updateUserRoles'])->name('settings.users.roles');
        Route::post('/settings/numbering', [SettingsController::class, 'updateNumberingFormats'])->name('settings.numbering');
        Route::post('/settings/approval-flows', [SettingsController::class, 'storeApprovalFlow'])->name('settings.approval-flows.store');
        Route::delete('/settings/approval-flows/{approvalFlow}', [SettingsController::class, 'destroyApprovalFlow'])->name('settings.approval-flows.destroy');
        Route::put('/settings/approval-flows/{approvalFlow}/steps', [SettingsController::class, 'updateApprovalFlowSteps'])->name('settings.approval-flows.steps');
        Route::post('/settings/notifications', [SettingsController::class, 'updateNotificationPreferences'])->name('settings.notifications');

        // Dynamic Document Engine (Milestone 3, Task #66).
        Route::post('/settings/documents', [SettingsController::class, 'storeDocumentTemplate'])->name('settings.documents.store');
        Route::put('/settings/documents/{documentTemplate}', [SettingsController::class, 'updateDocumentTemplate'])->name('settings.documents.update');
        Route::delete('/settings/documents/{documentTemplate}', [SettingsController::class, 'destroyDocumentTemplate'])->name('settings.documents.destroy');

        // Import/Export Mapping (Milestone 3, Task #67).
        Route::post('/settings/field-mapping', [SettingsController::class, 'updateFieldMapping'])->name('settings.field-mapping');

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
    Route::post('/incidents/{incident}/investigation', [IncidentController::class, 'storeInvestigation'])->name('incidents.investigation.store');
    Route::post('/incidents/{incident}/raise-finding', [IncidentController::class, 'raiseFinding'])->name('incidents.raise-finding');

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

/*
|--------------------------------------------------------------------------
| Platform Super Admin (Milestone 2, Task #44)
|--------------------------------------------------------------------------
|
| A SEPARATE surface from everything above -- reachable only by
| role:platform_admin (see User::ROLE_PLATFORM_ADMIN,
| RolePermissionSeeder). Deliberately its own top-level group, not nested
| inside the tenant-side `auth` group above: a Platform Super Admin has
| no tenant (User::isPlatformAdmin()) and none of that group's
| department/module/workspace navigation concepts apply to them. See
| docs/ADR/008-tenancy-foundation.md.
*/
Route::middleware(['auth', 'role:platform_admin'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('/', [PlatformController::class, 'dashboard'])->name('dashboard');
    Route::get('/tenants', [PlatformController::class, 'tenants'])->name('tenants');
    Route::post('/tenants', [PlatformController::class, 'storeTenant'])->name('tenants.store');
    Route::get('/tenants/{tenant}', [PlatformController::class, 'show'])->name('tenants.show');
    Route::put('/tenants/{tenant}', [PlatformController::class, 'updateTenant'])->name('tenants.update');
    Route::put('/tenants/{tenant}/status', [PlatformController::class, 'updateTenantStatus'])->name('tenants.update-status');
    Route::get('/tenants/{tenant}/grants', [PlatformController::class, 'tenantGrants'])->name('tenants.grants');
    Route::put('/tenants/{tenant}/grants', [PlatformController::class, 'updateTenantGrants'])->name('tenants.grants.update');
});
