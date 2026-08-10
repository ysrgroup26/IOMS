<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream C1. See the owning migration's own doc comment. */
class VendorDocument extends Model
{
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

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }
}
