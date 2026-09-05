<?php

namespace App\Concerns;

use App\Support\SecureDocumentRegistry;

/**
 * v2.38.0 (Master Audit, P1). Makes a model's uploaded file reachable
 * only through `SecureDocumentController` -- authenticated and
 * tenant-checked -- instead of a permanent public URL served straight off
 * the `public/storage` symlink.
 *
 * Follows the same "opt-in reusable trait" shape as HasApprovals /
 * HasWorkflow. Ownership resolution deliberately lives HERE, on the
 * model, because the model already knows its own relations; centralising
 * it in the controller meant restating each chain by hand and let the two
 * drift apart.
 *
 * Two ownership shapes cover every document model in IOMS today
 * (verified across all 13):
 *   - the model has its own `company_id`  -> default below, nothing to do
 *   - the model hangs off a parent that has one -> override
 *     `secureDocumentOwnerCompanyId()`, e.g.
 *         return $this->vendor?->company_id;
 *
 * Fails CLOSED: a null owner means the controller refuses to serve the
 * file. That is intentional -- an unattributable document is exactly the
 * case where guessing would be dangerous.
 */
trait HasSecureDocument
{
    /** Column holding the stored path. Override when it isn't `file_path`. */
    public function secureDocumentPathColumn(): string
    {
        return 'file_path';
    }

    /** Company that owns this document; the tenant check runs against it. */
    public function secureDocumentOwnerCompanyId(): ?int
    {
        return $this->company_id ?? null;
    }

    /** Filename offered to the browser on download. */
    public function secureDocumentName(): ?string
    {
        $path = $this->secureDocumentPath();

        return $this->original_name ?? ($path ? basename($path) : null);
    }

    public function secureDocumentPath(): ?string
    {
        return $this->{$this->secureDocumentPathColumn()} ?: null;
    }

    /**
     * The URL to expose instead of `asset('storage/'.$path)`. Returns null
     * when there is no file, so an accessor can keep its existing
     * "null when empty" contract rather than emitting a dead link.
     */
    public function secureDocumentUrl(): ?string
    {
        if (! $this->secureDocumentPath()) {
            return null;
        }

        $type = SecureDocumentRegistry::typeFor(static::class);

        if ($type === null) {
            return null;
        }

        return route('secure-documents.show', ['type' => $type, 'id' => $this->getKey()]);
    }
}
