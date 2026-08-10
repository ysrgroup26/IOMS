<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePermitToWorkRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\JobSafetyAnalysis;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\RiskAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B6 (Permit To Work). Structurally mirrors
 * RiskAssessmentController -- same authorization/tenant-guard shape.
 */
class PermitToWorkController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $permits = PermitToWork::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'requester:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('ptw_number', 'like', "%{$v}%")->orWhere('work_description', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('permit_type', $v))
            ->latest('start_datetime')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('PermitsToWork/Index', [
            'permits' => $permits,
            'filters' => $request->only('search', 'status', 'type'),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('PermitsToWork/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'riskAssessments' => RiskAssessment::whereIn('company_id', $tenantCompanyIds)->where('status', RiskAssessment::STATUS_APPROVED)->get(['id', 'ra_number', 'title']),
            'jsas' => JobSafetyAnalysis::whereIn('company_id', $tenantCompanyIds)->where('status', JobSafetyAnalysis::STATUS_APPROVED)->get(['id', 'jsa_number', 'job_title']),
            'ptwNumber' => PermitToWork::generateNumber(),
            'types' => PermitToWork::TYPES,
        ]);
    }

    public function store(StorePermitToWorkRequest $request): RedirectResponse
    {
        $permit = PermitToWork::create([
            ...$request->validated(),
            'ptw_number' => PermitToWork::generateNumber(),
            'status' => PermitToWork::STATUS_DRAFT,
            'requested_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Requested Permit To Work {$permit->ptw_number}.", $permit);

        return redirect()->route('permits-to-work.show', $permit)->with('flash', ['success' => 'Permit To Work created.']);
    }

    public function show(PermitToWork $permitToWork, Request $request): Response
    {
        $this->assertInCurrentTenant($permitToWork);
        $permitToWork->load(
            'company:id,name', 'project:id,name', 'riskAssessment:id,ra_number', 'jsa:id,jsa_number',
            'requester:id,name', 'areaAuthority:id,name', 'hseApprover:id,name', 'closer:id,name',
            'gasTests.tester:id,name', 'lotoRecords'
        );

        $activities = ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permitToWork->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('PermitsToWork/Show', [
            'permit' => $permitToWork,
            'activities' => $activities,
            'canManage' => $request->user()->canManageHse(),
        ]);
    }

    public function transition(Request $request, PermitToWork $permitToWork): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($permitToWork);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                PermitToWork::STATUS_SUBMITTED, PermitToWork::STATUS_APPROVED, PermitToWork::STATUS_REJECTED,
                PermitToWork::STATUS_ACTIVE, PermitToWork::STATUS_CLOSED, PermitToWork::STATUS_CANCELLED,
            ])],
        ]);

        try {
            if ($data['status'] === PermitToWork::STATUS_APPROVED) {
                $permitToWork->hse_approver_id = $request->user()->id;
                $permitToWork->save();
            }
            if ($data['status'] === PermitToWork::STATUS_CLOSED) {
                $permitToWork->closed_by = $request->user()->id;
                $permitToWork->closed_at = now();
                $permitToWork->save();
            }
            $permitToWork->transitionTo($data['status'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Permit To Work '.$data['status'].'.']);
    }

    private function assertInCurrentTenant(PermitToWork $permitToWork): void
    {
        abort_unless(Company::query()->pluck('id')->contains($permitToWork->company_id), 404);
    }
}
