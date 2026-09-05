<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

class MaterialRequestItem extends Model
{
    use HasSecureDocument;
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
        return $this->reference_image_path ? $this->secureDocumentUrl() : null;
    }

    /** v2.38.0 (Master Audit): see App\Concerns\HasSecureDocument. */
    public function secureDocumentPathColumn(): string
    {
        return 'reference_image_path';
    }

    /** v2.38.0 (Master Audit): no company_id of its own -- ownership resolves through its parent. */
    public function secureDocumentOwnerCompanyId(): ?int
    {
        return $this->materialRequest?->company_id;
    }
}
