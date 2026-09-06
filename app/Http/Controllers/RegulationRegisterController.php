<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\RegulationRegister;
use App\Services\NumberGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.52.0 -- Regulations & Standards Register.
 *
 * The register of legal and normative requirements the Health, Safety &
 * Environment function must identify, keep current, and evidence
 * compliance against. Every HSE management system asks for one; IOMS had
 * nowhere to hold it, so it lived in a spreadsheet nobody reviewed.
 *
 * AUTHORIZATION mirrors the rest of the Health, Safety & Environment
 * module: reading the register is open to any authenticated tenant user
 * who can reach the workspace (knowing which rules apply to your own site
 * is not privileged), while creating and editing entries requires
 * `canManageHse()` — the same gate every other HSE master-data screen
 * uses, not a new one invented here.
 *
 * TENANT ISOLATION flows through `company_id`, and every write validates
 * the submitted company against `Company::query()` (which TenantScope has
 * already narrowed), so a crafted request cannot file a regulation into
 * another customer's register.
 */
class RegulationRegisterController extends Controller
{
    public function index(Request $request): Response
    {
        $companyIds = Company::query()->pluck('id');

        $query = RegulationRegister::query()
            ->whereIn('company_id', $companyIds)
            ->with('owner:id,full_name', 'company:id,name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('regulation_number', 'like', "%{$search}%")
                    ->orWhere('issuing_authority', 'like', "%{$search}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('due_for_review')) {
            $query->where('status', RegulationRegister::STATUS_ACTIVE)
                ->whereNotNull('review_date')
                ->whereDate('review_date', '<=', now());
        }

        $registers = $query->orderByRaw('COALESCE(year, 0) desc')->orderBy('title')->paginate(25)->withQueryString();

        // Counts are computed from the same tenant-scoped set the list uses,
        // so the header can never disagree with the rows below it.
        $base = RegulationRegister::whereIn('company_id', $companyIds);

        return Inertia::render('Hse/Regulations/Index', [
            'registers' => $registers->through(fn (RegulationRegister $r) => [
                'id' => $r->id,
                'register_number' => $r->register_number,
                'category' => $r->category,
                'document_type' => $r->document_type,
                'citation' => $r->citation(),
                'title' => $r->title,
                'issuing_authority' => $r->issuing_authority,
                'status' => $r->status,
                'effective_date' => $r->effective_date,
                'review_date' => $r->review_date,
                'is_due_for_review' => $r->isDueForReview(),
                'owner' => $r->owner?->full_name,
                'company' => $r->company?->name,
                'document_url' => $r->secureDocumentUrl(),
            ]),
            'stats' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('status', RegulationRegister::STATUS_ACTIVE)->count(),
                'due_for_review' => (clone $base)->where('status', RegulationRegister::STATUS_ACTIVE)
                    ->whereNotNull('review_date')->whereDate('review_date', '<=', now())->count(),
                'superseded' => (clone $base)->where('status', RegulationRegister::STATUS_SUPERSEDED)->count(),
            ],
            'filters' => $request->only(['search', 'category', 'status', 'due_for_review']),
            'categories' => RegulationRegister::CATEGORIES,
            'documentTypes' => RegulationRegister::DOCUMENT_TYPES,
            'statuses' => RegulationRegister::STATUSES,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::whereIn('company_id', $companyIds)->active()
                ->orderBy('full_name')->get(['id', 'full_name']),
            'canManage' => $request->user()->canManageHse(),
        ]);
    }

    public function store(Request $request, NumberGeneratorService $numbers): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);

        $validated = $this->validated($request);

        $validated['register_number'] = $numbers->generate('regulation_register', $validated['company_id']);
        $validated['created_by'] = $request->user()->id;
        $validated = $this->withDocument($request, $validated);

        $register = RegulationRegister::create($validated);

        ActivityLog::record('created', "Regulation register entry \"{$register->title}\" was added.");

        return back()->with('success', 'Regulasi/standar ditambahkan ke register.');
    }

    public function update(Request $request, RegulationRegister $regulation, NumberGeneratorService $numbers): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($regulation);

        $validated = $this->validated($request, $regulation);
        $validated = $this->withDocument($request, $validated, $regulation);

        // An entry numbered before this feature existed, or imported, still
        // gets a number the first time it is edited.
        if (! $regulation->register_number) {
            $validated['register_number'] = $numbers->generate('regulation_register', $validated['company_id']);
        }

        $regulation->update($validated);

        ActivityLog::record('updated', "Regulation register entry \"{$regulation->title}\" was updated.");

        return back()->with('success', 'Entri register diperbarui.');
    }

    public function destroy(Request $request, RegulationRegister $regulation): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($regulation);

        $title = $regulation->title;

        // The attached file goes with the row -- an orphaned private file
        // is both a storage leak and a copy of a document nobody can reach.
        if ($regulation->document_path) {
            Storage::disk('private')->delete($regulation->document_path);
        }

        $regulation->delete();

        ActivityLog::record('deleted', "Regulation register entry \"{$title}\" was removed.");

        return back()->with('success', 'Entri register dihapus.');
    }

    private function validated(Request $request, ?RegulationRegister $existing = null): array
    {
        $companyIds = Company::query()->pluck('id');
        $employeeIds = Employee::whereIn('company_id', $companyIds)->pluck('id');
        $registerIds = RegulationRegister::whereIn('company_id', $companyIds)->pluck('id');

        return $request->validate([
            // Rule::in against the tenant's OWN ids -- a submitted id that
            // is not in this set is rejected outright, whatever it claims.
            'company_id' => ['required', Rule::in($companyIds)],
            'category' => ['required', 'string', 'max:120'],
            'document_type' => ['required', 'string', 'max:80'],
            'regulation_number' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'title' => ['required', 'string', 'max:255'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(RegulationRegister::STATUSES)],
            // A regulation cannot supersede itself, and the replacement must
            // be an entry in this tenant's own register.
            'superseded_by' => ['nullable', Rule::in($registerIds->reject(fn ($id) => $id === $existing?->id)->values())],
            'effective_date' => ['nullable', 'date'],
            'review_date' => ['nullable', 'date'],
            'applicability' => ['nullable', 'string', 'max:2000'],
            'scope' => ['nullable', 'string', 'max:2000'],
            'owner_employee_id' => ['nullable', Rule::in($employeeIds)],
            'source_reference' => ['nullable', 'string', 'max:500'],
            'compliance_reference' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:10240'],
        ]);
    }

    /**
     * Stores the attachment on the PRIVATE disk. A customer's controlled
     * copies of regulations are not world-readable; they are served by
     * SecureDocumentController, which re-checks tenant ownership on every
     * download.
     */
    private function withDocument(Request $request, array $validated, ?RegulationRegister $existing = null): array
    {
        unset($validated['document']);

        if ($request->hasFile('document')) {
            if ($existing?->document_path) {
                Storage::disk('private')->delete($existing->document_path);
            }

            $file = $request->file('document');
            $validated['document_path'] = $file->store('regulations', 'private');
            $validated['document_name'] = $file->getClientOriginalName();
        }

        return $validated;
    }

    private function assertInCurrentTenant(RegulationRegister $regulation): void
    {
        abort_unless(Company::query()->pluck('id')->contains($regulation->company_id), 404);
    }
}
