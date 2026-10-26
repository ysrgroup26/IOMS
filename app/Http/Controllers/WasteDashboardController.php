<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WasteMovement;
use App\Models\WasteRecord;
use App\Models\WasteStorageLocation;
use App\Models\WasteType;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v1.11.4 (HSE Waste Management, Part 18). Every KPI is a real, tenant-
 * scoped count over actual WasteRecord/WasteMovement/WasteType rows --
 * no fabricated metric. Storage-threshold alerts reuse
 * WasteRecord::is_approaching_storage_limit/is_storage_overdue, both
 * driven entirely by the tenant's own configured WasteType.storage_limit_days
 * (an operational setting, not a legal determination -- see that model's
 * own doc comment).
 *
 * v2.134.0 (ADR 077) -- THE DASHBOARD IS THE WORKSPACE. Every figure,
 * lifecycle stage and priority item is a FILTER of the records listed on
 * the same page, so a number is always one click from the rows it counts.
 * Two limits are kept apart on purpose: how LONG waste may stay (the
 * type's storage_limit_days, configured by the company) and how MUCH a
 * TPS can hold (the location's declared capacity). Neither is a
 * regulatory verdict; nothing here marks anything "compliant".
 */
class WasteDashboardController extends Controller
{
    private const PER_PAGE = 20;

    /** Share of declared capacity at which a TPS is flagged as filling up. A display threshold, not a regulation. */
    public const CAPACITY_WARNING_RATIO = 0.8;

    /** Summary views: each is a filter of the records list. */
    public const VIEWS = ['b3_stored', 'non_b3_stored', 'pickup_transit', 'done', 'approaching', 'overdue', 'incomplete', 'onsite', 'ready_for_pickup'];

    public function index(Request $request): Response
    {
        $companyIds = Company::query()->pluck('id');
        $today = Carbon::today();

        $records = WasteRecord::whereIn('company_id', $companyIds)
            ->with([
                'wasteType:id,name,category,waste_code,storage_limit_days,unit',
                'storageLocation:id,name,code,capacity,capacity_unit,status',
                'project:id,name',
                'movements' => fn ($q) => $q->select('id', 'waste_record_id', 'vendor_id', 'manifest_number', 'pickup_date', 'disposal_date', 'status')
                    ->with('vendor:id,name')->withCount('documents'),
            ])
            ->get();

        $rows = $records->map(fn (WasteRecord $r) => $this->row($r, $today));
        $stored = $rows->filter(fn ($r) => in_array($r['status'], WasteRecord::STORED_STATUSES, true));

        $filters = $this->filters($request);
        $filtered = $this->applyFilters($rows, $filters)
            ->sortBy([
                // What needs action first, then the oldest.
                fn ($a, $b) => $b['priority'] <=> $a['priority'],
                fn ($a, $b) => ($a['generated_date'] ?? '') <=> ($b['generated_date'] ?? ''),
            ])->values();

        $page = max(1, (int) $request->query('page', 1));

        return Inertia::render('Hse/WasteManagement/Dashboard', [
            'summary' => [
                'total' => $rows->count(),
                'b3_stored' => $stored->where('category', WasteType::CATEGORY_B3)->count(),
                'non_b3_stored' => $stored->where('category', WasteType::CATEGORY_NON_B3)->count(),
                'pickup_transit' => $rows->whereIn('status', [WasteRecord::STATUS_SCHEDULED_PICKUP, WasteRecord::STATUS_IN_TRANSIT])->count(),
                'done' => $rows->whereIn('status', [WasteRecord::STATUS_DISPOSED, WasteRecord::STATUS_CLOSED])->count(),
                'approaching' => $rows->where('storage_state', 'approaching')->count(),
                'overdue' => $rows->where('storage_state', 'overdue')->count(),
                'incomplete' => $rows->filter(fn ($r) => $r['gaps'] !== [])->count(),
            ],
            'lifecycle' => collect(WasteRecord::STATUSES)->map(fn ($s) => [
                'status' => $s,
                'count' => $rows->where('status', $s)->count(),
            ])->values(),
            // Records that went from generation straight to pickup, never into a TPS:
            // the lifecycle is not the same for every waste stream.
            'directPickupCount' => $rows->where('skipped_storage', true)->count(),
            'priorities' => $this->priorities($rows),
            'storage' => $this->storage($companyIds, $stored),
            'recentMovements' => WasteMovement::whereIn('company_id', $companyIds)
                ->with('wasteRecord:id,record_number,waste_type_id', 'wasteRecord.wasteType:id,name,category', 'vendor:id,name')
                ->withCount('documents')
                ->latest('id')
                ->limit(8)
                ->get(['id', 'waste_record_id', 'vendor_id', 'manifest_number', 'status', 'pickup_date', 'disposal_date', 'destination']),
            'records' => new LengthAwarePaginator(
                $filtered->forPage($page, self::PER_PAGE)->values(),
                $filtered->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'filters' => $filters,
            'options' => [
                'wasteTypes' => WasteType::whereIn('company_id', $companyIds)->orderBy('name')->get(['id', 'name', 'category', 'waste_code']),
                'storageLocations' => WasteStorageLocation::whereIn('company_id', $companyIds)->orderBy('name')->get(['id', 'name', 'code']),
                'statuses' => WasteRecord::STATUSES,
            ],
            'capacityWarningPercent' => (int) (self::CAPACITY_WARNING_RATIO * 100),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    /** One record as the workspace needs it; every flag is derived from stored facts. */
    private function row(WasteRecord $r, Carbon $today): array
    {
        $latest = $r->movements->first(); // relation is ordered latest('id')
        $category = $r->wasteType?->category;
        $storageState = $r->is_storage_overdue ? 'overdue' : ($r->is_approaching_storage_limit ? 'approaching' : null);
        $limit = $r->wasteType?->storage_limit_days;
        $daysLeft = ($limit !== null && $r->days_in_storage !== null) ? $limit - $r->days_in_storage : null;

        // MISSING DATA, stated as such -- never a compliance verdict.
        $gaps = [];
        if (in_array($r->status, [WasteRecord::STATUS_STORED, WasteRecord::STATUS_SCHEDULED_PICKUP], true) && ! $r->storage_location_id) {
            $gaps[] = 'no_storage_location';
        }
        if ($category === WasteType::CATEGORY_B3 && ! $r->wasteType?->waste_code) {
            $gaps[] = 'no_waste_code';
        }
        if (in_array($r->status, [WasteRecord::STATUS_SCHEDULED_PICKUP, WasteRecord::STATUS_IN_TRANSIT, WasteRecord::STATUS_DISPOSED], true)) {
            if (! $latest?->vendor_id) {
                $gaps[] = 'no_vendor';
            }
            if ($category === WasteType::CATEGORY_B3 && in_array($r->status, [WasteRecord::STATUS_IN_TRANSIT, WasteRecord::STATUS_DISPOSED], true) && ! $latest?->manifest_number) {
                $gaps[] = 'no_manifest';
            }
        }
        if (in_array($r->status, [WasteRecord::STATUS_IN_TRANSIT, WasteRecord::STATUS_DISPOSED, WasteRecord::STATUS_CLOSED], true)
            && $r->movements->sum('documents_count') === 0) {
            $gaps[] = 'no_handover_document';
        }

        return [
            'id' => $r->id,
            'record_number' => $r->record_number,
            'company_id' => $r->company_id,
            'status' => $r->status,
            'category' => $category,
            'waste_type_id' => $r->waste_type_id,
            'waste_type' => $r->wasteType?->name,
            'waste_code' => $r->wasteType?->waste_code,
            'quantity' => $r->quantity !== null ? (float) $r->quantity : null,
            'unit' => $r->unit,
            'container' => $r->container,
            'location' => $r->location,
            'storage_location_id' => $r->storage_location_id,
            'storage_location' => $r->storageLocation?->name,
            'project' => $r->project?->name,
            'generated_date' => $r->generated_date?->toDateString(),
            'received_date' => $r->received_date?->toDateString(),
            'days_in_storage' => $r->days_in_storage,
            'storage_limit_days' => $limit,
            'days_left' => $daysLeft,
            'storage_state' => $storageState,
            'pickup_state' => $latest?->status,
            'vendor' => $latest?->vendor?->name,
            'manifest_number' => $latest?->manifest_number,
            'documents_count' => (int) $r->movements->sum('documents_count'),
            'skipped_storage' => ! $r->storage_location_id && $r->received_date === null
                && in_array($r->status, [WasteRecord::STATUS_SCHEDULED_PICKUP, WasteRecord::STATUS_IN_TRANSIT, WasteRecord::STATUS_DISPOSED, WasteRecord::STATUS_CLOSED], true),
            'gaps' => $gaps,
            'priority' => match (true) {
                $storageState === 'overdue' => 4,
                $storageState === 'approaching' => 3,
                $gaps !== [] && $r->status !== WasteRecord::STATUS_CLOSED => 2,
                $r->status === WasteRecord::STATUS_SCHEDULED_PICKUP => 1,
                default => 0,
            },
        ];
    }

    private function filters(Request $request): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';

        return [
            'q' => trim((string) $request->query('q', '')),
            'view' => in_array($request->query('view'), self::VIEWS, true) ? $request->query('view') : '',
            'status' => in_array($request->query('status'), WasteRecord::STATUSES, true) ? $request->query('status') : '',
            'category' => in_array($request->query('category'), WasteType::CATEGORIES, true) ? $request->query('category') : '',
            'waste_type_id' => ctype_digit((string) $request->query('waste_type_id')) ? (string) $request->query('waste_type_id') : '',
            'storage_location_id' => ctype_digit((string) $request->query('storage_location_id')) || $request->query('storage_location_id') === 'none'
                ? (string) $request->query('storage_location_id') : '',
            'pickup' => in_array($request->query('pickup'), ['none', ...WasteMovement::STATUSES], true) ? $request->query('pickup') : '',
            'from' => $date($request->query('from')),
            'to' => $date($request->query('to')),
        ];
    }

    private function applyFilters(Collection $rows, array $f): Collection
    {
        return $rows
            ->when($f['q'] !== '', function (Collection $c) use ($f) {
                $needle = mb_strtolower($f['q']);

                return $c->filter(fn ($r) => str_contains(mb_strtolower(implode(' ', array_filter([
                    $r['record_number'], $r['waste_type'], $r['waste_code'], $r['storage_location'], $r['location'],
                    $r['project'], $r['vendor'], $r['manifest_number'], $r['container'],
                ]))), $needle));
            })
            ->when($f['view'] !== '', fn (Collection $c) => $c->filter(fn ($r) => match ($f['view']) {
                'b3_stored' => $r['category'] === WasteType::CATEGORY_B3 && in_array($r['status'], WasteRecord::STORED_STATUSES, true),
                'non_b3_stored' => $r['category'] === WasteType::CATEGORY_NON_B3 && in_array($r['status'], WasteRecord::STORED_STATUSES, true),
                'pickup_transit' => in_array($r['status'], [WasteRecord::STATUS_SCHEDULED_PICKUP, WasteRecord::STATUS_IN_TRANSIT], true),
                'done' => in_array($r['status'], [WasteRecord::STATUS_DISPOSED, WasteRecord::STATUS_CLOSED], true),
                'approaching' => $r['storage_state'] === 'approaching',
                'overdue' => $r['storage_state'] === 'overdue',
                'incomplete' => $r['gaps'] !== [],
                // Quick-action views: what each action can be applied to.
                'onsite' => in_array($r['status'], WasteRecord::STORED_STATUSES, true),
                'ready_for_pickup' => in_array($r['status'], [WasteRecord::STATUS_GENERATED, WasteRecord::STATUS_STORED], true),
                default => true,
            }))
            ->when($f['status'] !== '', fn (Collection $c) => $c->where('status', $f['status']))
            ->when($f['category'] !== '', fn (Collection $c) => $c->where('category', $f['category']))
            ->when($f['waste_type_id'] !== '', fn (Collection $c) => $c->filter(fn ($r) => (string) $r['waste_type_id'] === $f['waste_type_id']))
            ->when($f['storage_location_id'] !== '', fn (Collection $c) => $c->filter(fn ($r) => $f['storage_location_id'] === 'none'
                ? $r['storage_location_id'] === null
                : (string) $r['storage_location_id'] === $f['storage_location_id']))
            ->when($f['pickup'] !== '', fn (Collection $c) => $c->filter(fn ($r) => $f['pickup'] === 'none'
                ? $r['pickup_state'] === null
                : $r['pickup_state'] === $f['pickup']))
            ->when($f['from'] !== '', fn (Collection $c) => $c->filter(fn ($r) => $r['generated_date'] !== null && $r['generated_date'] >= $f['from']))
            ->when($f['to'] !== '', fn (Collection $c) => $c->filter(fn ($r) => $r['generated_date'] !== null && $r['generated_date'] <= $f['to']));
    }

    /** What needs attention, by name; each entry links to its record. */
    private function priorities(Collection $rows): array
    {
        $pick = fn (Collection $c) => $c->take(6)->map(fn ($r) => collect($r)->only([
            'id', 'record_number', 'waste_type', 'category', 'storage_location', 'days_in_storage',
            'storage_limit_days', 'days_left', 'gaps', 'vendor', 'status', 'quantity', 'unit',
        ]))->values();
        $open = $rows->where('status', '!=', WasteRecord::STATUS_CLOSED);

        return [
            'overdue' => $pick($rows->where('storage_state', 'overdue')->sortByDesc('days_in_storage')),
            'approaching' => $pick($rows->where('storage_state', 'approaching')->sortBy('days_left')),
            'incomplete' => $pick($open->filter(fn ($r) => $r['gaps'] !== [])),
            'awaiting_handover' => $pick($rows->where('status', WasteRecord::STATUS_SCHEDULED_PICKUP)->sortBy('generated_date')),
            'counts' => [
                'overdue' => $rows->where('storage_state', 'overdue')->count(),
                'approaching' => $rows->where('storage_state', 'approaching')->count(),
                'incomplete' => $open->filter(fn ($r) => $r['gaps'] !== [])->count(),
                'awaiting_handover' => $rows->where('status', WasteRecord::STATUS_SCHEDULED_PICKUP)->count(),
            ],
        ];
    }

    /**
     * Per TPS: what is in it now, the oldest item, and -- only where the
     * units agree -- how full it is against its DECLARED capacity. Mixed or
     * missing units are reported as such rather than added up.
     */
    private function storage($companyIds, Collection $stored): array
    {
        $locations = WasteStorageLocation::whereIn('company_id', $companyIds)->orderBy('name')
            ->get(['id', 'name', 'code', 'location', 'container_type', 'capacity', 'capacity_unit', 'status']);

        $byLocation = $stored->groupBy(fn ($r) => $r['storage_location_id'] ?? 'none');

        $out = $locations->map(function (WasteStorageLocation $l) use ($byLocation) {
            $items = $byLocation->get($l->id, collect());
            $quantities = $items->groupBy(fn ($r) => mb_strtolower(trim((string) $r['unit'])))
                ->map(fn ($g, $unit) => ['unit' => $g->first()['unit'], 'quantity' => round($g->sum('quantity'), 2)])->values();
            $capUnit = mb_strtolower(trim((string) $l->capacity_unit));
            $comparable = $l->capacity !== null && (float) $l->capacity > 0 && $capUnit !== ''
                && $quantities->count() === 1 && mb_strtolower(trim((string) $quantities->first()['unit'])) === $capUnit;
            $used = $comparable ? $quantities->first()['quantity'] / (float) $l->capacity : null;
            $oldest = $items->sortByDesc('days_in_storage')->first();

            return [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
                'status' => $l->status,
                'container_type' => $l->container_type,
                'records' => $items->count(),
                'b3_records' => $items->where('category', WasteType::CATEGORY_B3)->count(),
                'quantities' => $quantities,
                'capacity' => $l->capacity !== null ? (float) $l->capacity : null,
                'capacity_unit' => $l->capacity_unit,
                'utilization' => $used !== null ? round($used * 100) : null,
                'capacity_state' => $used === null ? ($quantities->count() > 1 ? 'mixed_units' : ($l->capacity ? 'unit_mismatch' : 'no_capacity'))
                    : ($used > 1 ? 'exceeded' : ($used >= self::CAPACITY_WARNING_RATIO ? 'filling' : 'ok')),
                'oldest' => $oldest ? collect($oldest)->only(['id', 'record_number', 'waste_type', 'days_in_storage', 'storage_limit_days', 'storage_state']) : null,
            ];
        })->values()->all();

        $unassigned = $byLocation->get('none', collect());
        if ($unassigned->isNotEmpty()) {
            $out[] = [
                'id' => null, 'name' => 'No storage location', 'code' => null, 'status' => null, 'container_type' => null,
                'records' => $unassigned->count(), 'b3_records' => $unassigned->where('category', WasteType::CATEGORY_B3)->count(),
                'quantities' => $unassigned->groupBy('unit')->map(fn ($g, $u) => ['unit' => $u, 'quantity' => round($g->sum('quantity'), 2)])->values(),
                'capacity' => null, 'capacity_unit' => null, 'utilization' => null, 'capacity_state' => 'unassigned',
                'oldest' => ($o = $unassigned->sortByDesc('days_in_storage')->first()) ? collect($o)->only(['id', 'record_number', 'waste_type', 'days_in_storage', 'storage_limit_days', 'storage_state']) : null,
            ];
        }

        return $out;
    }
}
