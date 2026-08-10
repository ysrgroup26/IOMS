<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Models\ContractorWorker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 4 (Contractor Management). Structurally mirrors VendorController. */
class ContractorController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $contractors = Contractor::whereIn('company_id', $tenantCompanyIds)
            ->withCount('workers')
            ->when($request->input('search'), fn ($q, $v) => $q->where('company_name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
            ->when($request->input('approval_status'), fn ($q, $v) => $q->where('approval_status', $v))
            ->orderBy('company_name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Contractors/Index', [
            'contractors' => $contractors,
            'filters' => $request->only('search', 'approval_status'),
            'can' => ['manage' => $request->user()->canManageContractors()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageContractors(), 403);

        return Inertia::render('Contractors/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'contractorCode' => Contractor::generateCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageContractors(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'pic_name' => ['nullable', 'string', 'max:255'],
            'pic_contact' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contractor = Contractor::create([...$data, 'code' => Contractor::generateCode(), 'approval_status' => Contractor::APPROVAL_STATUSES[0]]);
        ActivityLog::record('created', "Registered contractor \"{$contractor->company_name}\" ({$contractor->code}).", $contractor);

        return redirect()->route('contractors.show', $contractor)->with('flash', ['success' => 'Contractor registered.']);
    }

    public function show(Contractor $contractor, Request $request): Response
    {
        $this->assertInCurrentTenant($contractor);
        $contractor->load('documents.uploader:id,name', 'workers');

        return Inertia::render('Contractors/Show', [
            'contractor' => $contractor,
            'canManage' => $request->user()->canManageContractors(),
            'documentTypes' => ContractorDocument::TYPES,
            'hseStatuses' => ContractorWorker::HSE_STATUSES,
        ]);
    }

    public function reviewApproval(Request $request, Contractor $contractor): RedirectResponse
    {
        abort_unless($request->user()->canManageContractors(), 403);
        $this->assertInCurrentTenant($contractor);

        $data = $request->validate(['approval_status' => ['required', Rule::in(['approved', 'rejected'])]]);
        $contractor->update($data);

        ActivityLog::record('updated', "Contractor \"{$contractor->company_name}\" {$data['approval_status']}.", $contractor);

        return back()->with('success', 'Approval status updated.');
    }

    public function storeDocument(Request $request, Contractor $contractor): RedirectResponse
    {
        abort_unless($request->user()->canManageContractors(), 403);
        $this->assertInCurrentTenant($contractor);

        $data = $request->validate([
            'document_type' => ['required', Rule::in(ContractorDocument::TYPES)],
            'expiry_date' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $path = $request->file('file')->store('uploads/contractor-documents', 'public');
        $contractor->documents()->create([
            'document_type' => $data['document_type'], 'file_path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'expiry_date' => $data['expiry_date'] ?? null, 'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Document uploaded.');
    }

    public function destroyDocument(Contractor $contractor, ContractorDocument $document, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageContractors(), 403);
        abort_unless($document->contractor_id === $contractor->id, 404);
        $this->assertInCurrentTenant($contractor);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    public function storeWorker(Request $request, Contractor $contractor): RedirectResponse
    {
        abort_unless($request->user()->canManageContractors(), 403);
        $this->assertInCurrentTenant($contractor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'worker_id_number' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'competency' => ['nullable', 'string', 'max:255'],
            'hse_status' => ['required', Rule::in(ContractorWorker::HSE_STATUSES)],
        ]);

        $contractor->workers()->create($data);
        ActivityLog::record('created', "Added worker to contractor \"{$contractor->company_name}\".", $contractor);

        return back()->with('success', 'Worker added.');
    }

    public function updateWorker(Request $request, Contractor $contractor, ContractorWorker $worker): RedirectResponse
    {
        abort_unless($request->user()->canManageContractors(), 403);
        abort_unless($worker->contractor_id === $contractor->id, 404);
        $this->assertInCurrentTenant($contractor);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'worker_id_number' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'competency' => ['nullable', 'string', 'max:255'],
            'hse_status' => ['required', Rule::in(ContractorWorker::HSE_STATUSES)],
        ]);

        $worker->update($data);

        return back()->with('success', 'Worker updated.');
    }

    public function destroyWorker(Contractor $contractor, ContractorWorker $worker, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageContractors(), 403);
        abort_unless($worker->contractor_id === $contractor->id, 404);
        $this->assertInCurrentTenant($contractor);

        $worker->delete();

        return back()->with('success', 'Worker removed.');
    }

    private function assertInCurrentTenant(Contractor $contractor): void
    {
        abort_unless(Company::query()->pluck('id')->contains($contractor->company_id), 404);
    }
}
