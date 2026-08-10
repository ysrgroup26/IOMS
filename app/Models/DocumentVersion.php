<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 6. See the owning migration's own doc comment. */
class DocumentVersion extends Model
{
    protected $fillable = ['controlled_document_id', 'version', 'file_path', 'original_name', 'uploaded_by', 'notes'];

    protected $appends = ['url'];

    public function controlledDocument()
    {
        return $this->belongsTo(ControlledDocument::class);
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
