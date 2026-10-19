<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Exports\EmployeeImportTemplateExport;
use App\Http\Controllers\Concerns\ReadsImportedSpreadsheets;
use App\Http\Requests\BulkDestroyEmployeesRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Imports\EmployeesImport;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompetencyType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCase;
use App\Models\EmployeeInternship;
use App\Models\Position;
use App\Models\Project;
use App\Models\RosterPattern;
use App\Models\Shift;
use App\Services\DocumentEngine;
use App\Services\EntitlementService;
use App\Services\EmployeeEraser;
use App\Services\Documents\DocumentIngestionService;
use App\Services\Documents\FormatDetector;
use App\Services\Documents\Support\ExtractedDocument;
use App\Services\Documents\TabularBridge;
use App\Services\FieldMappingService;
use App\Services\ImportHeaderMatcher;
use App\Services\MasterDataDetector;
use App\Services\PdfGeneratorService;
use App\Support\NameFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelFacadeType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
    use ReadsImportedSpreadsheets;

    /** @see ReadsImportedSpreadsheets — unchanged from when this was hardcoded here. */
    protected function importModuleKey(): string
    {
        return 'employees';
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        // v1.10.5 security fix: `Employee` carries no TenantScope of its
        // own (only `Company` does -- see CLAUDE.md's "Where things
        // actually are"). Without this base `whereIn`, omitting the
        // optional `?company_id=` filter entirely (the default landing
        // state of this page) returned EVERY tenant's employees, not just
        // the current tenant's -- a full-roster cross-tenant leak, not
        // just a single-record IDOR. `inCompany($companyId)` below still
        // narrows within this set exactly as before; if `$companyId`
        // belongs to another tenant, the two conditions combine to zero
        // rows rather than leaking that tenant's data, so `$companyId`
        // itself doesn't need a separate Rule::in() check here.
        $tenantCompanyIds = Company::query()->pluck('id');
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        $employees = Employee::query()
            ->whereIn('employees.company_id', $tenantCompanyIds)
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

        return redirect()->route('employees.index')->with('success', 'Employee created.');
    }

    public function show(Employee $employee): Response
    {
        $this->assertInCurrentTenant($employee);

        $employee->load(
            'company', 'department', 'position', 'internship', 'competencies.competencyType',
            'shiftAssignments.shift', 'rosters.shift', 'rosters.rosterPattern', 'rosters.project'
        );

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
            // Milestone 4, Workstream A2 -- competency types belonging to
            // this employee's own company, for the "Add Competency" dialog.
            'competencyTypes' => CompetencyType::active()->where('company_id', $employee->company_id)->get(['id', 'name', 'type']),
            // Milestone 4, Workstream A3 -- shifts/roster patterns/projects
            // for this employee's own company, for the Shift/Roster dialogs.
            'shifts' => Shift::active()->where('company_id', $employee->company_id)->get(['id', 'name', 'code']),
            'rosterPatterns' => RosterPattern::active()->where('company_id', $employee->company_id)->get(['id', 'name', 'days_on', 'days_off']),
            'availableProjects' => Project::where('company_id', $employee->company_id)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'manage' => request()->user()->isAdmin(),
                /*
                 * v2.69.0 -- gates the Employee Relations card below.
                 *
                 * v2.122.0: the PLAN is part of the answer. Employee Cases
                 * belongs to a workspace a Starter tenant does not have, so
                 * a Starter administrator -- who passes the role check --
                 * was shown the card and its "Lihat riwayat kasus" link,
                 * which answered "Fitur ini belum tersedia untuk paket
                 * perusahaan Anda". Browser-verified. Asked through the
                 * same prefix map `EnforceTenantEntitlement` enforces with.
                 */
                'viewCases' => request()->user()->canManageEmployeeCases()
                    && app(EntitlementService::class)->tenantCanUseRoutePrefix(
                        request()->user()->tenant,
                        'employee-cases.index',
                    ),
            ],

            /*
             * v2.115.0 -- WHAT DELETING THIS PERSON WOULD ACTUALLY TAKE.
             *
             * Deletion is permanent now, so the confirmation has to state
             * the real consequence instead of the reassurance the old
             * dialog gave ("their history is preserved", which stopped
             * being true). Counted here rather than described in the
             * component, because a number the server took from the
             * database is a fact and a sentence a component wrote is a
             * guess.
             *
             * Resolved for an admin only: it is the only role that can
             * reach the action, and a dependent-record census is not
             * something to put in every viewer's page payload.
             */
            'erasureImpact' => request()->user()->isAdmin()
                ? app(EmployeeEraser::class)->impact([$employee->id])
                : null,

            /*
             * v2.69.0 -- EMPLOYEE RELATIONS, AND WHY IT IS CONDITIONAL.
             *
             * This page is readable by anyone who can view employees --
             * HSE, project managers, warehouse. A disciplinary history is
             * not that kind of data, so these props are resolved ONLY for
             * a viewer who may read cases. Sending them and hiding the
             * card in React would still put them in the page payload,
             * where anyone can read them; the authorization has to happen
             * before serialization, not after.
             *
             * Standing is derived (see Employee::currentDisciplinaryStanding)
             * rather than stored, so it lapses on its own.
             */
            'disciplinary' => request()->user()->canManageEmployeeCases()
                ? [
                    'standing' => $employee->currentDisciplinaryStanding(),
                    'openCases' => EmployeeCase::query()
                        ->where('employee_id', $employee->id)
                        ->active()
                        ->count(),
                    'totalCases' => EmployeeCase::query()
                        ->where('employee_id', $employee->id)
                        ->count(),
                ]
                : null,
        ]);
    }

    public function edit(Employee $employee): Response
    {
        $this->authorize('update', $employee);
        $this->assertInCurrentTenant($employee);

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
        $this->assertInCurrentTenant($employee);

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

        return redirect()->route('employees.index')->with('success', 'Employee updated.');
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

    /**
     * v2.115.0 -- PERMANENT, AND THE AUDIT ROW HAS TO SURVIVE IT.
     *
     * This used to be a soft delete, with the comment "KPI history is
     * preserved" next to it. The history was preserved and the employee
     * number was preserved with it, which is what made a corrected
     * import impossible (ADR 055).
     *
     * `ActivityLog` has no foreign key to the subject, so the row
     * outlives the record -- but `subject_id` then points at nothing, so
     * the identity has to be IN the entry. The snapshot below is what
     * makes the audit trail still answer "who was removed, by whom, and
     * what went with them" after the record itself is gone.
     */
    public function destroy(Employee $employee, EmployeeEraser $eraser): RedirectResponse
    {
        $this->authorize('delete', $employee);
        $this->assertInCurrentTenant($employee);

        $snapshot = $this->erasureSnapshot($employee);
        $name = $employee->full_name;
        $number = $employee->employee_id;

        $removed = $eraser->erase($employee);

        ActivityLog::record(
            'deleted',
            "Employee {$name} ({$number}) was permanently deleted, with {$removed['total_dependents']} dependent record(s).",
            $employee,
            ['employee' => $snapshot, 'removed' => $removed]
        );

        return redirect()->route('employees.index')
            ->with('success', "{$name} permanently deleted.");
    }

    /**
     * The identity of an employee as it was at the moment of erasure.
     *
     * Names, not ids: a department id in an audit row about a deleted
     * person is one more lookup that may itself be gone. This is the
     * same reasoning as `ActivityLog.actor_name` (v2.100.0) applied to
     * the subject instead of the actor.
     *
     * @return array<string, string|null>
     */
    private function erasureSnapshot(Employee $employee): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'nik' => $employee->nik,
            'full_name' => $employee->full_name,
            'department' => $employee->department?->name,
            'position' => $employee->position?->name,
            'company' => $employee->company?->name,
            'status' => $employee->status,
            'employment_type' => $employee->employment_type,
            'join_date' => $employee->join_date?->toDateString(),
        ];
    }

    /**
     * v2.108.1 -- THE CONFIRMED COLUMN MAPPING, VALIDATED AS THE INPUT IT IS.
     *
     * `[field => heading in the customer's own file]`, as
     * `/employees/import/inspect` suggested it and the import dialog showed
     * it for confirmation. Absent on the golden path (the official
     * template), in which case the tenant's configured mapping is used
     * unchanged -- byte-for-byte the previous behaviour.
     *
     * Three refusals, because this decides which column feeds which field
     * and a wrong one writes a phone number into an address:
     *
     *  - only fields IOMS actually imports may appear (`array:` with the
     *    catalogue's own keys), so a mapping cannot name a column that is
     *    not part of the import at all;
     *  - each heading is a plain string, never a nested structure;
     *  - no two fields may claim the SAME heading, which would copy one
     *    column into two fields and is exactly the silent field-shifting
     *    this check exists to stop.
     *
     * @return array<string, string>
     */
    private function columnMappingRules(): array
    {
        $fields = array_keys(config('mapping_fields.employees.import', []));

        return [
            'column_mapping' => ['sometimes', 'array:'.implode(',', $fields)],
            'column_mapping.*' => ['string', 'max:255', 'distinct'],
        ];
    }

    /** @return array<string, string> field => heading, as confirmed by the user */
    private function confirmedColumnMapping(Request $request): array
    {
        /** @var array<string, string> $mapping */
        $mapping = $request->input('column_mapping', []);

        return $mapping;
    }

    /**
     * v2.108.0 -- REMOVING A SET, WITH THE SAFETY RULES WHERE THEY BELONG.
     *
     * Every refusal this action can make has already been made by
     * BulkDestroyEmployeesRequest: authority (`deleteAny`), the bound on
     * how many, the typed confirmation, the count the human agreed to,
     * and the tenant boundary -- all or nothing. By the time this method
     * runs, `$request->employees()` is a complete, tenant-owned set that
     * may be deleted, so there is deliberately no second opinion here
     * and no path on which some rows go and others stay.
     *
     * SOFT DELETE, one record at a time, inside a transaction. Soft
     * because KPI history, man-hours, PPE issues and approvals all point
     * at the person -- the same reason `destroy()` has always soft
     * deleted. One at a time rather than a mass `whereIn()->delete()`
     * because each removal is recorded in the audit trail against its own
     * subject, which a bulk query cannot do; the transaction is what
     * keeps the set atomic anyway.
     */
    public function bulkDestroy(BulkDestroyEmployeesRequest $request, EmployeeEraser $eraser): RedirectResponse
    {
        $employees = $request->employees();
        $employees->load('department:id,name', 'position:id,name', 'company:id,name');

        $removed = DB::transaction(function () use ($employees, $eraser) {
            /*
             * Every per-employee audit row is written BEFORE the erasure,
             * so the entry is in the database whether or not the set
             * erases cleanly -- and if it does not, the whole
             * transaction including these rows rolls back together. The
             * old code wrote each row after deleting its employee, which
             * worked only because a soft delete leaves the model
             * populated; it would be a quiet lie here.
             */
            foreach ($employees as $employee) {
                ActivityLog::record(
                    'deleted',
                    "Employee {$employee->full_name} ({$employee->employee_id}) was permanently deleted in a bulk deletion.",
                    $employee,
                    ['employee' => $this->erasureSnapshot($employee)]
                );
            }

            $removed = $eraser->eraseMany($employees);

            // The act itself, as one line, so the Activity Center shows a
            // bulk removal as the single decision it was rather than only
            // as N unrelated deletions.
            ActivityLog::record(
                'deleted',
                "Permanently deleted {$employees->count()} employee(s) in one bulk deletion, "
                ."with {$removed['total_dependents']} dependent record(s).",
                null,
                ['removed' => $removed]
            );

            return $removed;
        });

        return redirect()->route('employees.index')
            ->with('success', "{$employees->count()} employee(s) permanently deleted.");
    }

    public function export(Request $request, FieldMappingService $mapping)
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        $search = $request->input('search');

        ActivityLog::record('exported', 'Exported employee list to Excel.');

        return Excel::download(
            new EmployeeExport($departmentId, $search, $companyId, $mapping->exportFields('employees'), Company::query()->pluck('id')),
            'employees.xlsx'
        );
    }

    /**
     * v2.102.0 -- THE PRINTABLE COUNTERPART OF THE EXCEL EXPORT.
     *
     * Same filters, same tenant scoping and the same display order as
     * `export()` above, deliberately: a register that disagreed with the
     * spreadsheet beside it would be worse than not having one. The
     * filters in force are printed on the document, because a filtered
     * register that reads as a complete one is a document that misleads.
     *
     * The tenant company scoping is the same fail-closed rule the Excel
     * export carries (v1.10.5): the base filter is the current tenant's
     * own company ids, never "everyone".
     */
    public function exportPdf(Request $request, PdfGeneratorService $pdf, DocumentEngine $documents): \Illuminate\Http\Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $departmentId = $request->input('department_id') ? (int) $request->input('department_id') : null;
        $search = $request->input('search');

        $employees = Employee::query()
            ->whereIn('employees.company_id', Company::query()->pluck('id'))
            ->with('company', 'department', 'position')
            ->search($search)
            ->inCompany($companyId)
            ->inDepartment($departmentId)
            ->orderedForDisplay()
            ->get();

        // Says what this document covers, so a filtered register cannot be
        // mistaken for the whole workforce. Named rather than echoing raw
        // ids, and silent when nothing is filtered.
        $scope = array_filter([
            $companyId ? Company::find($companyId)?->name : null,
            $departmentId ? Department::find($departmentId)?->name : null,
            $search ? 'Pencarian: '.$search : null,
        ]);

        ActivityLog::record('exported', 'Exported employee register to PDF.');

        return $pdf->streamInline('pdf.employee-register', [
            'employees' => $employees,
            'identity' => $documents->identity(),
            'scopeLabel' => $scope ? implode(' · ', $scope) : 'Seluruh karyawan terdaftar',
            'generatedAt' => now(),
            'preparedBy' => $request->user()->displayName(),
        ], 'employee-register.pdf');
    }



    /**
     * v2.111.0 -- CAN THE EXISTING IMPORTER READ THIS WORKBOOK AS IT IS?
     *
     * Only when EVERY located table already has its headings on row 1,
     * because that is the single assumption `EmployeesImport` makes
     * (`WithHeadingRow` with no `headingRow()` override). When that holds,
     * the ORIGINAL file is handed over untouched, which matters for two
     * reasons that are easy to lose:
     *
     *  - Maatwebsite reads EVERY sheet of a workbook. The bridge carries
     *    one table, so bridging a workbook whose rosters are split across
     *    sheets would silently import fewer people than before.
     *  - A real Excel date cell stays a date cell rather than becoming a
     *    string on its way through.
     *
     * When it does NOT hold -- a company name and a report title above the
     * table, which is what a roster exported from a payroll system
     * actually looks like -- the file goes through the bridge, because
     * the alternative is what this method was written to fix: `inspect`
     * locating the headings on row 5, showing the customer a correct
     * mapping, and then the import reading row 1 and writing NOTHING.
     * A preview that promises rows and an import that writes none is a
     * worse failure than a refusal.
     */
    /**
     * The audit line for an import.
     *
     * Names the rows that did NOT land as well as the ones that did:
     * "Imported 72 employee(s), 3 row(s) skipped" is a usable record of
     * what happened to a file, and "Imported 72 employee(s)" on its own
     * quietly loses the three the customer still has to deal with.
     *
     * v2.115.0 dropped the separate "restored" clause this carried in
     * v2.114.0: deletion is permanent, so no row can reinstate anybody
     * and every row that lands is a new employee.
     */
    private function importOutcome(EmployeesImport $import): string
    {
        $parts = ["imported {$import->imported} employee(s)"];

        if ($import->skipped !== []) {
            $parts[] = count($import->skipped).' row(s) skipped';
        }

        return ucfirst(implode(', ', $parts));
    }




    /**
     * v2.102.0 -- SMART IMPORT: READ THE FILE THE CUSTOMER ALREADY HAS.
     *
     * The official template remains the golden path. This endpoint is for
     * the other file: the workforce list a customer already keeps, in
     * their own words, whose headings are not ours. It reads only the
     * heading row and a few sample rows, suggests which column feeds which
     * field, and WRITES NOTHING. The suggestion is shown for confirmation
     * before any import runs.
     *
     * Read with PhpSpreadsheet directly rather than through an import
     * class, because at this point there is no mapping yet -- the whole
     * question is what the columns mean, which `WithHeadingRow` would
     * already have had to assume.
     */
    public function inspectImport(Request $request, DocumentIngestionService $ingestion): JsonResponse
    {
        $request->validate(['file' => $this->importFileRules()]);

        /*
         * v2.110.0: this used to call PhpSpreadsheet directly, read the
         * ACTIVE SHEET ONLY, and assume the headings were on row 1.
         * Three real customer files break every one of those
         * assumptions -- a roster with a title and a logo above the
         * table, a workbook whose data is on the second sheet, and a
         * list that only exists as a Word document or a PDF.
         *
         * `DocumentIngestionService` answers all three: it detects the
         * format by CONTENT, extracts every table from every sheet, and
         * LOCATES the heading row rather than assuming it. The response
         * keeps the original keys so the existing dialog keeps working,
         * and adds `tables` for the files that genuinely have more than
         * one.
         */
        try {
            $inspection = $ingestion->inspect($request->file('file'), 'employees');
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $primary = $inspection['tables'][0] ?? null;

        if ($primary === null) {
            throw ValidationException::withMessages([
                'file' => 'Tidak ada tabel yang dapat dibaca dari berkas ini. '
                    .($inspection['warnings'][0] ?? 'Pastikan berkas memuat tabel dengan baris judul kolom.'),
            ]);
        }

        return response()->json([
            // --- the original contract, unchanged ---
            'headings' => $primary['headings'],
            ...$primary['mapping'],
            'field_labels' => config('mapping_fields.employees.import', []),

            // --- what the document platform adds ---
            'format' => $inspection['format'],
            'format_label' => $inspection['format_label'],
            // Stated so a user can see WHY a heading row was taken from
            // row 5, and so a low-confidence PDF reconstruction is
            // offered for confirmation rather than trusted.
            'header_row' => $primary['header_row_number'] ?? 1,
            'confidence' => $primary['confidence'] ?? 1.0,
            'source' => $primary['source'] ?? null,
            'notes' => $primary['notes'] ?? [],
            'warnings' => $inspection['warnings'],
            'tables' => array_map(fn (array $t) => [
                'index' => $t['index'],
                'source' => $t['source'],
                'headings' => $t['headings'],
                'row_count' => $t['row_count'] ?? null,
                'confidence' => $t['confidence'] ?? null,
            ], $inspection['tables']),
        ]);
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
            'file' => $this->importFileRules(),
            // v2.125.0 -- the sheet the customer picked out of a
            // multi-sheet workbook. Existence is checked where the file is
            // open (withImportSpreadsheet), which refuses by name rather
            // than falling back to another sheet.
            'sheet' => ['nullable', 'string', 'max:200'],
            'company_id' => ['required', Rule::in(Company::query()->pluck('id'))],
            ...$this->columnMappingRules(),
        ]);

        $companyId = (int) $request->input('company_id');

        try {
            // v2.110.0: the file reaching EmployeesImport is always a
            // spreadsheet, whatever the customer uploaded. See
            // withImportSpreadsheet().
            $import = $this->withImportSpreadsheet($request, function (string $path, int $rowOffset, string $reader) use ($companyId, $request, $mapping) {
                $import = new EmployeesImport($companyId, $request->user()->id, previewOnly: true, columnKeys: $mapping->importColumnKeys('employees', $this->confirmedColumnMapping($request)), rowOffset: $rowOffset);
                Excel::import($import, $path, null, $reader);

                return $import;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Berkas ini tidak dapat dibaca. Pastikan berkas memuat satu tabel dengan baris judul kolom. '
                    .'Penyebab: '.mb_substr($e->getMessage(), 0, 160),
            ], 422);
        }

        $departments = $detector->detectDepartments($import->departmentNames, $companyId);
        $positions = $detector->detectPositions($import->positionNames, $companyId);

        return response()->json([
            'total_rows' => $import->totalRows,
            'valid_rows' => $import->imported,
            // v2.114.0: rows that will REINSTATE an employee the customer
            // previously deleted. Reported separately, because the preview
            // must promise what the import will actually do.
            'invalid_rows' => count($import->skipped),
            'skipped' => $import->skipped,
            'warnings' => $import->warnings,
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
    /**
     * v2.101.0. Creates only the names that are genuinely absent, comparing
     * on `MasterDataDetector`'s key so that case and stray whitespace
     * cannot produce a second record for a department or job title that
     * already exists.
     *
     * A position is created company-wide, with no department, because that
     * is what this request carries: the dialog collects a list of NAMES,
     * not name-and-department pairs. The import resolves a position by name
     * within the company, so one created here is found; a position that
     * should belong to a specific department can be attached afterwards in
     * Settings.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $names
     */
    private function createMissingMasterData(string $model, int $companyId, array $names): void
    {
        $existingKeys = $model::where('company_id', $companyId)
            ->pluck('name')
            ->map(fn (string $name) => MasterDataDetector::comparisonKey($name))
            ->all();

        /*
         * v2.105.0 -- STORED IN THE FORM THE PRODUCT DISPLAYS.
         *
         * A department is upper case (MECHANIC VESSEL); a job title is
         * Title Case with acronyms intact (HSE Officer). Matching is still
         * on the key, which ignores case and spacing, so "mechanic" never
         * creates a second MECHANIC -- while "Mechanic Vessel" stays its
         * own department, because it is a different name and not a
         * different spelling of the same one.
         */
        $format = $model === Department::class
            ? NameFormatter::department(...)
            : NameFormatter::title(...);

        foreach ($names as $rawName) {
            $name = $format($rawName);
            $key = MasterDataDetector::comparisonKey($rawName);

            if ($name === '' || in_array($key, $existingKeys, true)) {
                continue;
            }

            $model::create(['company_id' => $companyId, 'name' => $name]);
            $existingKeys[] = $key;
        }
    }

    public function createMissingMasterDataAndImport(Request $request, FieldMappingService $mapping): JsonResponse
    {
        $request->validate([
            'file' => $this->importFileRules(),
            // v2.125.0 -- the sheet the customer picked out of a
            // multi-sheet workbook. Existence is checked where the file is
            // open (withImportSpreadsheet), which refuses by name rather
            // than falling back to another sheet.
            'sheet' => ['nullable', 'string', 'max:200'],
            'company_id' => ['required', Rule::in(Company::query()->pluck('id'))],
            'new_departments' => ['array'],
            'new_departments.*' => ['string', 'max:255'],
            'new_positions' => ['array'],
            'new_positions.*' => ['string', 'max:255'],
            ...$this->columnMappingRules(),
        ]);

        $companyId = (int) $request->input('company_id');
        // Read before the closure: `$request` is captured anyway, but the
        // mapping is a decided value, not something to re-derive inside a
        // transaction.
        $confirmedMapping = $this->confirmedColumnMapping($request);

        /*
         * v2.105.0 -- A FAILURE HERE HAS TO SAY SOMETHING.
         *
         * Any exception used to leave the framework to answer with a 500,
         * which the dialog rendered as the bare words "Import failed" --
         * after a preview that had just promised every row was valid. The
         * customer had nothing to act on and no way to tell a bad file from
         * a bad deployment.
         *
         * The per-row failures are handled inside EmployeesImport now and
         * no longer reach this point, so what IS caught here is a genuine
         * whole-import failure: an unreadable file, or master data that
         * could not be created. The transaction still rolls everything back
         * -- a half-imported workforce is worse than none -- and the
         * message says which stage failed.
         */
        try {
            // v2.110.0: normalised to a spreadsheet FIRST, outside the
            // transaction -- extracting a Word or PDF table is reading,
            // not writing, and holding a transaction open across it would
            // keep row locks for the length of a parse.
            $import = $this->withImportSpreadsheet($request, fn (string $path, int $rowOffset, string $reader) => DB::transaction(function () use ($request, $companyId, $path, $rowOffset, $reader, $mapping, $confirmedMapping) {
                /*
                 * v2.101.0 -- NORMALISED, AND MATCHED AGAINST WHAT IS ALREADY
                 * THERE.
                 *
                 * `firstOrCreate(['name' => $name])` matched on the raw cell, so
                 * " Produksi " created a second department whose name begins
                 * with a space, and "produksi" created another beside
                 * "Produksi" on any connection that compares case-sensitively.
                 * The name is now stored normalised, and an existing record is
                 * reused whenever it matches on the same key the detector and
                 * the import compare with.
                 */
                $this->createMissingMasterData(
                    Department::class,
                    $companyId,
                    $request->input('new_departments', [])
                );

                $this->createMissingMasterData(
                    Position::class,
                    $companyId,
                    $request->input('new_positions', [])
                );

                $import = new EmployeesImport($companyId, $request->user()->id, columnKeys: $mapping->importColumnKeys('employees', $confirmedMapping), rowOffset: $rowOffset);
                Excel::import($import, $path, null, $reader);

                return $import;
            }));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Impor dibatalkan dan tidak ada data yang tersimpan. '
                    .'Penyebab: '.mb_substr($e->getMessage(), 0, 200),
            ], 422);
        }

        ActivityLog::record('imported', $this->importOutcome($import)." from Excel after creating missing master data ({$import->totalRows} rows processed).");

        return response()->json([
            'total_rows' => $import->totalRows,
            'imported' => $import->imported,
            'needs_completion' => $import->needsCompletion,
            'skipped' => $import->skipped,
            // v2.108.0: rows that landed but need a human to finish
            // something -- an unreadable join date, today. Never a
            // refusal; see EmployeesImport::$warnings.
            'warnings' => $import->warnings,
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
            'file' => $this->importFileRules(),
            // v2.125.0 -- the sheet the customer picked out of a
            // multi-sheet workbook. Existence is checked where the file is
            // open (withImportSpreadsheet), which refuses by name rather
            // than falling back to another sheet.
            'sheet' => ['nullable', 'string', 'max:200'],
            'company_id' => ['required', Rule::in(Company::query()->pluck('id'))],
            ...$this->columnMappingRules(),
        ]);

        $import = $this->withImportSpreadsheet($request, function (string $path, int $rowOffset, string $reader) use ($request, $mapping) {
            $import = new EmployeesImport((int) $request->input('company_id'), $request->user()->id, columnKeys: $mapping->importColumnKeys('employees', $this->confirmedColumnMapping($request)), rowOffset: $rowOffset);
            Excel::import($import, $path, null, $reader);

            return $import;
        });

        // The format is NAMED in the audit trail, because "imported from
        // Excel" on a row that actually came out of a PDF would make the
        // history harder to trust, not easier.
        $source = app(FormatDetector::class)->label(app(FormatDetector::class)->detectUploaded($request->file('file')));

        ActivityLog::record('imported', $this->importOutcome($import)." from {$source} ({$import->totalRows} rows processed).");

        return response()->json([
            'total_rows' => $import->totalRows,
            'imported' => $import->imported,
            'needs_completion' => $import->needsCompletion,
            'skipped' => $import->skipped,
            // v2.108.0: rows that landed but need a human to finish
            // something -- an unreadable join date, today. Never a
            // refusal; see EmployeesImport::$warnings.
            'warnings' => $import->warnings,
        ]);
    }

    /**
     * v1.10.5 security fix: `show()`/`edit()`/`update()`/`destroy()` had
     * NO ownership check on the route-model-bound `$employee` at all --
     * only `authorize('update'|'delete', ...)` (a ROLE check, `isAdmin()`)
     * gated them, never a check that the employee actually belongs to the
     * current tenant. Any admin from Tenant A could view, edit, or delete
     * any employee record in the system by guessing/incrementing the id.
     * Same `assertInCurrentTenant()` 404-not-403 pattern used throughout
     * every Milestone 4 controller, now applied to Employee -- the app's
     * original, pre-Milestone-4 resource and its single most sensitive
     * piece of tenant-owned data.
     */
    private function assertInCurrentTenant(Employee $employee): void
    {
        abort_unless(Company::query()->pluck('id')->contains($employee->company_id), 404);
    }
}
