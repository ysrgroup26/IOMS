<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\ControlledDocument;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 6 (Document Control Foundation). */
class ControlledDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $documents = ControlledDocument::whereIn('company_id', $tenantCompanyIds)
            ->with('department:id,name', 'owner:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('title', 'like', "%{$v}%")->orWhere('document_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('ControlledDocuments/Index', [
            'documents' => $documents,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageDocuments()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageDocuments(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('ControlledDocuments/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'departments' => Department::whereIn('company_id', $tenantCompanyIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']),
            'documentNumber' => ControlledDocument::generateNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageDocuments(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantDepartmentIds = Department::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', Rule::in($tenantDepartmentIds)],
            'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,dwg', 'max:20480'],
        ]);

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store('uploads/controlled-documents', 'public');
        }

        $document = ControlledDocument::create([
            ...$data,
            'document_number' => ControlledDocument::generateNumber(),
            'owner_id' => $request->user()->id,
            'version' => '1.0',
            'file_path' => $filePath,
            'status' => ControlledDocument::STATUS_DRAFT,
        ]);

        if ($filePath) {
            $document->versions()->create([
                'version' => '1.0', 'file_path' => $filePath,
                'original_name' => $request->file('file')->getClientOriginalName(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        ActivityLog::record('created', "Created controlled document \"{$document->title}\" ({$document->document_number}).", $document);

        return redirect()->route('controlled-documents.show', $document)->with('flash', ['success' => 'Document created.']);
    }

    public function show(ControlledDocument $controlledDocument, Request $request): Response
    {
        $this->assertInCurrentTenant($controlledDocument);
        $controlledDocument->load('department:id,name', 'owner:id,name', 'versions.uploader:id,name');

        $activities = ActivityLog::where('subject_type', ControlledDocument::class)
            ->where('subject_id', $controlledDocument->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('ControlledDocuments/Show', [
            'document' => $controlledDocument,
            'activities' => $activities,
            'canManage' => $request->user()->canManageDocuments(),
        ]);
    }

    /** New revision -- keeps the previous version's row, bumps the header's current version/file. */
    public function storeVersion(Request $request, ControlledDocument $controlledDocument): RedirectResponse
    {
        abort_unless($request->user()->canManageDocuments(), 403);
        $this->assertInCurrentTenant($controlledDocument);

        $data = $request->validate([
            'version' => ['required', 'string', 'max:20'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,dwg', 'max:20480'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $path = $request->file('file')->store('uploads/controlled-documents', 'public');

        $controlledDocument->versions()->create([
            'version' => $data['version'], 'file_path' => $path,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'uploaded_by' => $request->user()->id, 'notes' => $data['notes'] ?? null,
        ]);

        $controlledDocument->update(['version' => $data['version'], 'file_path' => $path]);

        ActivityLog::record('updated', "Uploaded revision {$data['version']} of \"{$controlledDocument->title}\".", $controlledDocument);

        return back()->with('success', 'New revision uploaded.');
    }

    public function transition(Request $request, ControlledDocument $controlledDocument): RedirectResponse
    {
        abort_unless($request->user()->canManageDocuments(), 403);
        $this->assertInCurrentTenant($controlledDocument);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                ControlledDocument::STATUS_REVIEW, ControlledDocument::STATUS_APPROVED, ControlledDocument::STATUS_DRAFT,
                ControlledDocument::STATUS_EFFECTIVE, ControlledDocument::STATUS_OBSOLETE,
            ])],
        ]);

        try {
            if ($data['status'] === ControlledDocument::STATUS_EFFECTIVE) {
                $controlledDocument->effective_date = now()->toDateString();
                $controlledDocument->save();
            }
            $controlledDocument->transitionTo($data['status'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Document '.$data['status'].'.']);
    }

    private function assertInCurrentTenant(ControlledDocument $document): void
    {
        abort_unless(Company::query()->pluck('id')->contains($document->company_id), 404);
    }
}
