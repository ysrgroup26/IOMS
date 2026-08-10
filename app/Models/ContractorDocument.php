<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 4. Mirrors VendorDocument exactly. */
class ContractorDocument extends Model
{
    public const TYPES = ['legal_document', 'safety_document', 'contract', 'insurance', 'other'];

    protected $fillable = ['contractor_id', 'document_type', 'file_path', 'original_name', 'expiry_date', 'uploaded_by'];

    protected $appends = ['url', 'is_expired'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date'];
    }

    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
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
