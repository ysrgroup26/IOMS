<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.110.0 -- Warehouse & Logistics: what is actually on the shelf.
 *
 * Quantities are written as NUMBERS, with a number format, not as
 * pre-formatted strings: a stock sheet whose quantities are text cannot
 * be summed, sorted or filtered, which is most of what a warehouse does
 * with one.
 *
 * `available` is quantity minus reserved -- the figure a storeman can act
 * on. It is computed from the two stored columns rather than stored
 * itself, so it cannot drift from them.
 */
class StockPositionDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'stock-position';
    }

    public function title(): string
    {
        return 'Stock Position Report';
    }

    public function description(): string
    {
        return 'Posisi stok per gudang, termasuk jumlah yang sudah dipesan dan sisa yang tersedia.';
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
            ['key' => 'item_code', 'label' => 'Item Code', 'type' => 'text', 'width' => 18],
            ['key' => 'item_name', 'label' => 'Item Name', 'type' => 'text', 'width' => 30],
            ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'width' => 18],
            ['key' => 'warehouse', 'label' => 'Warehouse', 'type' => 'text', 'width' => 22],
            ['key' => 'storage_location', 'label' => 'Location', 'type' => 'text', 'width' => 18],
            ['key' => 'unit', 'label' => 'Unit', 'type' => 'text', 'width' => 10],
            ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'width' => 14],
            ['key' => 'reserved_quantity', 'label' => 'Reserved', 'type' => 'decimal', 'width' => 14],
            ['key' => 'available', 'label' => 'Available', 'type' => 'decimal', 'width' => 14],
            ['key' => 'min_stock', 'label' => 'Min. Stock', 'type' => 'number', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = Stock::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('item:id,item_code,name,category,unit,min_stock', 'warehouse:id,name', 'storageLocation:id,name')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', (int) $v))
            // "Below minimum" is the question a stock report is most
            // often opened to answer, so it is a filter rather than
            // something the reader has to eyeball.
            ->when(($filters['below_minimum'] ?? null), fn ($q) => $q->whereHas(
                'item', fn ($i) => $i->whereNotNull('min_stock')->whereColumn('stocks.quantity', '<', 'items.min_stock')
            ))
            ->orderBy('warehouse_id');

        foreach ($query->cursor() as $stock) {
            yield [
                'item_code' => $stock->item?->item_code,
                'item_name' => $stock->item?->name,
                'category' => $stock->item?->category,
                'warehouse' => $stock->warehouse?->name,
                'storage_location' => $stock->storageLocation?->name,
                'unit' => $stock->item?->unit,
                'quantity' => $stock->quantity,
                'reserved_quantity' => $stock->reserved_quantity,
                'available' => (float) $stock->quantity - (float) $stock->reserved_quantity,
                'min_stock' => $stock->item?->min_stock,
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Gudang' => isset($filters['warehouse_id']) ? Warehouse::find($filters['warehouse_id'])?->name : null,
            'Filter' => ($filters['below_minimum'] ?? null) ? 'Hanya item di bawah stok minimum' : null,
        ];
    }

    public function authorize(User $user): bool
    {
        return true;
    }
}
