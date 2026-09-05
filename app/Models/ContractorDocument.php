<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 4. Mirrors VendorDocument exactly. */
class ContractorDocument extends Model
{
    use HasSecureDocument;
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

    /**
     * v2.38.0 (Master Audit, P1). Was `asset('storage/'.$this->file_path)`
     * -- a permanent, unauthenticated public URL to a contractor's
     * licence/certificate documents, served straight off the
     * public/storage symlink with no tenant check and no way to revoke it
     * once leaked. Now routed through SecureDocumentController, which
     * authenticates the request and verifies the document's owning
     * company is in the caller's tenant.
     *
     * Nothing else had to change: the frontend already renders this
     * accessor inside a plain `<a href>`, and a browser sends its session
     * cookie, so existing links keep working. The underlying file has NOT
     * been moved -- the controller streams from whichever disk holds it
     * -- so this ships with no data migration. See that controller for
     * why the physical relocation is deliberately left for sign-off.
     */
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
        return $this->contractor?->company_id;
    }
}
