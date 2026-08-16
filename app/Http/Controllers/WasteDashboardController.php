<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WasteMovement;
use App\Models\WasteRecord;
use App\Models\WasteType;
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
 */
class WasteDashboardController extends Controller
{
    public function index(): Response
    {
        $companyIds = Company::query()->pluck('id');

        $records = WasteRecord::whereIn('company_id', $companyIds)->with('wasteType:id,category,storage_limit_days')->get();
        $stored = $records->whereIn('status', WasteRecord::STORED_STATUSES);

        return Inertia::render('Hse/WasteManagement/Dashboard', [
            'totalRecordsCount' => $records->count(),
            'b3StoredCount' => $stored->filter(fn (WasteRecord $r) => $r->wasteType?->category === WasteType::CATEGORY_B3)->count(),
            'nonB3StoredCount' => $stored->filter(fn (WasteRecord $r) => $r->wasteType?->category === WasteType::CATEGORY_NON_B3)->count(),
            'awaitingPickupCount' => $records->where('status', WasteRecord::STATUS_SCHEDULED_PICKUP)->count(),
            'inTransitCount' => $records->where('status', WasteRecord::STATUS_IN_TRANSIT)->count(),
            'disposedCount' => $records->whereIn('status', [WasteRecord::STATUS_DISPOSED, WasteRecord::STATUS_CLOSED])->count(),
            'approachingLimitCount' => $stored->filter(fn (WasteRecord $r) => $r->is_approaching_storage_limit)->count(),
            'overdueStorageCount' => $stored->filter(fn (WasteRecord $r) => $r->is_storage_overdue)->count(),
            'storedRecords' => WasteRecord::whereIn('company_id', $companyIds)
                ->whereIn('status', WasteRecord::STORED_STATUSES)
                ->with('wasteType:id,name,category,storage_limit_days', 'storageLocation:id,name')
                ->orderBy('generated_date')
                ->limit(8)
                ->get(),
            'recentMovements' => WasteMovement::whereIn('company_id', $companyIds)
                ->with('wasteRecord:id,record_number', 'vendor:id,name')
                ->latest('id')
                ->limit(8)
                ->get(['id', 'waste_record_id', 'vendor_id', 'status', 'pickup_date', 'disposal_date']),
        ]);
    }
}
