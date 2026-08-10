<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Vendor;
use App\Models\VendorDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream C1 (Vendor/Supplier Master). Structurally
 * mirrors SafetyObservationController/HazardCategoryController -- same
 * canManageProcurement() gate shape, same assertInCurrentTenant() guard
 * pattern built in from the start (not retrofitted).
 */
class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $vendors = Vendor::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->withCount('purchaseOrders')
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('vendor_code', 'like', "%{$v}%"))
            ->when($request->input('qualification_status'), fn ($q, $v) => $q->where('qualification_status', $v))
            ->when($request->input('category'), fn ($q, $v) => $q->where('category', $v))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
            'filters' => $request->only('search', 'qualification_status', 'category'),
            'can' => ['manage' => $request->user()->canManageProcurement()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageProcurement(), 403);

        return Inertia::render('Vendors/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'types' => Vendor::TYPES,
            'vendorCode' => Vendor::generateVendorCode(),
        ]);
    }

    public function store(StoreVendorRequest $request): RedirectResponse
    {
        $vendor = Vendor::create([
            ...$request->validated(),
            'vendor_code' => Vendor::generateVendorCode(),
            'qualification_status' => Vendor::QUALIFICATION_DRAFT,
        ]);

        ActivityLog::record('created', "Created vendor \"{$vendor->name}\" ({$vendor->vendor_code}).", $vendor);

        return redirect()->route('vendors.show', $vendor)->with('flash', ['success' => 'Vendor added.']);
    }

    public function edit(Vendor $vendor, Request $request): Response
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($vendor);

        return Inertia::render('Vendors/Form', [
            'vendor' => $vendor,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'types' => Vendor::TYPES,
            'vendorCode' => $vendor->vendor_code,
        ]);
    }

    public function update(StoreVendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->assertInCurrentTenant($vendor);

        $vendor->update($request->validated());
        ActivityLog::record('updated', "Updated vendor \"{$vendor->name}\".", $vendor);

        return redirect()->route('vendors.show', $vendor)->with('flash', ['success' => 'Vendor updated.']);
    }

    public function show(Vendor $vendor, Request $request): Response
    {
        $this->assertInCurrentTenant($vendor);
        $vendor->load('company:id,name', 'reviewer:id,name', 'documents.uploader:id,name');

        $activities = ActivityLog::where('subject_type', Vendor::class)
            ->where('subject_id', $vendor->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('Vendors/Show', [
            'vendor' => $vendor,
            'activities' => $activities,
            'canManage' => $request->user()->canManageProcurement(),
            'documentTypes' => VendorDocument::TYPES,
        ]);
    }

    /** Vendor Qualification (Workstream C1/C2 spec area) -- review decision, states enforced directly on the Vendor row (see its migration's own doc comment on why no separate checklist table). */
    public function reviewQualification(Request $request, Vendor $vendor): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($vendor);

        $data = $request->validate([
            'qualification_status' => ['required', Rule::in([
                Vendor::QUALIFICATION_UNDER_REVIEW, Vendor::QUALIFICATION_QUALIFIED, Vendor::QUALIFICATION_CONDITIONAL,
                Vendor::QUALIFICATION_REJECTED, Vendor::QUALIFICATION_SUSPENDED,
            ])],
            'qualified_until' => ['nullable', 'date'],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $vendor->update([
            ...$data,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        ActivityLog::record('updated', "Vendor \"{$vendor->name}\" qualification set to {$data['qualification_status']}.", $vendor);

        return back()->with('success', 'Vendor qualification updated.');
    }

    public function storeDocument(Request $request, Vendor $vendor): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($vendor);

        $data = $request->validate([
            'document_type' => ['required', Rule::in(VendorDocument::TYPES)],
            'expiry_date' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $path = $request->file('file')->store('uploads/vendor-documents', 'public');

        $vendor->documents()->create([
            'document_type' => $data['document_type'],
            'file_path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'expiry_date' => $data['expiry_date'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Uploaded a {$data['document_type']} document for vendor \"{$vendor->name}\".", $vendor);

        return back()->with('success', 'Document uploaded.');
    }

    public function destroyDocument(Vendor $vendor, VendorDocument $document, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        abort_unless($document->vendor_id === $vendor->id, 404);
        $this->assertInCurrentTenant($vendor);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    private function assertInCurrentTenant(Vendor $vendor): void
    {
        abort_unless(Company::query()->pluck('id')->contains($vendor->company_id), 404);
    }
}
