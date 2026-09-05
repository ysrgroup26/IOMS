<?php

namespace App\Http\Controllers;

use App\Concerns\HasSecureDocument;
use App\Models\Company;
use App\Support\SecureDocumentRegistry;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v2.38.0 (Master Audit, P1 -- private document delivery).
 *
 * CONFIRMED problem this solves: uploaded operational documents were
 * written to the `public` disk, and `public/storage` is a symlink, so the
 * web server served them directly -- bypassing Laravel entirely. No
 * authentication, no tenant check, no expiry, no revocation.
 *
 * Precise severity (deliberately not overstated): stored filenames are
 * random 40-character hashes, so these URLs are NOT enumerable. The real
 * exposure is that any URL which ever leaks -- a forwarded email, a
 * screenshot, browser history, a referrer header, an ex-employee's saved
 * link -- grants permanent, unauthenticated access to another company's
 * compliance documents, with no way to revoke it. For a multi-tenant SaaS
 * whose customers carry regulatory obligations that is serious, but it is
 * P1 rather than P0 because it needs a leak rather than being directly
 * exploitable.
 *
 * WHY IT STREAMS FROM EITHER DISK. Moving every existing file onto a
 * private disk is an irreversible bulk operation on production data and
 * needs sign-off, so it is deliberately NOT done here. Serving from
 * whichever disk currently holds the file lets the application stop
 * handing out public URLs immediately -- no file migration, and no
 * frontend changes, because the UI already renders these accessors inside
 * `<a href>`/`<img src>` and the browser sends its session cookie.
 *
 * NOTE for whoever performs that migration later: two PDF templates
 * (`pdf/material-request`, `pdf/ppe-replacement-request`) embed images
 * via `public_path('storage/'.$path)` -- a filesystem path, not this URL.
 * They are unaffected by this change but WILL break if the underlying
 * files move, and must be repointed at the private disk in the same
 * change.
 *
 * WHY A WHITELIST (`SecureDocumentRegistry`): a route accepting an
 * arbitrary storage path or class name is a path-traversal/IDOR hazard by
 * construction. Callers pass a type KEY and a record id; the storage path
 * is read from the database row, never from user input.
 */
class SecureDocumentController extends Controller
{
    public function show(string $type, int $id): StreamedResponse
    {
        $modelClass = SecureDocumentRegistry::classFor($type);

        abort_if($modelClass === null, 404);

        $record = $modelClass::find($id);

        abort_if($record === null, 404);

        // Guards against a registry entry pointing at a model that never
        // opted in -- without the trait there is no ownership contract to
        // check, and serving it would bypass the tenant boundary.
        abort_unless(in_array(HasSecureDocument::class, class_uses_recursive($record), true), 404);

        $path = $record->secureDocumentPath();

        abort_if($path === null, 404);

        // The tenant boundary. `Company::query()` passes through
        // TenantScope, the same authority every guarded controller in this
        // codebase uses. 404 rather than 403 so a foreign id never
        // confirms the record exists.
        $ownerCompanyId = $record->secureDocumentOwnerCompanyId();

        abort_unless(
            $ownerCompanyId !== null && Company::query()->pluck('id')->contains($ownerCompanyId),
            404
        );

        $disk = $this->resolveDisk($path);

        abort_if($disk === null, 404);

        return Storage::disk($disk)->download($path, $record->secureDocumentName() ?: basename($path));
    }

    /**
     * Private-first so a migrated file is served from its new home even if
     * a stale copy remains on the public disk during a phased migration.
     */
    private function resolveDisk(string $path): ?string
    {
        foreach (['private', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }
}
