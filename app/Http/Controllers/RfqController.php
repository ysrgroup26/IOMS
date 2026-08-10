<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRfqRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\RfqVendor;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Workstream C3 (RFQ). Creating an RFQ transitions its parent PR to converted_to_rfq -- see store()'s own comment. */
class RfqController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $rfqs = Rfq::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('purchaseRequisition:id,pr_number', 'buyer:id,name')
            ->withCount('quotations')
            ->when($request->input('search'), fn ($q, $v) => $q->where('rfq_number', 'like', "%{$v}%"))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Rfqs/Index', [
            'rfqs' => $rfqs,
            'filters' => $request->only('search', 'status'),
            'can' => ['manage' => $request->user()->canManageProcurement()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('Rfqs/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'purchaseRequisitions' => PurchaseRequisition::whereIn('company_id', $tenantCompanyIds)
                ->where('status', PurchaseRequisition::STATUS_APPROVED)
                ->get(['id', 'pr_number', 'company_id']),
            'vendors' => Vendor::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'vendor_code', 'company_id']),
            'rfqNumber' => Rfq::generateNumber(),
            'preselectedPrId' => $request->integer('pr') ?: null,
        ]);
    }

    public function store(StoreRfqRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $vendorIds = $data['vendor_ids'];
        unset($data['vendor_ids']);

        $rfq = DB::transaction(function () use ($data, $vendorIds, $request) {
            $rfq = Rfq::create([
                ...$data,
                'rfq_number' => Rfq::generateNumber(),
                'status' => Rfq::STATUS_ISSUED,
                'buyer_id' => $request->user()->id,
            ]);

            foreach ($vendorIds as $vendorId) {
                $rfq->rfqVendors()->create(['vendor_id' => $vendorId, 'status' => RfqVendor::STATUSES[0], 'invited_at' => now()]);
            }

            // PR lifecycle: creating an RFQ IS the "Converted to RFQ" event
            // per the spec's own suggested PR chain -- fires through
            // transitionTo() so the guard/ActivityLog/notification all
            // still apply consistently, not a raw ->update(['status'=>...]).
            $pr = $rfq->purchaseRequisition;
            if ($pr->status === PurchaseRequisition::STATUS_APPROVED) {
                $pr->transitionTo(PurchaseRequisition::STATUS_CONVERTED_TO_RFQ, $request->user());
            }

            return $rfq;
        });

        ActivityLog::record('created', "Issued RFQ {$rfq->rfq_number} to ".count($vendorIds).' vendor(s).', $rfq);

        return redirect()->route('rfqs.show', $rfq)->with('flash', ['success' => 'RFQ issued.']);
    }

    public function show(Rfq $rfq, Request $request): Response
    {
        $this->assertInCurrentTenant($rfq);
        $rfq->load(
            'company:id,name', 'purchaseRequisition:id,pr_number,status', 'buyer:id,name',
            'rfqVendors.vendor:id,name,vendor_code,qualification_status',
            'quotations.vendor:id,name,vendor_code',
            'selectedVendor:id,name,vendor_code', 'selector:id,name'
        );

        $activities = ActivityLog::where('subject_type', Rfq::class)
            ->where('subject_id', $rfq->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('Rfqs/Show', [
            'rfq' => $rfq,
            'activities' => $activities,
            'canManage' => $request->user()->canManageProcurement(),
        ]);
    }

    public function close(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($rfq);

        $rfq->update(['status' => Rfq::STATUS_CLOSED]);
        ActivityLog::record('updated', "Closed RFQ {$rfq->rfq_number}.", $rfq);

        return back()->with('success', 'RFQ closed.');
    }

    /** Quotation Comparison outcome -- records the decision transparently, does NOT auto-pick the cheapest vendor. */
    public function selectVendor(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->canManageProcurement(), 403);
        $this->assertInCurrentTenant($rfq);

        $tenantVendorIds = $rfq->quotations()->pluck('vendor_id');

        $data = $request->validate([
            'selected_vendor_id' => ['required', Rule::in($tenantVendorIds)],
            'evaluation_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $rfq->update([
            ...$data,
            'selected_by' => $request->user()->id,
            'selected_at' => now(),
        ]);

        ActivityLog::record('updated', "Selected vendor for RFQ {$rfq->rfq_number}.", $rfq);

        return back()->with('success', 'Vendor selected.');
    }

    private function assertInCurrentTenant(Rfq $rfq): void
    {
        abort_unless(Company::query()->pluck('id')->contains($rfq->company_id), 404);
    }
}
