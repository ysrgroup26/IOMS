<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 1B. Permanent transaction log -- see the owning migration's own doc comment. */
class StockMovement extends Model
{
    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';

    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';

    public const TYPE_OPNAME = 'opname';

    public const TYPES = [
        self::TYPE_RECEIPT, self::TYPE_ISSUE, self::TYPE_TRANSFER_OUT, self::TYPE_TRANSFER_IN,
        self::TYPE_ADJUSTMENT_IN, self::TYPE_ADJUSTMENT_OUT, self::TYPE_OPNAME,
    ];

    // TYPE_OPNAME is deliberately NOT in this list -- a physical count
    // variance can go either direction (counted > system OR counted <
    // system), so StockTransactionController::opname() records the
    // variance as a real ADJUSTMENT_IN/ADJUSTMENT_OUT (tagged in its own
    // notes as an opname), never as a same-typed row whose direction
    // would have to be inferred some OTHER way and risk exactly the
    // sign-convention bug this whole always-positive-quantity design
    // exists to avoid. TYPE_OPNAME stays defined/in TYPES purely as a
    // possible future reporting label, not a movement this code creates.
    public const INBOUND_TYPES = [self::TYPE_RECEIPT, self::TYPE_TRANSFER_IN, self::TYPE_ADJUSTMENT_IN];

    protected $fillable = [
        'movement_number', 'company_id', 'item_id', 'warehouse_id', 'storage_location_id',
        'type', 'quantity', 'reference_type', 'reference_id', 'related_movement_id',
        'performed_by', 'movement_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function storageLocation()
    {
        return $this->belongsTo(StorageLocation::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function relatedMovement()
    {
        return $this->belongsTo(self::class, 'related_movement_id');
    }

    /** Quantity is always positive on the row; direction comes entirely from `type` -- see the migration's own doc comment on why. */
    public function isInbound(): bool
    {
        return in_array($this->type, self::INBOUND_TYPES, true);
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('stock_movement', $companyId);
    }
}
