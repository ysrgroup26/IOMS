<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialRequestItem extends Model
{
    protected $fillable = [
        'material_request_id',
        'item_name',
        'specification',
        'quantity',
        'unit',
        'reference_image_path',
        'remarks',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    public function materialRequest()
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function getReferenceImageUrlAttribute(): ?string
    {
        return $this->reference_image_path ? asset('storage/'.$this->reference_image_path) : null;
    }
}
