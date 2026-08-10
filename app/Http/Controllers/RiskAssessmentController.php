<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRiskAssessmentRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\RiskAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B4 (HIRADC / Risk Assessment). Structurally
 * mirrors IncidentController/SafetyObservationController -- same
 * authorization gate shape (canManageHse()), same transition() endpoint
 * pattern, same tenant-ownership-guard-built-in-from-the-start discipline.
 */
class RiskAssessmentController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $riskAssessments = RiskAssessment::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('project:id,name', 'preparer:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('ra_number', 'like', "%{$v}%")->orWhere('title', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('assessment_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('RiskAssessments/Index', [
            'riskAssessments' => $riskAssessments,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('RiskAssessments/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'raNumber' => RiskAssessment::generateNumber(),
        ]);
    }

    public function store(StoreRiskAssessmentRequest $request): RedirectResponse
    {
        $riskAssessment = RiskAssessment::create([
            ...$request->validated(),
            'ra_number' => RiskAssessment::generateNumber(),
            'status' => RiskAssessment::STATUS_DRAFT,
            'prepared_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Created HIRADC {$riskAssessment->ra_number}.", $riskAssessment);

        return redirect()->route('risk-assessments.show', $riskAssessment)->with('flash', ['success' => 'Risk assessment created.']);
    }

    public function edit(RiskAssessment $riskAssessment, Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($riskAssessment);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('RiskAssessments/Form', [
            'riskAssessment' => $riskAssessment,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'raNumber' => $riskAssessment->ra_number,
        ]);
    }

    public function update(StoreRiskAssessmentRequest $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        $this->assertInCurrentTenant($riskAssessment);

        $riskAssessment->update($request->validated());

        ActivityLog::record('updated', "Updated HIRADC {$riskAssessment->ra_number}.", $riskAssessment);

        return redirect()->route('risk-assessments.show', $riskAssessment)->with('flash', ['success' => 'Risk assessment updated.']);
    }

    public function show(RiskAssessment $riskAssessment, Request $request): Response
    {
        $this->assertInCurrentTenant($riskAssessment);
        $riskAssessment->load('company:id,name', 'project:id,name', 'preparer:id,name', 'reviewer:id,name', 'approver:id,name');

        $activities = ActivityLog::where('subject_type', RiskAssessment::class)
            ->where('subject_id', $riskAssessment->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('RiskAssessments/Show', [
            'riskAssessment' => $riskAssessment,
            'activities' => $activities,
            'canManage' => $request->user()->canManageHse(),
        ]);
    }

    public function transition(Request $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $this->assertInCurrentTenant($riskAssessment);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                RiskAssessment::STATUS_SUBMITTED, RiskAssessment::STATUS_APPROVED,
                RiskAssessment::STATUS_DRAFT, RiskAssessment::STATUS_ARCHIVED, RiskAssessment::STATUS_CANCELLED,
            ])],
        ]);

        try {
            if ($data['status'] === RiskAssessment::STATUS_APPROVED) {
                $riskAssessment->approved_by = $request->user()->id;
                $riskAssessment->save();
            }
            if ($data['status'] === RiskAssessment::STATUS_SUBMITTED) {
                $riskAssessment->reviewed_by = $request->user()->id;
                $riskAssessment->save();
            }
            $riskAssessment->transitionTo($data['status'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Risk assessment '.$data['status'].'.']);
    }

    private function assertInCurrentTenant(RiskAssessment $riskAssessment): void
    {
        abort_unless(Company::query()->pluck('id')->contains($riskAssessment->company_id), 404);
    }
}
