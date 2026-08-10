<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Acceleration Part 1A (Item Master). See the owning migration's own doc comment. */
class Item extends Model
{
    use SoftDeletes;

    public const TYPES = ['consumable', 'spare_part', 'ppe', 'tool', 'asset'];

    protected $fillable = [
        'item_code', 'company_id', 'name', 'category', 'type', 'specification',
        'unit', 'brand', 'min_stock', 'max_stock', 'is_active', 'attachment_path',
    ];

    protected $appends = ['attachment_url'];

    protected function casts(): array
    {
        return [
            'min_stock' => 'integer',
            'max_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path ? asset('storage/'.$this->attachment_path) : null;
    }

    public static function generateCode(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('item', $companyId);
    }
}
