<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream C1. See the owning migration's own doc comment. */
class VendorDocument extends Model
{
    use HasSecureDocument;
    public const TYPES = ['legal_document', 'company_profile', 'certificate', 'contract', 'other'];

    protected $fillable = ['vendor_id', 'document_type', 'file_path', 'original_name', 'expiry_date', 'uploaded_by'];

    protected $appends = ['url', 'is_expired'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date'];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->secureDocumentUrl();
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /** v2.38.0 (Master Audit): no company_id of its own -- ownership resolves through its parent. */
    public function secureDocumentOwnerCompanyId(): ?int
    {
        return $this->vendor?->company_id;
    }
}
