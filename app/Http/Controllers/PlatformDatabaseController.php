<?php

namespace App\Http\Controllers;

use App\Http\Requests\RestoreDatabaseRequest;
use App\Models\ActivityLog;
use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * v2.108.2 (SEC-010) -- WHOLE-DATABASE BACKUP AND RESTORE, MOVED OUT OF
 * THE CUSTOMER'S SETTINGS.
 *
 * THE FAULT. `/settings/backup` and `/settings/restore` lived in the
 * tenant-side `role:super_admin` group. `super_admin` is a CUSTOMER role
 * -- the administrator of one organization (`isTenantAdmin()` is that role
 * PLUS a tenant), not an IOMS operator. The handlers behind it ran
 * `mysqldump` and `mysql <` against the WHOLE connection, which has no
 * notion of tenancy at all.
 *
 * So any customer's own administrator could download a complete dump of
 * every other customer's employees, incidents, permits and invoices, and
 * could overwrite the database for every tenant on the installation. It
 * was not an escalation path or a bug in a check -- the feature worked
 * exactly as written, in the wrong place.
 *
 * THE FIX, AND WHY IT IS A MOVE RATHER THAN A PATCH. Backing up an entire
 * multi-tenant database is a legitimate operation, but it belongs to
 * whoever operates the platform, so it now lives on the platform surface
 * behind `role:platform_admin` -- the same gate as every other
 * installation-wide action. Nothing about it is tenant-scoped, which is
 * precisely why it must not be reachable from inside a tenant.
 *
 * `RestrictPlatformAdminFromTenantRoutes` already keeps operators out of
 * tenant pages; this is the matching direction.
 *
 * WHAT A CUSTOMER KEEPS. Everything that was ever theirs to export is
 * still theirs: the employee register (Excel and PDF), report exports and
 * every other module's own export. What they lose is the ability to take a
 * copy of OTHER customers' data, which was never theirs to take.
 */
class PlatformDatabaseController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backups) {}

    /** The maintenance page: what this can do here, and what it cannot. */
    public function show(): Response
    {
        return Inertia::render('Platform/Database', [
            'supported' => $this->backups->isSupported(),
            'unsupportedReason' => $this->backups->unsupportedReason(),
            'database' => config('database.connections.mysql.database'),
            'confirmationWord' => RestoreDatabaseRequest::CONFIRMATION,
        ]);
    }

    /**
     * Streams a full SQL dump and deletes it from the server afterwards.
     *
     * The file is written under `storage/app/backups`, which is on the
     * private disk and outside the public symlink, and then removed after
     * sending -- a dump of every tenant's data left lying in a directory
     * is the same exposure in a different shape.
     */
    public function backup(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! $this->backups->isSupported()) {
            return back()->with('error', $this->backups->unsupportedReason());
        }

        $filename = 'ioms-backup-'.now()->format('Y-m-d_His').'.sql';
        $path = Storage::disk('backups')->path($filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        $result = $this->backups->dump($path);

        if (! $result['ok'] || ! file_exists($path)) {
            @unlink($path);

            return back()->with('error', 'Backup gagal. '.mb_substr($result['output'], 0, 200));
        }

        ActivityLog::record('exported', 'Platform database backup downloaded by '.$request->user()->displayName().'.');

        return response()->download($path)->deleteFileAfterSend(true);
    }

    /**
     * Replaces the database from an uploaded dump.
     *
     * Every guard is in RestoreDatabaseRequest, which refuses the request
     * outright unless the caller is a platform operator, typed the
     * confirmation word, named this deployment's own database, and
     * uploaded something that actually reads as a mysqldump. By the time
     * this method runs the decision has been made.
     */
    public function restore(RestoreDatabaseRequest $request): RedirectResponse
    {
        if (! $this->backups->isSupported()) {
            return back()->with('error', $this->backups->unsupportedReason());
        }

        $uploadPath = $request->file('backup_file')->store('restores', 'local');
        $fullPath = Storage::disk('local')->path($uploadPath);

        /*
         * Recorded BEFORE the restore runs. A dump that predates this line
         * will not contain it, and a restore that fails half way through
         * would otherwise leave no trace of having been attempted at all.
         */
        ActivityLog::record(
            'restored',
            'Platform database restore STARTED by '.$request->user()->displayName().'.',
            null,
            ['file' => $request->file('backup_file')->getClientOriginalName()]
        );

        try {
            $result = $this->backups->restore($fullPath);
        } finally {
            Storage::disk('local')->delete($uploadPath);
        }

        if (! $result['ok']) {
            return back()->with('error', 'Restore gagal. '.mb_substr($result['output'], 0, 200));
        }

        return back()->with('success', 'Database dipulihkan dari berkas backup.');
    }
}
