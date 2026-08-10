<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobSafetyAnalysisRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\JobSafetyAnalysis;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Workstream B5 (JSA). Mirrors RiskAssessmentController exactly. */
class JobSafetyAnalysisController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $jsas = JobSafetyAnalysis::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'preparer:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('jsa_number', 'like', "%{$v}%")->orWhere('job_title', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('jsa_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('JobSafetyAnalyses/Index', [
            'jsas' => $jsas,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('JobSafetyAnalyses/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'jsaNumber' => JobSafetyAnalysis::generateNumber(),
        ]);
    }

    public function store(StoreJobSafetyAnalysisRequest $request): RedirectResponse
    {
        $jsa = JobSafetyAnalysis::create([
            ...$request->validated(),
            'jsa_number' => JobSafetyAnalysis::generateNumber(),
            'status' => JobSafetyAnalysis::STATUS_DRAFT,
            'prepared_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Created JSA {$jsa->jsa_number}.", $jsa);

        return redirect()->route('job-safety-analyses.show', $jsa)->with('flash', ['success' => 'JSA created.']);
    }

    public function edit(JobSafetyAnalysis $jobSafetyAnalysis, Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($jobSafetyAnalysis);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('JobSafetyAnalyses/Form', [
            'jsa' => $jobSafetyAnalysis,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'jsaNumber' => $jobSafetyAnalysis->jsa_number,
        ]);
    }

    public function update(StoreJobSafetyAnalysisRequest $request, JobSafetyAnalysis $jobSafetyAnalysis): RedirectResponse
    {
        $this->assertInCurrentTenant($jobSafetyAnalysis);

        $jobSafetyAnalysis->update($request->validated());

        ActivityLog::record('updated', "Updated JSA {$jobSafetyAnalysis->jsa_number}.", $jobSafetyAnalysis);

        return redirect()->route('job-safety-analyses.show', $jobSafetyAnalysis)->with('flash', ['success' => 'JSA updated.']);
    }

    public function show(JobSafetyAnalysis $jobSafetyAnalysis, Request $request): Response
    {
        $this->assertInCurrentTenant($jobSafetyAnalysis);
        $jobSafetyAnalysis->load('company:id,name', 'project:id,name', 'preparer:id,name', 'reviewer:id,name', 'approver:id,name');

        $activities = ActivityLog::where('subject_type', JobSafetyAnalysis::class)
            ->where('subject_id', $jobSafetyAnalysis->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('JobSafetyAnalyses/Show', [
            'jsa' => $jobSafetyAnalysis,
            'activities' => $activities,
            'canManage' => $request->user()->canManageHse(),
        ]);
    }

    public function transition(Request $request, JobSafetyAnalysis $jobSafetyAnalysis): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($jobSafetyAnalysis);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                JobSafetyAnalysis::STATUS_SUBMITTED, JobSafetyAnalysis::STATUS_APPROVED,
                JobSafetyAnalysis::STATUS_DRAFT, JobSafetyAnalysis::STATUS_ARCHIVED, JobSafetyAnalysis::STATUS_CANCELLED,
            ])],
        ]);

        try {
            if ($data['status'] === JobSafetyAnalysis::STATUS_APPROVED) {
                $jobSafetyAnalysis->approved_by = $request->user()->id;
                $jobSafetyAnalysis->save();
            }
            if ($data['status'] === JobSafetyAnalysis::STATUS_SUBMITTED) {
                $jobSafetyAnalysis->reviewed_by = $request->user()->id;
                $jobSafetyAnalysis->save();
            }
            $jobSafetyAnalysis->transitionTo($data['status'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'JSA '.$data['status'].'.']);
    }

    private function assertInCurrentTenant(JobSafetyAnalysis $jobSafetyAnalysis): void
    {
        abort_unless(Company::query()->pluck('id')->contains($jobSafetyAnalysis->company_id), 404);
    }
}
