<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 1C. See the owning migration's own doc comment. */
class AssetTransaction extends Model
{
    public const TYPE_ASSIGNMENT = 'assignment';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_INSPECTION = 'inspection';

    public const TYPE_STATUS_CHANGE = 'status_change';

    public const TYPES = [self::TYPE_ASSIGNMENT, self::TYPE_TRANSFER, self::TYPE_INSPECTION, self::TYPE_STATUS_CHANGE];

    protected $fillable = [
        'asset_id', 'type', 'from_location', 'to_location', 'from_employee_id', 'to_employee_id',
        'inspection_result', 'previous_status', 'new_status', 'performed_by', 'transaction_date', 'notes',
    ];

    protected function casts(): array
    {
        return ['transaction_date' => 'date'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromEmployee()
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    public function toEmployee()
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
