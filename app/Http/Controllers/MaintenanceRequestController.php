<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\MaintenanceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Acceleration Part 2 (Maintenance CMMS Foundation). */
class MaintenanceRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $requests = MaintenanceRequest::whereIn('company_id', $tenantCompanyIds)
            ->with('asset:id,name,asset_code', 'reporter:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('request_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('request_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('MaintenanceRequests/Index', [
            'requests' => $requests,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageAssets()],
        ]);
    }

    public function create(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('MaintenanceRequests/Form', [
            'assets' => Asset::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'asset_code', 'company_id']),
            'requestNumber' => MaintenanceRequest::generateNumber(),
            'priorities' => MaintenanceRequest::PRIORITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantAssetIds = Asset::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'asset_id' => ['required', Rule::in($tenantAssetIds)],
            'problem' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(MaintenanceRequest::PRIORITIES)],
            'request_date' => ['required', 'date'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $asset = Asset::find($data['asset_id']);
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('uploads/maintenance-requests', 'public');
        }
        unset($data['attachment']);

        $mr = MaintenanceRequest::create([
            ...$data,
            'request_number' => MaintenanceRequest::generateNumber(),
            'company_id' => $asset->company_id,
            'status' => MaintenanceRequest::STATUS_REPORTED,
            'reported_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Reported maintenance request {$mr->request_number} for \"{$asset->name}\".", $mr);

        return redirect()->route('maintenance-requests.show', $mr)->with('flash', ['success' => 'Maintenance request reported.']);
    }

    public function show(MaintenanceRequest $maintenanceRequest, Request $request): Response
    {
        $this->assertInCurrentTenant($maintenanceRequest);
        $maintenanceRequest->load('asset:id,name,asset_code', 'reporter:id,name', 'workOrders:id,wo_number,maintenance_request_id,status');

        $activities = ActivityLog::where('subject_type', MaintenanceRequest::class)
            ->where('subject_id', $maintenanceRequest->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('MaintenanceRequests/Show', [
            'maintenanceRequest' => $maintenanceRequest,
            'activities' => $activities,
            'canManage' => $request->user()->canManageAssets(),
        ]);
    }

    public function transition(Request $request, MaintenanceRequest $maintenanceRequest): RedirectResponse
    {
        abort_unless($request->user()->canManageAssets(), 403);
        $this->assertInCurrentTenant($maintenanceRequest);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                MaintenanceRequest::STATUS_APPROVED, MaintenanceRequest::STATUS_REJECTED,
                MaintenanceRequest::STATUS_REPORTED, MaintenanceRequest::STATUS_CANCELLED,
            ])],
        ]);

        try {
            $maintenanceRequest->transitionTo($data['status'], $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Maintenance request '.$data['status'].'.']);
    }

    private function assertInCurrentTenant(MaintenanceRequest $mr): void
    {
        abort_unless(Company::query()->pluck('id')->contains($mr->company_id), 404);
    }
}
