<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.112.0 -- Warehouse & Logistics: what moved, and when.
 *
 * The Stock Position report answers "what is on the shelf now". This one
 * answers "how did it get there", which is the question a stock-take
 * variance actually needs. Quantities are written as NUMBERS so the sheet
 * can be summed and pivoted.
 */
class StockMovementRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'stock-movement-register';
    }

    public function title(): string
    {
        return 'Stock Movement Register';
    }

    public function description(): string
    {
        return 'Riwayat mutasi stok per gudang beserta jenis pergerakan, jumlah dan petugas yang mencatat.';
    }

    public function workspace(): string
    {
        return 'logistics';
    }

    public function formats(): array
    {
        return DocumentRenderer::FORMATS;
    }

    public function columns(): array
    {
        return [
            ['key' => 'movement_number', 'label' => 'Movement No.', 'type' => 'text', 'width' => 20],
            ['key' => 'movement_date', 'label' => 'Date', 'type' => 'date', 'width' => 14],
            ['key' => 'type', 'label' => 'Type', 'type' => 'text', 'width' => 14],
            ['key' => 'item_code', 'label' => 'Item Code', 'type' => 'text', 'width' => 18],
            ['key' => 'item_name', 'label' => 'Item Name', 'type' => 'text', 'width' => 30],
            ['key' => 'warehouse', 'label' => 'Warehouse', 'type' => 'text', 'width' => 22],
            ['key' => 'unit', 'label' => 'Unit', 'type' => 'text', 'width' => 10],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'width' => 14],
            ['key' => 'performer', 'label' => 'Recorded By', 'type' => 'text', 'width' => 20],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = StockMovement::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('item:id,item_code,name,unit', 'warehouse:id,name', 'performer:id,name')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', (int) $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('movement_date', '<=', $v))
            ->orderByDesc('movement_date');

        foreach ($query->cursor() as $movement) {
            yield [
                'movement_number' => $movement->movement_number,
                'movement_date' => $movement->movement_date,
                'type' => DocumentLabel::humanize($movement->type),
                'item_code' => $movement->item?->item_code,
                'item_name' => $movement->item?->name,
                'warehouse' => $movement->warehouse?->name,
                'unit' => $movement->item?->unit,
                'quantity' => $movement->quantity,
                'performer' => $movement->performer?->name,
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Gudang' => isset($filters['warehouse_id']) ? Warehouse::find($filters['warehouse_id'])?->name : null,
            'Jenis' => DocumentLabel::humanize($filters['type'] ?? null),
            'Periode' => DocumentLabel::period($filters),
        ];
    }

    public function authorize(User $user): bool
    {
        return true;
    }
}
