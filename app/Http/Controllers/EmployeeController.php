<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Exports\EmployeeImportTemplateExport;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Imports\EmployeesImport;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeInternship;
use App\Models\Position;
use App\Services\FieldMappingService;
use App\Services\MasterDataDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $employees = Employee::query()
            ->with('company', 'department', 'position')
            ->search($request->input('search'))
            ->inCompany($companyId)
            ->inDepartment($request->input('department_id') ? (int) $request->input('department_id') : null)
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->profileStatus($request->input('profile_status'))
            ->orderedForDisplay()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)
                ->inCompany($companyId)
                ->ordered()
                ->get(['id', 'name', 'company_id']),
            'filters' => $request->only('search', 'company_id', 'department_id', 'status', 'profile_status'),
            'can' => ['manage' => $request->user()->isAdmin()],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Employee::class);

        return Inertia::render('Employees/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->ordered()->get(['id', 'name', 'company_id']),
            'positions' => Position::where('is_active', true)->ordered()->get(['id', 'name', 'company_id', 'department_id']),
            'employmentTypes' => $this->employmentTypeOptions(),
            'employee' => null,
        ]);
    }

    /**
     * Milestone 4, Workstream A. `internship` is a nested sub-array
     * (App\Models\EmployeeInternship fields), not an Employee column --
     * pulled out before Employee::create() and persisted separately, only
     * when employment_type is intern/pkl. Wrapped in a transaction so a
     * failure creating the internship detail row doesn't leave a
     * half-created Employee behind.
     */
    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $internshipData = $data['internship'] ?? null;
        unset($data['internship']);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('uploads/employees', 'public');
        }

        $employee = DB::transaction(function () use ($data, $internshipData) {
            $employee = Employee::create($data);
            $this->syncInternship($employee, $internshipData);

            return $employee;
        });

        ActivityLog::record('created', "Employee {$employee->full_name} ({$employee->employee_id}) was created.", $employee);

        return redirect()->route('employees.index')->with('success', 'Employee created successfully.');
    }

    public function show(Employee $employee): Response
    {
        $employee->load('company', 'department', 'position', 'internship');

        $currentYear = (int) now()->format('Y');

        $monthlyTotals = collect(range(1, 12))->map(function ($month) use ($employee, $currentYear) {
            return [
                'month' => $month,
                'totals' => $employee->kpiTotals($currentYear, $month),
            ];
        });

        // Projects this employee is currently assigned to, for the profile page.
        $projects = $employee->projects()->orderByDesc('projects.created_at')->get(['projects.id', 'projects.name', 'projects.status']);

        return Inertia::render('Employees/Profile', [
            'employee' => $employee,
            'yearSummary' => $employee->kpiTotals($currentYear),
            'monthlyBreakdown' => $monthlyTotals,
            'year' => $currentYear,
            'yearsOfService' => $employee->yearsOfService(),
            'projects' => $projects,
            'can' => ['manage' => request()->user()->isAdmin()],
        ]);
    }

    public function edit(Employee $employee): Response
    {
        $this->authorize('update', $employee);

        $employee->load('internship');

        return Inertia::render('Employees/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('is_active', true)->ordered()->get(['id', 'name', 'company_id']),
            'positions' => Position::where('is_active', true)->ordered()->get(['id', 'name', 'company_id', 'department_id']),
            'employmentTypes' => $this->employmentTypeOptions(),
            'employee' => $employee,
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();
        $internshipData = $data['internship'] ?? null;
        unset($data['internship']);

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('uploads/employees', 'public');
        }

        DB::transaction(function () use ($employee, $data, $internshipData) {
            $employee->update($data);
            $this->syncInternship($employee, $internshipData);
        });

        ActivityLog::record('updated', "Employee {$employee->full_name} ({$employee->employee_id}) was updated.", $employee);

        return redirect()->route('employees.index')->with('success', 'Employee updated successfully.');
    }

    /**
     * Milestone 4, Workstream A. Only writes an EmployeeInternship row
     * when the employee is actually classified intern/pkl -- a switch
     * away from intern/pkl deliberately leaves any existing detail row
     * untouched (historical record of a past placement), it does not
     * delete it; the Employee Profile page simply stops showing it while
     * employment_type is something else.
     */
    private function syncInternship(Employee $employee, ?array $internshipData): void
    {
        if (! $employee->isInternOrPkl() || ! $internshipData || empty($internshipData['institution'])) {
            return;
        }

        $employee->internship()->updateOrCreate(
            ['employee_id' => $employee->id],
            [
                'institution' => $internshipData['institution'],
                'program' => $internshipData['program'] ?? null,
                'mentor_name' => $internshipData['mentor_name'] ?? null,
                'agreement_number' => $internshipData['agreement_number'] ?? null,
                'start_date' => $internshipData['start_date'] ?? null,
                'end_date' => $internshipData['end_date'] ?? null,
                'work_location' => $internshipData['work_location'] ?? null,
                'induction_completed' => (bool) ($internshipData['induction_completed'] ?? false),
                'insurance_coverage' => $internshipData['insurance_coverage'] ?? null,
                'evaluation' => $internshipData['evaluation'] ?? null,
                'completion_status' => $internshipData['completion_status'] ?? EmployeeInternship::STATUS_ONGOING,
            ]
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function employmentTypeOptions(): array
    {
        return collect(Employee::EMPLOYMENT_TYPES)
            ->map(fn (string $type) => [
                'value' => $type,
                'label' => (new Employee(['employment_type' => $type]))->employmentTypeLabel(),
            ])
            ->values()
            ->all();
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $name = $employee->full_name;
        $employee->delete(); // soft delete: KPI history is preserved

        ActivityLog::record('deleted', "Employee {$name} was removed.", $employee);

        return redirect()->route('employees.index')->with('success', 'Employee removed.');
    }

    public function export(Request $request, FieldMappingService $mapping)
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        $search = $request->input('search');

        ActivityLog::record('exported', 'Exported employee list to Excel.');

        return Excel::download(new EmployeeExport($departmentId, $search, $companyId, $mapping->exportFields('employees')), 'employees.xlsx');
    }

    public function importTemplate(): BinaryFileResponse
    {
        return Excel::download(new EmployeeImportTemplateExport, 'employee-import-template.xlsx');
    }

    /**
     * Smart Master Data Detection (v1.6.10). Runs the exact same
     * EmployeesImport class used for the real import, just in preview
     * mode -- nothing is written to the database. The department/position
     * names it collects while scanning are then classified by the
     * reusable MasterDataDetector (existing / new / probably-a-typo),
     * and the whole thing is returned as one summary the frontend
     * renders before the user commits to anything.
     */
    public function previewImport(Request $request, MasterDataDetector $detector, FieldMappingService $mapping): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'company_id' => ['required', 'exists:companies,id'],
        ]);

        $companyId = (int) $request->input('company_id');

        $import = new EmployeesImport($companyId, $request->user()->id, previewOnly: true, columnKeys: $mapping->importColumnKeys('employees'));
        Excel::import($import, $request->file('file'));

        $departments = $detector->detectDepartments($import->departmentNames, $companyId);
        $positions = $detector->detectPositions($import->positionNames, $companyId);

        return response()->json([
            'total_rows' => $import->totalRows,
            'valid_rows' => $import->imported,
            'invalid_rows' => count($import->skipped),
            'skipped' => $import->skipped,
            'departments' => $departments,
            'positions' => $positions,
            'has_missing_master_data' => count($departments['new']) > 0 || count($positions['new']) > 0,
        ]);
    }

    /**
     * Creates whatever master data the user confirmed from the preview
     * (explicitly NOT the typo-suggested names -- those are only ever
     * created if the user separately re-types them as a genuinely new
     * name, never auto-created from a flagged suggestion), then runs
     * the real import in the same request. All inside one transaction:
     * if the import itself throws for an unexpected reason, the
     * newly-created master data rolls back with it rather than being
     * left behind orphaned from a failed import.
     */
    public function createMissingMasterDataAndImport(Request $request, FieldMappingService $mapping): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'company_id' => ['required', 'exists:companies,id'],
            'new_departments' => ['array'],
            'new_departments.*' => ['string', 'max:255'],
            'new_positions' => ['array'],
            'new_positions.*' => ['string', 'max:255'],
        ]);

        $companyId = (int) $request->input('company_id');
        $file = $request->file('file');

        $import = DB::transaction(function () use ($request, $companyId, $file, $mapping) {
            foreach ($request->input('new_departments', []) as $name) {
                Department::firstOrCreate(['company_id' => $companyId, 'name' => $name]);
            }

            foreach ($request->input('new_positions', []) as $name) {
                Position::firstOrCreate(['company_id' => $companyId, 'name' => $name]);
            }

            $import = new EmployeesImport($companyId, $request->user()->id, columnKeys: $mapping->importColumnKeys('employees'));
            Excel::import($import, $file);

            return $import;
        });

        ActivityLog::record('imported', "Imported {$import->imported} employee(s) from Excel after creating missing master data ({$import->totalRows} rows processed).");

        return response()->json([
            'total_rows' => $import->totalRows,
            'imported' => $import->imported,
            'needs_completion' => $import->needsCompletion,
            'skipped' => $import->skipped,
        ]);
    }

    /**
     * Processes every row individually and never stops on the first
     * invalid one -- see EmployeesImport for the actual row-by-row
     * logic. Returns a plain summary the frontend renders directly,
     * rather than a redirect with flash data, since the import result
     * (counts + a list of skipped rows with reasons) doesn't fit the
     * usual single flash-message pattern well.
     */
    public function import(Request $request, FieldMappingService $mapping): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'company_id' => ['required', 'exists:companies,id'],
        ]);

        $import = new EmployeesImport((int) $request->input('company_id'), $request->user()->id, columnKeys: $mapping->importColumnKeys('employees'));
        Excel::import($import, $request->file('file'));

        ActivityLog::record('imported', "Imported {$import->imported} employee(s) from Excel ({$import->totalRows} rows processed).");

        return response()->json([
            'total_rows' => $import->totalRows,
            'imported' => $import->imported,
            'needs_completion' => $import->needsCompletion,
            'skipped' => $import->skipped,
        ]);
    }
}
