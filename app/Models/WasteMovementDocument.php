<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * v1.11.4 (HSE Waste Management, Part 17). Mirrors VendorDocument's own
 * shape and conventions.
 *
 * v2.38.0 (Master Audit): the previous version of this comment claimed a
 * public disk and an `asset('storage/'.path)` accessor -- both were true
 * then and are wrong now. Delivery goes through
 * `SecureDocumentController` (authenticated + tenant-checked) via
 * `HasSecureDocument`; the file itself has not moved yet.
 */
class WasteMovementDocument extends Model
{
    use HasSecureDocument;
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

    /**
     * v2.38.0 (Master Audit, P1). Was a permanent unauthenticated public
     * URL (see ContractorDocument's own accessor for the full reasoning).
     * Waste manifests and disposal certificates are regulatory evidence
     * naming vendors, quantities and destinations -- exactly the class of
     * document that must not sit behind a guessable-once-leaked static
     * URL. Now authenticated and tenant-checked; no file moved.
     */
    public function getUrlAttribute(): ?string
    {
        return $this->secureDocumentUrl();
    }
    /** v2.38.0 (Master Audit): no company_id of its own -- ownership resolves through its parent. */
    public function secureDocumentOwnerCompanyId(): ?int
    {
        return $this->wasteMovement?->company_id;
    }
}
