<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\HandoverRecord;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use App\Services\DocumentEngine;
use App\Services\NumberGeneratorService;
use App\Services\PdfGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.52.0 -- BAST (Berita Acara Serah Terima).
 *
 * A formal handover instrument, deliberately separate from Goods Receipt.
 * A completed Work Order handed over to the asset owner needs a BAST and
 * moves no stock at all; a pallet of electrodes booked into the warehouse
 * needs a Goods Receipt and no BAST. See the create_handover_records
 * migration for the full reasoning.
 *
 * REUSES rather than duplicates: `source_type`/`source_id` point at the
 * Work Order, Purchase Order, Goods Receipt or Project that already holds
 * the business data, and the morph target is validated against a closed
 * allow-list so a crafted request cannot aim it at an arbitrary model.
 */
class HandoverRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $companyIds = Company::query()->pluck('id');

        $query = HandoverRecord::whereIn('company_id', $companyIds)
            ->with('company:id,name', 'creator:id,name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('bast_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('second_party_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('handover_type')) {
            $query->where('handover_type', $type);
        }

        return Inertia::render('HandoverRecords/Index', [
            'records' => $query->latest('handover_date')->latest('id')->paginate(25)->withQueryString()
                ->through(fn (HandoverRecord $r) => [
                    'id' => $r->id,
                    'bast_number' => $r->bast_number,
                    'title' => $r->title,
                    'handover_type' => $r->handover_type,
                    'type_label' => $r->typeLabel(),
                    'handover_date' => $r->handover_date,
                    'first_party_name' => $r->first_party_name,
                    'second_party_name' => $r->second_party_name,
                    'second_party_organization' => $r->second_party_organization,
                    'reference_number' => $r->reference_number,
                    'status' => $r->status,
                    'company' => $r->company?->name,
                ]),
            'filters' => $request->only(['search', 'status', 'handover_type']),
            'statuses' => HandoverRecord::STATUSES,
            'types' => collect(HandoverRecord::TYPES)->map(fn ($t) => [
                'value' => $t, 'label' => HandoverRecord::TYPE_LABELS[$t],
            ])->values(),
            'companies' => Company::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::whereIn('company_id', $companyIds)->active()
                ->orderBy('full_name')->get(['id', 'full_name']),
            'sources' => $this->availableSources($companyIds),
            'canManage' => $request->user()->canManageSystemSettings() || $request->user()->isManager(),
        ]);
    }

    public function show(Request $request, HandoverRecord $handoverRecord): Response
    {
        $this->assertInCurrentTenant($handoverRecord);
        $handoverRecord->load('company:id,name', 'creator:id,name', 'acceptor:id,name', 'firstPartyEmployee:id,full_name');

        return Inertia::render('HandoverRecords/Show', [
            'record' => [
                ...$handoverRecord->toArray(),
                'type_label' => $handoverRecord->typeLabel(),
                'statement' => $handoverRecord->statement(),
                'company_name' => $handoverRecord->company?->name,
                'creator_name' => $handoverRecord->creator?->name,
            ],
            'canManage' => $request->user()->canManageSystemSettings() || $request->user()->isManager(),
        ]);
    }

    public function store(Request $request, NumberGeneratorService $numbers): RedirectResponse
    {
        $this->assertCanManage($request);

        $validated = $this->validated($request);
        $validated['bast_number'] = $numbers->generate('handover_record', $validated['company_id']);
        $validated['created_by'] = $request->user()->id;

        $record = HandoverRecord::create($validated);

        ActivityLog::record('created', "BAST {$record->bast_number} was created.");

        return redirect()->route('handover-records.show', $record->id)
            ->with('success', 'BAST dibuat.');
    }

    public function update(Request $request, HandoverRecord $handoverRecord): RedirectResponse
    {
        $this->assertCanManage($request);
        $this->assertInCurrentTenant($handoverRecord);

        $handoverRecord->update($this->validated($request, $handoverRecord));

        ActivityLog::record('updated', "BAST {$handoverRecord->bast_number} was updated.");

        return back()->with('success', 'BAST diperbarui.');
    }

    /**
     * Records that the second party accepted the handover.
     *
     * The acceptor is derived from the authenticated user, never from the
     * request — "who accepted this" is exactly the field a client must not
     * be able to choose.
     */
    public function accept(Request $request, HandoverRecord $handoverRecord): RedirectResponse
    {
        $this->assertCanManage($request);
        $this->assertInCurrentTenant($handoverRecord);

        $handoverRecord->update([
            'status' => HandoverRecord::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_by' => $request->user()->id,
        ]);

        ActivityLog::record('updated', "BAST {$handoverRecord->bast_number} was accepted.");

        return back()->with('success', 'BAST ditandai diterima.');
    }

    public function destroy(Request $request, HandoverRecord $handoverRecord): RedirectResponse
    {
        $this->assertCanManage($request);
        $this->assertInCurrentTenant($handoverRecord);

        // An accepted BAST is evidence. Deleting one would remove the
        // record of a handover both parties signed off, so it is refused --
        // correct it by issuing a replacement, the way any controlled
        // document is corrected.
        abort_if($handoverRecord->status === HandoverRecord::STATUS_ACCEPTED, 422, 'A BAST that has been accepted cannot be deleted.');

        $number = $handoverRecord->bast_number;
        $handoverRecord->delete();

        ActivityLog::record('deleted', "BAST {$number} was deleted.");

        return redirect()->route('handover-records.index')->with('success', 'BAST dihapus.');
    }

    /** The formal document, on the tenant's own letterhead. */
    public function pdf(HandoverRecord $handoverRecord, PdfGeneratorService $pdf, DocumentEngine $documents): \Illuminate\Http\Response
    {
        $this->assertInCurrentTenant($handoverRecord);
        $handoverRecord->load('company', 'creator', 'acceptor', 'firstPartyEmployee');

        return $pdf->streamInline('pdf.handover-record', [
            'record' => $handoverRecord,
            'identity' => $documents->identity(),
            'documentTemplate' => $documents->resolveTemplate('handover_record', $handoverRecord->company_id),
        ], "{$handoverRecord->bast_number}.pdf");
    }

    private function validated(Request $request, ?HandoverRecord $existing = null): array
    {
        $companyIds = Company::query()->pluck('id');
        $employeeIds = Employee::whereIn('company_id', $companyIds)->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($companyIds)],
            'handover_type' => ['required', Rule::in(HandoverRecord::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'handover_date' => ['required', 'date'],

            'first_party_name' => ['required', 'string', 'max:255'],
            'first_party_position' => ['nullable', 'string', 'max:255'],
            'first_party_organization' => ['nullable', 'string', 'max:255'],
            'first_party_employee_id' => ['nullable', Rule::in($employeeIds)],

            'second_party_name' => ['required', 'string', 'max:255'],
            'second_party_position' => ['nullable', 'string', 'max:255'],
            'second_party_organization' => ['nullable', 'string', 'max:255'],

            // A closed allow-list of morph keys, resolved to a class below.
            // Without this, `source_type` would accept any model name.
            'source_key' => ['nullable', Rule::in(array_keys(HandoverRecord::SOURCE_TYPES))],
            'source_id' => ['nullable', 'integer'],
            'reference_number' => ['nullable', 'string', 'max:255'],

            'scope' => ['nullable', 'string', 'max:4000'],
            'acceptance_statement' => ['nullable', 'string', 'max:4000'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['required_with:items', 'string', 'max:500'],
            'items.*.quantity' => ['nullable', 'string', 'max:50'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(HandoverRecord::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $sourceKey = $data['source_key'] ?? null;
        unset($data['source_key']);

        $data['source_type'] = null;
        if ($sourceKey && ! empty($data['source_id'])) {
            $class = HandoverRecord::SOURCE_TYPES[$sourceKey];

            // The referenced record must belong to THIS tenant. Validating
            // the id inside its own model keeps this correct even for
            // sources whose company link is indirect.
            abort_unless(
                $this->sourceBelongsToTenant($class, (int) $data['source_id'], $companyIds),
                422,
                'The referenced document does not belong to this company.'
            );

            $data['source_type'] = $class;
        } else {
            $data['source_id'] = null;
        }

        return $data;
    }

    private function sourceBelongsToTenant(string $class, int $id, $companyIds): bool
    {
        return match ($class) {
            WorkOrder::class => WorkOrder::whereKey($id)->whereIn('company_id', $companyIds)->exists(),
            PurchaseOrder::class => PurchaseOrder::whereKey($id)->whereIn('company_id', $companyIds)->exists(),
            Project::class => Project::whereKey($id)->whereIn('company_id', $companyIds)->exists(),
            // Goods Receipt has no company_id of its own -- it inherits
            // ownership from the PO, material request or warehouse it was
            // booked against, so ownership is resolved one hop out.
            GoodsReceipt::class => GoodsReceipt::whereKey($id)->whereHas('purchaseOrder', fn ($q) => $q->whereIn('company_id', $companyIds))->exists()
                || GoodsReceipt::whereKey($id)->whereHas('warehouse', fn ($q) => $q->whereIn('company_id', $companyIds))->exists()
                || GoodsReceipt::whereKey($id)->whereHas('materialRequest', fn ($q) => $q->whereIn('company_id', $companyIds))->exists(),
            default => false,
        };
    }

    /** Recent, tenant-scoped candidates a BAST can be raised against. */
    private function availableSources($companyIds): array
    {
        return [
            'work_order' => WorkOrder::whereIn('company_id', $companyIds)->latest()->limit(50)
                ->get(['id', 'wo_number as label'])->toArray(),
            'purchase_order' => PurchaseOrder::whereIn('company_id', $companyIds)->latest()->limit(50)
                ->get(['id', 'po_number as label'])->toArray(),
            'project' => Project::whereIn('company_id', $companyIds)->orderBy('name')->limit(100)
                ->get(['id', 'name as label'])->toArray(),
        ];
    }

    /**
     * A BAST commits the company contractually, so it is not open to every
     * authenticated account. Management and Super Admin may raise one --
     * the same people who already own Work Orders and Purchase Orders.
     */
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageSystemSettings() || $request->user()->isManager(), 403);
    }

    private function assertInCurrentTenant(HandoverRecord $record): void
    {
        abort_unless(Company::query()->pluck('id')->contains($record->company_id), 404);
    }
}
