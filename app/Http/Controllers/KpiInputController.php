<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKpiRecordRequest;
use App\Http\Requests\StoreQuickAttendanceRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The core KPI module. Two input modes:
 *  1. Single input: pick one employee + one KPI category + date -> +1 record.
 *  2. Quick Attendance: pick a KPI category that supports checklisting
 *     (TBM, Drill, Campaign, Safety Meeting) + a date, check multiple
 *     employees, Save -> each checked employee gets +1 for that category.
 */
class KpiInputController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('create', KpiRecord::class);

        return Inertia::render('KpiInput/Index', [
            'departments' => Department::where('is_active', true)->ordered()->get(['id', 'name', 'company_id']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            // Intentionally NOT company-scoped: Quick Attendance lets HSE
            // check off employees across multiple departments/companies in
            // one session (v1.2 design), so there's no single company
            // context to scope categories by here. Every active category
            // (global + every company's specific ones) is shown; company
            // scoping applies where it's well-defined -- the Dashboard and
            // Reports, both of which have a single Company filter.
            'categories' => KpiCategory::active()->get(),
            'quickAttendanceCategories' => KpiCategory::quickAttendanceEnabled()->get(),
            'recentRecords' => KpiRecord::with('employee', 'kpiCategory', 'department')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * Fetches employees for the quick-attendance checklist, optionally
     * filtered by department, without a full page reload.
     */
    public function employeesForAttendance(Request $request)
    {
        $this->authorize('create', KpiRecord::class);

        $employees = Employee::query()
            ->active()
            ->inDepartment($request->input('department_id') ? (int) $request->input('department_id') : null)
            ->orderedForDisplay()
            ->get(['employees.id', 'employees.employee_id', 'employees.full_name', 'employees.department_id']);

        return response()->json($employees);
    }

    public function storeSingle(StoreKpiRecordRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $record = KpiRecord::create($data);
        $record->load('employee', 'kpiCategory');

        ActivityLog::record(
            'created',
            "{$record->kpiCategory->name} recorded for {$record->employee->full_name}.",
            $record
        );

        return back()->with('success', "{$record->kpiCategory->short_label} +1 recorded for {$record->employee->full_name}.");
    }

    /**
     * Quick Attendance: bulk-create one KpiRecord per checked employee,
     * all sharing the same category/date, in a single transaction.
     */
    public function storeQuickAttendance(StoreQuickAttendanceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $category = KpiCategory::findOrFail($data['kpi_category_id']);

        $count = DB::transaction(function () use ($data, $request) {
            $employees = Employee::whereIn('id', $data['employee_ids'])->get(['id', 'department_id', 'full_name']);

            foreach ($employees as $employee) {
                KpiRecord::create([
                    'employee_id' => $employee->id,
                    'department_id' => $employee->department_id,
                    'kpi_category_id' => $data['kpi_category_id'],
                    'record_date' => $data['record_date'],
                    'quantity' => 1,
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            }

            return $employees->count();
        });

        ActivityLog::record(
            'quick_attendance',
            "Quick Attendance: {$category->name} recorded for {$count} employee(s).",
            $category,
            ['employee_ids' => $data['employee_ids'], 'record_date' => $data['record_date']]
        );

        return back()->with('success', "{$category->short_label} +1 applied to {$count} employee(s).");
    }
}
