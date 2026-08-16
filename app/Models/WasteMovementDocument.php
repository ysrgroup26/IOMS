<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.4 (HSE Waste Management, Part 17). Mirrors VendorDocument's own shape/conventions exactly -- same `public` disk, same asset('storage/'.path) URL accessor. */
class WasteMovementDocument extends Model
{
    public const TYPES = ['manifest', 'disposal_certificate', 'transporter_document', 'photo', 'other'];

    protected $fillable = ['waste_movement_id', 'document_type', 'file_path', 'original_name', 'uploaded_by'];

    protected $appends = ['url'];

    public function wasteMovement()
    {
        return $this->belongsTo(WasteMovement::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }
}
