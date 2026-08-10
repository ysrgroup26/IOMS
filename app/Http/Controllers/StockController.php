<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Acceleration Part 1B (Inventory reports). Stock Summary
 * (this index), Low Stock Alert (a filter on the same page, not a
 * separate route), Stock Card (per-item movement history), Movement
 * History (full log). All tenant-scoped from the start.
 */
class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $stocks = \App\Models\Stock::whereIn('company_id', $tenantCompanyIds)
            ->with('item:id,name,item_code,unit,min_stock,max_stock', 'warehouse:id,name,code')
            ->when($request->input('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->input('low_stock'), fn ($q) => $q->whereRaw('stocks.quantity <= (select items.min_stock from items where items.id = stocks.item_id)'))
            ->when($request->input('search'), fn ($q, $v) => $q->whereHas('item', fn ($i) => $i->where('name', 'like', "%{$v}%")->orWhere('item_code', 'like', "%{$v}%")))
            ->orderBy('quantity')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Warehouses/Stock', [
            'stocks' => $stocks,
            'filters' => $request->only('warehouse_id', 'low_stock', 'search'),
            'warehouses' => Warehouse::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'code']),
            'items' => Item::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name', 'item_code', 'unit']),
            'can' => ['manage' => $request->user()->canManageWarehouse()],
        ]);
    }

    /** Stock Card -- one item's full movement history, computed running balance shown alongside each row. */
    public function card(Item $item, Request $request): Response
    {
        abort_unless(Company::query()->pluck('id')->contains($item->company_id), 404);

        $movements = StockMovement::where('item_id', $item->id)
            ->with('warehouse:id,name', 'performer:id,name')
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();

        $running = 0;
        $movements = $movements->map(function ($m) use (&$running) {
            $running += $m->isInbound() ? (float) $m->quantity : -(float) $m->quantity;

            return [
                'id' => $m->id, 'movement_number' => $m->movement_number, 'type' => $m->type,
                'quantity' => $m->quantity, 'movement_date' => $m->movement_date, 'warehouse' => $m->warehouse,
                'performer' => $m->performer, 'notes' => $m->notes, 'running_balance' => $running,
            ];
        })->reverse()->values();

        return Inertia::render('Warehouses/StockCard', [
            'item' => $item,
            'movements' => $movements,
        ]);
    }

    public function movements(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $movements = StockMovement::whereIn('company_id', $tenantCompanyIds)
            ->with('item:id,name,item_code,unit', 'warehouse:id,name', 'performer:id,name')
            ->when($request->input('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->latest('movement_date')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Warehouses/Movements', [
            'movements' => $movements,
            'filters' => $request->only('type', 'warehouse_id'),
            'warehouses' => Warehouse::whereIn('company_id', $tenantCompanyIds)->active()->get(['id', 'name']),
            'types' => StockMovement::TYPES,
        ]);
    }
}
