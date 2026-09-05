<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 6. See the owning migration's own doc comment. */
class DocumentVersion extends Model
{
    use HasSecureDocument;
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

    public function getUrlAttribute(): ?string
    {
        return $this->secureDocumentUrl();
    }

    /** v2.38.0 (Master Audit): no company_id of its own -- ownership resolves through its parent. */
    public function secureDocumentOwnerCompanyId(): ?int
    {
        return $this->controlledDocument?->company_id;
    }
}
