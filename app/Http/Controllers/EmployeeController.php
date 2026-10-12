<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Exports\EmployeeImportTemplateExport;
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
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
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
                // v2.69.0 -- gates the Employee Relations card below.
                'viewCases' => request()->user()->canManageEmployeeCases(),
            ],

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

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);
        $this->assertInCurrentTenant($employee);

        $name = $employee->full_name;
        $employee->delete(); // soft delete: KPI history is preserved

        ActivityLog::record('deleted', "Employee {$name} was removed.", $employee);

        return redirect()->route('employees.index')->with('success', 'Employee removed.');
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
    public function bulkDestroy(BulkDestroyEmployeesRequest $request): RedirectResponse
    {
        $employees = $request->employees();

        DB::transaction(function () use ($employees) {
            foreach ($employees as $employee) {
                $name = $employee->full_name;
                $number = $employee->employee_id;

                $employee->delete();

                ActivityLog::record('deleted', "Employee {$name} ({$number}) was removed in a bulk deletion.", $employee);
            }

            // The act itself, as one line, so the Activity Center shows a
            // bulk removal as the single decision it was rather than only
            // as N unrelated deletions.
            ActivityLog::record('deleted', "Removed {$employees->count()} employee(s) in one bulk deletion.");
        });

        return redirect()->route('employees.index')
            ->with('success', "{$employees->count()} employee(s) removed.");
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
     * v2.110.0 -- EVERY IMPORT ENDPOINT ACCEPTS THE SAME FOUR FORMATS.
     *
     * The validation rule for an uploaded workforce file. Widened from
     * xlsx/xls to the formats the document platform can genuinely READ,
     * and no wider: a format listed here must have a working extractor,
     * or the customer is offered a door that does not open.
     *
     * `txt` is present only because a .csv frequently arrives with a
     * text/plain MIME type; the real decision is made by content, in
     * `FormatDetector`, not by this list.
     *
     * The size cap is the document platform's own, so one number governs
     * every upload rather than each endpoint inventing a limit.
     *
     * @return list<string>
     */
    private function importFileRules(): array
    {
        return ['required', 'file', 'mimes:xlsx,xls,csv,txt,docx,pdf', 'max:'.((int) config('documents.max_upload_mb') * 1024)];
    }

    /**
     * Runs `$work` against a SPREADSHEET, whatever the customer uploaded.
     *
     * A .xlsx or .xls is passed through untouched -- the existing
     * importer already reads it, and converting it would be a chance to
     * lose something for no gain.
     *
     * Anything else (CSV, Word, PDF) is extracted by the document
     * platform and written out as a clean, normalised .xlsx by
     * `TabularBridge`, and THAT is what the importer sees. The reason is
     * the one in TabularBridge's own doc comment: there is exactly one
     * set of employee validation rules in this codebase, and a second
     * import path would become a second, drifting copy of them.
     *
     * The temporary file is removed in `finally`, so a failed import does
     * not leave customer data in the system temp directory.
     *
     * @template T
     *
     * @param  \Closure(string, ?ExtractedDocument): T  $work
     * @return T
     */
    private function withImportSpreadsheet(Request $request, \Closure $work)
    {
        $file = $request->file('file');
        $format = app(FormatDetector::class)->detectUploaded($file);

        if ($format === null) {
            throw ValidationException::withMessages([
                'file' => 'Format berkas ini tidak dikenali. Format yang diterima: Excel (.xlsx/.xls), CSV, Word (.docx) dan PDF.',
            ]);
        }

        $document = app(DocumentIngestionService::class)->extract($file->getRealPath(), $format);
        $isSpreadsheet = in_array($format, [FormatDetector::XLSX, FormatDetector::XLS], true);

        if ($isSpreadsheet && $this->readableAsIs($document)) {
            return $work($file->getRealPath(), 0);
        }

        $table = $document->primaryTable();

        /*
         * v2.111.0 -- THE BRIDGE MUST EARN ITS PLACE.
         *
         * `readableAsIs()` trusts the locator, and the locator is a
         * heuristic. When it is wrong about a SPREADSHEET the cost is
         * total: the original file imported fine, and the normalisation
         * built from a misread heading row imports nothing. That is not
         * hypothetical -- a twelve-column template whose rows fill only
         * five columns made exactly that mistake and cost two passing
         * tests before the locator was corrected.
         *
         * So for a spreadsheet the bridge is taken only when the headings
         * it found MAP TO SOMETHING. Headings that match no IOMS field
         * cannot produce a usable import anyway, so the original file is
         * handed over and the path that worked before still works. The
         * locator stays an improvement on the old behaviour; it never
         * becomes a new way to lose.
         *
         * Word, CSV and PDF get no such fallback, because for them there
         * is no older path to fall back TO.
         */
        if ($isSpreadsheet && $table !== null && ! $this->mappableHeadings($table->headings)) {
            return $work($file->getRealPath(), 0);
        }

        if ($table === null) {
            if ($isSpreadsheet) {
                /*
                 * Our locator found nothing, but Maatwebsite might still
                 * read the file. Failing OPEN here keeps a workbook that
                 * imported yesterday importing today: the locator is an
                 * improvement on the old path, not a new gate in front of
                 * it.
                 */
                return $work($file->getRealPath(), 0);
            }

            throw ValidationException::withMessages([
                'file' => 'Tidak ada tabel yang dapat dibaca dari berkas ini. '
                    .($document->warnings[0] ?? 'Pastikan berkas memuat tabel dengan baris judul kolom.'),
            ]);
        }

        $path = app(TabularBridge::class)->toSpreadsheet($table);

        /*
         * The bridge puts the headings on row 1, so a record that sat on
         * source row N is now on row N - (headerRowNumber - 1).
         */
        $rowOffset = $table->headerRowNumber - 1;

        try {
            return $work($path, $rowOffset);
        } finally {
            @unlink($path);
        }
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
     * Do these headings name at least two things IOMS can import?
     *
     * Two rather than one because a single accidental match ("Status",
     * say) is noise; two is a column layout. The same matcher the preview
     * shows the customer answers it, so the decision and the explanation
     * it is shown beside cannot disagree.
     *
     * @param  list<string>  $headings
     */
    private function mappableHeadings(array $headings): bool
    {
        $suggestion = app(ImportHeaderMatcher::class)->suggest($headings, [], 'employees');

        return count($suggestion['mapping'] ?? []) >= 2;
    }

    private function readableAsIs(ExtractedDocument $document): bool
    {
        if ($document->tables === []) {
            return true;
        }

        foreach ($document->tables as $table) {
            if ($table->headerRowNumber !== 1) {
                return false;
            }
        }

        return true;
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
            'company_id' => ['required', Rule::in(Company::query()->pluck('id'))],
            ...$this->columnMappingRules(),
        ]);

        $companyId = (int) $request->input('company_id');

        try {
            // v2.110.0: the file reaching EmployeesImport is always a
            // spreadsheet, whatever the customer uploaded. See
            // withImportSpreadsheet().
            $import = $this->withImportSpreadsheet($request, function (string $path, int $rowOffset) use ($companyId, $request, $mapping) {
                $import = new EmployeesImport($companyId, $request->user()->id, previewOnly: true, columnKeys: $mapping->importColumnKeys('employees', $this->confirmedColumnMapping($request)), rowOffset: $rowOffset);
                Excel::import($import, $path, null, \Maatwebsite\Excel\Excel::XLSX);

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
            $import = $this->withImportSpreadsheet($request, fn (string $path, int $rowOffset) => DB::transaction(function () use ($request, $companyId, $path, $rowOffset, $mapping, $confirmedMapping) {
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
                Excel::import($import, $path, null, \Maatwebsite\Excel\Excel::XLSX);

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

        ActivityLog::record('imported', "Imported {$import->imported} employee(s) from Excel after creating missing master data ({$import->totalRows} rows processed).");

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
            'company_id' => ['required', Rule::in(Company::query()->pluck('id'))],
            ...$this->columnMappingRules(),
        ]);

        $import = $this->withImportSpreadsheet($request, function (string $path, int $rowOffset) use ($request, $mapping) {
            $import = new EmployeesImport((int) $request->input('company_id'), $request->user()->id, columnKeys: $mapping->importColumnKeys('employees', $this->confirmedColumnMapping($request)), rowOffset: $rowOffset);
            Excel::import($import, $path, null, \Maatwebsite\Excel\Excel::XLSX);

            return $import;
        });

        // The format is NAMED in the audit trail, because "imported from
        // Excel" on a row that actually came out of a PDF would make the
        // history harder to trust, not easier.
        $source = app(FormatDetector::class)->label(app(FormatDetector::class)->detectUploaded($request->file('file')));

        ActivityLog::record('imported', "Imported {$import->imported} employee(s) from {$source} ({$import->totalRows} rows processed).");

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
