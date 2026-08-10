<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\InspectionRequest;
use App\Models\Project;
use App\Models\ProjectActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 3 (QC Foundation). */
class InspectionRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $inspections = InspectionRequest::whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'inspector:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('inspection_number', 'like', "%{$v}%"))
            ->when($request->input('result'), fn ($q, $v) => $q->where('result', $v))
            ->latest('inspection_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('InspectionRequests/Index', [
            'inspections' => $inspections,
            'filters' => $request->only('search', 'result'),
            'can' => ['manage' => $request->user()->canManageProjects()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageProjects(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('InspectionRequests/Form', [
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'activities' => ProjectActivity::whereHas('project', fn ($q) => $q->whereIn('company_id', $tenantCompanyIds))->get(['id', 'name', 'project_id']),
            'inspectionNumber' => InspectionRequest::generateNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageProjects(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'project_id' => ['required', Rule::in($tenantProjectIds)],
            'project_activity_id' => ['nullable', 'integer'],
            'inspection_date' => ['required', 'date'],
        ]);

        if (! empty($data['project_activity_id'])) {
            $valid = ProjectActivity::where('project_id', $data['project_id'])->pluck('id');
            if (! $valid->contains($data['project_activity_id'])) {
                abort(422, 'Activity does not belong to the selected project.');
            }
        }

        $project = Project::find($data['project_id']);

        $inspection = InspectionRequest::create([
            ...$data,
            'inspection_number' => InspectionRequest::generateNumber(),
            'company_id' => $project->company_id,
            'inspector_id' => $request->user()->id,
            'status' => InspectionRequest::STATUS_REQUESTED,
        ]);

        ActivityLog::record('created', "Requested QC Inspection {$inspection->inspection_number}.", $inspection);

        return redirect()->route('inspection-requests.show', $inspection)->with('flash', ['success' => 'Inspection requested.']);
    }

    public function show(InspectionRequest $inspectionRequest, Request $request): Response
    {
        $this->assertInCurrentTenant($inspectionRequest);
        $inspectionRequest->load('project:id,name', 'activity:id,name', 'inspector:id,name', 'evidence');

        return Inertia::render('InspectionRequests/Show', [
            'inspection' => $inspectionRequest,
            'canManage' => $request->user()->canManageProjects(),
        ]);
    }

    public function recordResult(Request $request, InspectionRequest $inspectionRequest): RedirectResponse
    {
        abort_unless($request->user()->canManageProjects(), 403);
        $this->assertInCurrentTenant($inspectionRequest);

        $data = $request->validate([
            'result' => ['required', Rule::in([InspectionRequest::RESULT_PASSED, InspectionRequest::RESULT_FAILED])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $photos = $request->file('photos', []);
        unset($data['photos']);

        $inspectionRequest->update([...$data, 'status' => InspectionRequest::STATUS_COMPLETED]);

        foreach ($photos as $photo) {
            $path = $photo->store('uploads/inspection-evidence', 'public');
            $inspectionRequest->evidence()->create(['photo_path' => $path]);
        }

        ActivityLog::record('updated', "QC Inspection {$inspectionRequest->inspection_number} recorded as {$data['result']}.", $inspectionRequest);

        return back()->with('success', 'Inspection result recorded.');
    }

    private function assertInCurrentTenant(InspectionRequest $inspection): void
    {
        abort_unless(Company::query()->pluck('id')->contains($inspection->company_id), 404);
    }
}
