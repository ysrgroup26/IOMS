<?php

use App\Http\Controllers\ActivityCenterController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetDashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\HseChecklistTemplateController;
use App\Http\Controllers\HseEquipmentTypeController;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\ContractorController;
use App\Http\Controllers\ControlledDocumentController;
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
use App\Http\Controllers\InspectionRequestController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\JobSafetyAnalysisController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockTransactionController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseDashboardController;
use App\Http\Controllers\KpiInputController;
use App\Http\Controllers\KpiRecordController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\ManHourController;
use App\Http\Controllers\LogisticsDashboardController;
use App\Http\Controllers\LotoRecordController;
use App\Http\Controllers\MaintenanceDashboardController;
use App\Http\Controllers\MaintenanceRequestController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\NcrController;
use App\Http\Controllers\P3kBoxController;
use App\Http\Controllers\PermitToWorkController;
use App\Http\Controllers\ProcurementDashboardController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\RfqController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\SafetyEquipmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PpeController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\PpeTypeController;
use App\Http\Controllers\ProjectActivityController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectManagementDashboardController;
use App\Http\Controllers\QualityControlDashboardController;
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
use App\Http\Controllers\VendorPerformanceController;
use App\Http\Controllers\VendorQuotationController;
use App\Http\Controllers\VisitorController;
use App\Http\Controllers\WasteContainerController;
use App\Http\Controllers\WasteDashboardController;
use App\Http\Controllers\WasteMasterController;
use App\Http\Controllers\WasteMovementController;
use App\Http\Controllers\WasteRecordController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkCenterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website (v2.18.0, Public Website / Landing Page Foundation)
|--------------------------------------------------------------------------
| The root URL, reachable by anyone -- no `auth` or `guest` middleware.
| Previously `/` sat INSIDE the `auth` group as a bare
| `Route::redirect('/', '/dashboard')` (see the comment that used to live
| there, moved below), which meant an anonymous visitor was redirected
| straight to `/login` by Laravel's own unauthenticated-request handling
| before ever seeing anything -- exactly the behavior this phase's own
| directive says must stop. `PublicController::home()` now branches
| itself: an authenticated user (tenant OR Platform Admin) is redirected
| into the app exactly as before; a guest sees the public marketing page.
| The `home` route NAME is preserved (nothing else in this codebase calls
| it, confirmed by a whole-codebase grep, but kept for the same
| backward-compatibility reason the old redirect route comment gave).
*/
Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/privacy', [PublicController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [PublicController::class, 'terms'])->name('legal.terms');

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

    // v2.18.0: the bare `Route::redirect('/', '/dashboard')->name('home')`
    // that used to live here moved to `PublicController::home()` above --
    // `/` is no longer inside this auth-required group at all, since an
    // anonymous visitor must now see the public website, not get bounced
    // to /login. Dashboard remains the landing page for an authenticated
    // tenant user once they DO sign in (unchanged).
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Work Center (v1.8.0): the global cross-department "what needs my
    // attention" surface -- see WorkCenterController's own doc comment.
    Route::get('/work-center', [WorkCenterController::class, 'index'])->name('work-center.index');

    // Global Calendar (v1.11.0, SaaS Finalization Pass) -- see
    // CalendarController's own doc comment.
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::post('/calendar', [CalendarController::class, 'store'])->name('calendar.store');
    Route::put('/calendar/{calendarEvent}', [CalendarController::class, 'update'])->name('calendar.update');
    Route::delete('/calendar/{calendarEvent}', [CalendarController::class, 'destroy'])->name('calendar.destroy');

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

    // v1.11.4 (HSE Waste Management, Part 11-19). View routes open to
    // every role, same as every other HSE module above -- write
    // operations are gated inside each controller via canManageHse(),
    // identical shape to Safety Observation/Incident. `config/departments.php`
    // maps every `waste*` prefix below to `hse`.
    Route::get('/hse/waste/master', [WasteMasterController::class, 'master'])->name('waste.master');
    Route::get('/hse/waste/dashboard', [WasteDashboardController::class, 'index'])->name('waste.dashboard');
    // v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 7/11) --
    // Waste Container Inventory: physical container/equipment stock
    // (drums, IBC tanks, jumbo bags), separate from Waste Records
    // (actual waste material). Read route grouped with the other GET
    // waste.* routes above; mutation routes below, same
    // role:super_admin,hse group as waste-types/waste-storage-locations.
    Route::get('/waste-containers', [WasteContainerController::class, 'index'])->name('waste-containers.index');
    Route::get('/waste-records', [WasteRecordController::class, 'index'])->name('waste-records.index');
    Route::get('/waste-records/create', [WasteRecordController::class, 'create'])->name('waste-records.create');
    Route::post('/waste-records', [WasteRecordController::class, 'store'])->name('waste-records.store');
    Route::get('/waste-records/{wasteRecord}', [WasteRecordController::class, 'show'])->name('waste-records.show');
    Route::post('/waste-records/{wasteRecord}/transition', [WasteRecordController::class, 'transition'])->name('waste-records.transition');
    Route::post('/waste-records/{wasteRecord}/movements', [WasteMovementController::class, 'store'])->name('waste-movements.store');

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
    // v2.9.0 (Field/Foreman Experience pass, Phase 3C -- My PTW). MUST
    // stay registered before the `/permits-to-work/{permitToWork}` show
    // route directly below -- otherwise Laravel's route-model-binding
    // would try to resolve the literal segment "mine" as a PermitToWork
    // ID and 404 instead of reaching this controller method.
    Route::get('/permits-to-work/mine', [PermitToWorkController::class, 'myIndex'])->name('permits-to-work.mine');
    Route::post('/permits-to-work', [PermitToWorkController::class, 'store'])->name('permits-to-work.store');
    Route::get('/permits-to-work/{permitToWork}', [PermitToWorkController::class, 'show'])->name('permits-to-work.show');
    // v2.4.0 (PTW UX + Field Operations pass, Part 13) -- PTW PDF
    // document, see PermitToWorkController::pdf()'s own doc comment.
    Route::get('/permits-to-work/{permitToWork}/pdf', [PermitToWorkController::class, 'pdf'])->name('permits-to-work.pdf');
    // v2.6.0 (PTW Document View pass) -- in-browser document
    // presentation, see PermitToWorkController::document()'s own doc
    // comment for why this is separate from both `show` and `pdf`.
    Route::get('/permits-to-work/{permitToWork}/document', [PermitToWorkController::class, 'document'])->name('permits-to-work.document');
    Route::post('/permits-to-work/{permitToWork}/transition', [PermitToWorkController::class, 'transition'])->name('permits-to-work.transition');
    Route::post('/permits-to-work/{permitToWork}/gas-tests', [GasTestRecordController::class, 'store'])->name('permits-to-work.gas-tests.store');
    Route::delete('/permits-to-work/{permitToWork}/gas-tests/{gasTest}', [GasTestRecordController::class, 'destroy'])->name('permits-to-work.gas-tests.destroy');
    // v1.10.7: read-only, cross-permit list -- creation/deletion stays
    // nested under the owning PermitToWork above (see
    // GasTestRecordController's own doc comment for why).
    Route::get('/gas-test-records', [GasTestRecordController::class, 'index'])->name('gas-test-records.index');

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

    // Purchase Order (Milestone 4, Workstream C4): same segregation-of-
    // duties split as Purchase Requisition.
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
    Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->name('purchase-orders.reject');
    Route::post('/purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->name('purchase-orders.issue');
    Route::post('/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

    // Procurement Dashboard + Vendor Performance (Milestone 4, Workstream C6).
    Route::get('/procurement/dashboard', [ProcurementDashboardController::class, 'index'])->name('procurement.dashboard');
    Route::get('/procurement/vendor-performance', [VendorPerformanceController::class, 'index'])->name('procurement.vendor-performance');

    // Item Master (Milestone 4, Acceleration Part 1A) -- viewable by all
    // roles, mutation gated by canManageWarehouse() inside the controller.
    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::post('/items', [ItemController::class, 'store'])->name('items.store');
    Route::put('/items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::delete('/items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');

    // Warehouse / Inventory (Milestone 4, Acceleration Part 1B).
    // v1.11.3.2 (Priority Pass Part 9). Named warehouses.dashboard (not
    // warehouse.dashboard) deliberately -- the `warehouses` route-name
    // prefix is already mapped to the 'logistics' department in
    // config/departments.php (Warehouse stays inside Logistics, per
    // established design -- see workspaces.js's own note), so this
    // inherits that existing RBAC mapping with zero config change needed.
    // A `warehouse.*` prefix would resolve to the separate, effectively
    // unused 'warehouse' department key instead and 403 for every real
    // Logistics user -- the same bug class just fixed on the Main
    // Dashboard, avoided here by reusing the existing prefix family.
    Route::get('/warehouses/dashboard', [WarehouseDashboardController::class, 'index'])->name('warehouses.dashboard');
    Route::get('/warehouses/master', [WarehouseController::class, 'master'])->name('warehouses.master');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
    Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
    Route::post('/warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation'])->name('warehouses.locations.store');
    Route::delete('/warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'destroyLocation'])->name('warehouses.locations.destroy');

    Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('/stock/movements', [StockController::class, 'movements'])->name('stock.movements');
    Route::get('/stock/items/{item}/card', [StockController::class, 'card'])->name('stock.card');
    Route::get('/stock/transactions/create', [StockTransactionController::class, 'create'])->name('stock.transactions.create');
    Route::post('/stock/issue', [StockTransactionController::class, 'issue'])->name('stock.issue');
    Route::post('/stock/transfer', [StockTransactionController::class, 'transfer'])->name('stock.transfer');
    Route::post('/stock/adjust', [StockTransactionController::class, 'adjust'])->name('stock.adjust');
    Route::post('/stock/opname', [StockTransactionController::class, 'opname'])->name('stock.opname');

    // Asset Management (Milestone 4, Acceleration Part 1C).
    Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
    Route::get('/assets/create', [AssetController::class, 'create'])->name('assets.create');
    Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::post('/assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
    Route::post('/assets/{asset}/transfer', [AssetController::class, 'transfer'])->name('assets.transfer');
    Route::post('/assets/{asset}/inspect', [AssetController::class, 'inspect'])->name('assets.inspect');
    Route::post('/assets/{asset}/status', [AssetController::class, 'changeStatus'])->name('assets.change-status');

    // Maintenance CMMS Foundation (Milestone 4, Acceleration Part 2).
    Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'index'])->name('maintenance-requests.index');
    Route::get('/maintenance-requests/create', [MaintenanceRequestController::class, 'create'])->name('maintenance-requests.create');
    Route::post('/maintenance-requests', [MaintenanceRequestController::class, 'store'])->name('maintenance-requests.store');
    Route::get('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'show'])->name('maintenance-requests.show');
    Route::post('/maintenance-requests/{maintenanceRequest}/transition', [MaintenanceRequestController::class, 'transition'])->name('maintenance-requests.transition');

    Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('/work-orders/create', [WorkOrderController::class, 'create'])->name('work-orders.create');
    Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
    Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::post('/work-orders/{workOrder}/transition', [WorkOrderController::class, 'transition'])->name('work-orders.transition');
    Route::post('/work-orders/{workOrder}/spare-parts', [WorkOrderController::class, 'addSparePart'])->name('work-orders.spare-parts.store');

    // Project Activities (Milestone 4, Acceleration Part 3).
    Route::get('/projects/{project}/activities', [ProjectController::class, 'activities'])->name('projects.activities');
    Route::post('/projects/{project}/activities', [ProjectActivityController::class, 'store'])->name('projects.activities.store');
    Route::put('/projects/{project}/activities/{activity}', [ProjectActivityController::class, 'update'])->name('projects.activities.update');
    Route::delete('/projects/{project}/activities/{activity}', [ProjectActivityController::class, 'destroy'])->name('projects.activities.destroy');

    // QC Foundation (Milestone 4, Acceleration Part 3).
    Route::get('/inspection-requests', [InspectionRequestController::class, 'index'])->name('inspection-requests.index');
    Route::get('/inspection-requests/create', [InspectionRequestController::class, 'create'])->name('inspection-requests.create');
    Route::post('/inspection-requests', [InspectionRequestController::class, 'store'])->name('inspection-requests.store');
    Route::get('/inspection-requests/{inspectionRequest}', [InspectionRequestController::class, 'show'])->name('inspection-requests.show');
    Route::post('/inspection-requests/{inspectionRequest}/result', [InspectionRequestController::class, 'recordResult'])->name('inspection-requests.result');

    Route::get('/ncrs', [NcrController::class, 'index'])->name('ncrs.index');
    Route::get('/ncrs/create', [NcrController::class, 'create'])->name('ncrs.create');
    Route::post('/ncrs', [NcrController::class, 'store'])->name('ncrs.store');
    Route::get('/ncrs/{ncr}', [NcrController::class, 'show'])->name('ncrs.show');
    Route::post('/ncrs/{ncr}/corrective-action', [NcrController::class, 'raiseCorrectiveAction'])->name('ncrs.raise-corrective-action');
    Route::post('/ncrs/{ncr}/close', [NcrController::class, 'close'])->name('ncrs.close');

    // Contractor Management (Milestone 4, Acceleration Part 4).
    Route::get('/contractors', [ContractorController::class, 'index'])->name('contractors.index');
    Route::get('/contractors/create', [ContractorController::class, 'create'])->name('contractors.create');
    Route::post('/contractors', [ContractorController::class, 'store'])->name('contractors.store');
    Route::get('/contractors/{contractor}', [ContractorController::class, 'show'])->name('contractors.show');
    Route::post('/contractors/{contractor}/approval', [ContractorController::class, 'reviewApproval'])->name('contractors.approval');
    Route::post('/contractors/{contractor}/documents', [ContractorController::class, 'storeDocument'])->name('contractors.documents.store');
    Route::delete('/contractors/{contractor}/documents/{document}', [ContractorController::class, 'destroyDocument'])->name('contractors.documents.destroy');
    Route::post('/contractors/{contractor}/workers', [ContractorController::class, 'storeWorker'])->name('contractors.workers.store');
    Route::put('/contractors/{contractor}/workers/{worker}', [ContractorController::class, 'updateWorker'])->name('contractors.workers.update');
    Route::delete('/contractors/{contractor}/workers/{worker}', [ContractorController::class, 'destroyWorker'])->name('contractors.workers.destroy');

    // Visitor Management (Milestone 4, Acceleration Part 5).
    Route::get('/visitors', [VisitorController::class, 'index'])->name('visitors.index');
    Route::get('/visitors/create', [VisitorController::class, 'create'])->name('visitors.create');
    Route::post('/visitors', [VisitorController::class, 'store'])->name('visitors.store');
    Route::get('/visitors/{visitor}', [VisitorController::class, 'show'])->name('visitors.show');
    Route::post('/visitors/{visitor}/approve', [VisitorController::class, 'approve'])->name('visitors.approve');
    Route::post('/visitors/{visitor}/reject', [VisitorController::class, 'reject'])->name('visitors.reject');
    Route::post('/visitors/{visitor}/induction', [VisitorController::class, 'toggleInduction'])->name('visitors.induction');
    Route::post('/visitors/{visitor}/check-in', [VisitorController::class, 'checkIn'])->name('visitors.check-in');
    Route::post('/visitors/{visitor}/check-out', [VisitorController::class, 'checkOut'])->name('visitors.check-out');

    // Document Control Foundation (Milestone 4, Acceleration Part 6).
    Route::get('/controlled-documents', [ControlledDocumentController::class, 'index'])->name('controlled-documents.index');
    Route::get('/controlled-documents/create', [ControlledDocumentController::class, 'create'])->name('controlled-documents.create');
    Route::post('/controlled-documents', [ControlledDocumentController::class, 'store'])->name('controlled-documents.store');
    Route::get('/controlled-documents/{controlledDocument}', [ControlledDocumentController::class, 'show'])->name('controlled-documents.show');
    Route::post('/controlled-documents/{controlledDocument}/versions', [ControlledDocumentController::class, 'storeVersion'])->name('controlled-documents.versions.store');
    Route::post('/controlled-documents/{controlledDocument}/transition', [ControlledDocumentController::class, 'transition'])->name('controlled-documents.transition');

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
        Route::post('/safety-equipment/{safetyEquipment}/inspections', [SafetyEquipmentController::class, 'recordInspection'])->name('safety-equipment.inspections.store');

        // v1.11.1 (HSE Domain Hardening II, Part 7).
        Route::post('/hse-equipment-types', [HseEquipmentTypeController::class, 'store'])->name('hse-equipment-types.store');
        Route::put('/hse-equipment-types/{hseEquipmentType}', [HseEquipmentTypeController::class, 'update'])->name('hse-equipment-types.update');
        Route::delete('/hse-equipment-types/{hseEquipmentType}', [HseEquipmentTypeController::class, 'destroy'])->name('hse-equipment-types.destroy');

        // v1.11.2 (Final Completion Pass, Part 9).
        Route::post('/hse-checklist-templates', [HseChecklistTemplateController::class, 'store'])->name('hse-checklist-templates.store');
        Route::put('/hse-checklist-templates/{hseChecklistTemplate}', [HseChecklistTemplateController::class, 'update'])->name('hse-checklist-templates.update');
        Route::delete('/hse-checklist-templates/{hseChecklistTemplate}', [HseChecklistTemplateController::class, 'destroy'])->name('hse-checklist-templates.destroy');

        Route::post('/hse-materials', [HseMaterialController::class, 'store'])->name('hse-materials.store');
        Route::put('/hse-materials/{hseMaterial}', [HseMaterialController::class, 'update'])->name('hse-materials.update');
        Route::delete('/hse-materials/{hseMaterial}', [HseMaterialController::class, 'destroy'])->name('hse-materials.destroy');

        // v1.11.4 (HSE Waste Management, Part 12/15) -- master-data
        // mutations, same role gate as every other HSE master here.
        Route::post('/waste-types', [WasteMasterController::class, 'storeType'])->name('waste-types.store');
        Route::put('/waste-types/{wasteType}', [WasteMasterController::class, 'updateType'])->name('waste-types.update');
        Route::delete('/waste-types/{wasteType}', [WasteMasterController::class, 'destroyType'])->name('waste-types.destroy');
        Route::post('/waste-storage-locations', [WasteMasterController::class, 'storeStorageLocation'])->name('waste-storage-locations.store');
        Route::put('/waste-storage-locations/{wasteStorageLocation}', [WasteMasterController::class, 'updateStorageLocation'])->name('waste-storage-locations.update');
        Route::delete('/waste-storage-locations/{wasteStorageLocation}', [WasteMasterController::class, 'destroyStorageLocation'])->name('waste-storage-locations.destroy');

        // v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 7) --
        // Waste Container Inventory mutations, same gate as the two
        // master-data groups directly above.
        Route::post('/waste-containers', [WasteContainerController::class, 'store'])->name('waste-containers.store');
        Route::put('/waste-containers/{wasteContainer}', [WasteContainerController::class, 'update'])->name('waste-containers.update');
        Route::delete('/waste-containers/{wasteContainer}', [WasteContainerController::class, 'destroy'])->name('waste-containers.destroy');

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

    // v2.14.0 (SaaS Productization / Pricing Foundation, Part 8/9).
    // Tenant-facing Plans comparison page. Deliberately placed OUTSIDE
    // the `role:super_admin,hse` group above (and the `role:super_admin`
    // group below) -- knowing what plans exist and what the tenant's own
    // plan includes is not privileged information (see
    // SettingsController::plans()'s own doc comment), so every
    // authenticated tenant user should reach it, not only Super
    // Admin/HSE. Still inside the outer `auth`+`restrict.platform-admin`
    // group, so a Platform Admin (no tenant) cannot reach it, matching
    // every other tenant route in this file. This exact "route
    // accidentally nested inside a role-restricted group" shape is a
    // documented, previously-real bug in this codebase -- see
    // docs/CONVENTIONS.md's "Known Pitfalls" -- so this placement was
    // deliberate, not incidental.
    Route::get('/subscription/plans', [SettingsController::class, 'plans'])->name('subscription.plans');

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
        // v2.17.0 (PTW Field Workflow Foundation + Controlled PTW
        // Access). Placed alongside the rest of Users management, inside
        // this same `role:super_admin` group -- the Users tab itself is
        // already Super-Admin-only end to end (frontend `canSystem` gate
        // + this route group), so that's the effective authorization
        // today. `SettingsController::updatePtwAccess()`'s own inline
        // `canManageHse()` check is intentionally broader (Super Admin OR
        // HSE) as defense-in-depth for if this route is ever also
        // exposed to HSE from a future Settings surface -- it does not
        // widen who can reach it today, since this route-level
        // `role:super_admin` gate is stricter and runs first.
        Route::put('/settings/users/{user}/ptw-access', [SettingsController::class, 'updatePtwAccess'])->name('settings.users.ptw-access');

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

    // Man-Hour (v1.11.6, Production Readiness pass, Part 4) -- HR-owned operational log, see ManHourController's own doc comment.
    Route::get('/man-hour', [ManHourController::class, 'index'])->name('man-hour.index');
    Route::post('/man-hour', [ManHourController::class, 'store'])->name('man-hour.store');
    Route::delete('/man-hour/{manHourLog}', [ManHourController::class, 'destroy'])->name('man-hour.destroy');

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

    // v1.11.3 (Global Dashboard/Overview UX Rework, Part 4) -- these three
    // departments had no Overview route at all before this pass (confirmed
    // via audit: no Overview item in workspaces.js). Same route shape as
    // every other department dashboard above.
    Route::get('/asset-management/dashboard', [AssetDashboardController::class, 'index'])->name('asset-management.dashboard');
    Route::get('/maintenance/dashboard', [MaintenanceDashboardController::class, 'index'])->name('maintenance.dashboard');
    Route::get('/quality-control/dashboard', [QualityControlDashboardController::class, 'index'])->name('quality-control.dashboard');

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

    // v1.11.0 (SaaS Finalization Pass).
    Route::put('/tenants/{tenant}/subscription', [PlatformController::class, 'updateSubscription'])->name('tenants.subscription.update');
    Route::post('/tenants/{tenant}/invoices', [PlatformController::class, 'storeInvoice'])->name('tenants.invoices.store');
    Route::put('/invoices/{invoice}/mark-paid', [PlatformController::class, 'markInvoicePaid'])->name('invoices.mark-paid');
    Route::get('/plans', [PlatformController::class, 'plans'])->name('plans');
    Route::post('/plans', [PlatformController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{plan}', [PlatformController::class, 'updatePlan'])->name('plans.update');
});
