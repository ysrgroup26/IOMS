<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Milestone 4, Acceleration Part 1B (Warehouse/Inventory). Single,
 * reusable, concurrency-safe entry point for every stock-balance change
 * -- Goods Receipt, Goods Issue, Stock Transfer, Stock Adjustment, and
 * Stock Opname all call this instead of five near-identical
 * read-then-write blocks (the exact class of bug NumberGeneratorService
 * was built to eliminate for numbering sequences -- same reasoning,
 * applied here to inventory balances).
 */
class StockService
{
    /**
     * Records one StockMovement row and atomically applies its effect to
     * the matching Stock balance (locked for the duration of the
     * transaction, never a plain unlocked read-then-write).
     */
    public function recordMovement(
        int $companyId,
        int $itemId,
        int $warehouseId,
        string $type,
        float $quantity,
        User $performedBy,
        ?int $storageLocationId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $relatedMovementId = null,
        ?string $notes = null,
        ?string $movementDate = null,
    ): StockMovement {
        return DB::transaction(function () use (
            $companyId, $itemId, $warehouseId, $type, $quantity, $performedBy,
            $storageLocationId, $referenceType, $referenceId, $relatedMovementId, $notes, $movementDate
        ) {
            $movement = StockMovement::create([
                'movement_number' => StockMovement::generateNumber($companyId),
                'company_id' => $companyId,
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'storage_location_id' => $storageLocationId,
                'type' => $type,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'related_movement_id' => $relatedMovementId,
                'performed_by' => $performedBy->id,
                'movement_date' => $movementDate ?? now()->toDateString(),
                'notes' => $notes,
            ]);

            $stock = Stock::firstOrCreate(
                ['item_id' => $itemId, 'warehouse_id' => $warehouseId],
                ['company_id' => $companyId, 'storage_location_id' => $storageLocationId, 'quantity' => 0, 'reserved_quantity' => 0]
            );

            $stock = Stock::where('id', $stock->id)->lockForUpdate()->first();
            $delta = $movement->isInbound() ? $quantity : -$quantity;
            $stock->increment('quantity', $delta);
            if ($storageLocationId) {
                $stock->update(['storage_location_id' => $storageLocationId]);
            }

            return $movement;
        });
    }

    /** A same-item transfer between two warehouses -- two linked movement rows, both wrapped in one transaction. */
    public function transfer(
        int $companyId, int $itemId, int $fromWarehouseId, int $toWarehouseId,
        float $quantity, User $performedBy, ?string $notes = null, ?string $movementDate = null,
    ): array {
        return DB::transaction(function () use ($companyId, $itemId, $fromWarehouseId, $toWarehouseId, $quantity, $performedBy, $notes, $movementDate) {
            $out = $this->recordMovement($companyId, $itemId, $fromWarehouseId, StockMovement::TYPE_TRANSFER_OUT, $quantity, $performedBy, notes: $notes, movementDate: $movementDate);
            $in = $this->recordMovement($companyId, $itemId, $toWarehouseId, StockMovement::TYPE_TRANSFER_IN, $quantity, $performedBy, relatedMovementId: $out->id, notes: $notes, movementDate: $movementDate);
            $out->update(['related_movement_id' => $in->id]);

            return [$out, $in];
        });
    }
}
