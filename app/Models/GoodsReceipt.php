<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Logistics' first real module beyond Material Requests (v1.10.0). */
class GoodsReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'received_date',
        'material_request_id',
        'project_id',
        'received_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
        ];
    }

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('sort_order');
    }

    /** GR-{YEAR}-{00001}, same per-year sequential convention as Material Request/Leave/Incident. */
    public static function generateReceiptNumber(): string
    {
        $year = now()->year;
        $lastNumber = static::withTrashed()
            ->where('receipt_number', 'like', "GR-{$year}-%")
            ->orderByDesc('id')
            ->value('receipt_number');

        $sequence = $lastNumber ? ((int) substr($lastNumber, -5)) + 1 : 1;

        return sprintf('GR-%d-%05d', $year, $sequence);
    }
}
