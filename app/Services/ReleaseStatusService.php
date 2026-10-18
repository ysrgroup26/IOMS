<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Throwable;

/**
 * v2.118.0 -- IS THE RUNNING CODE THE DEPLOYED CODE.
 *
 * `SchemaStatusService` beside this one answers "is the DATABASE behind the
 * code". This answers the question one layer further back, which turned out
 * to be the more expensive one to not be able to ask.
 *
 * THE INCIDENT. Corrections to PPE Master and My Work were committed and
 * reported as released. The owner deployed them, opened PPE Master, and was
 * still told "Jenis APD bawaan tidak dapat diubah" -- a sentence that exists
 * in NO file of the release they deployed. It was last present in v2.106.0.
 * Running the deployed commit in an isolated environment and driving the same
 * screen in a browser, the edit SAVES and PERSISTS. So the committed code was
 * never the defect: the code that reached the server was something else.
 *
 * There are only a few ways for that to happen, and every one of them is
 * invisible from inside the application today:
 *
 *   - the checkout is on a DIFFERENT BRANCH than the release was pushed to,
 *     and `git pull` dutifully updated that other branch (this repository has
 *     carried two parallel lines, with two different v2.108.0 releases, so
 *     this is not hypothetical);
 *   - `git pull` did not fast-forward at all -- `public/build/` is committed,
 *     so a touched asset on the server leaves the working tree dirty and the
 *     pull refuses, while the deploy steps after it still run and still
 *     report success;
 *   - OPCACHE in the web workers is still serving the PREVIOUS release's
 *     compiled classes. The deploy runs under the CLI, which has its OWN
 *     opcache, so the deploy sees new code while every web request sees old
 *     code. Nothing in this repository mentioned opcache before this release.
 *
 * In all three the deploy log reads clean and the application keeps serving
 * the previous release. What was missing was not a fix -- it was any way to
 * SEE which code is answering requests. So this reports the identity of the
 * code that is executing RIGHT HERE, and the operations console renders it
 * from a WEB request, which is the only process whose answer matters.
 *
 * IT REPORTS. IT DOES NOT REPAIR. Nothing here pulls, migrates or resets a
 * cache on page load.
 */
class ReleaseStatusService
{
    /**
     * Release-critical files whose content is fingerprinted.
     *
     * Deliberately short and deliberately NOT generated from a directory
     * scan: the point is a stable, cheap signature an operator can compare
     * between the deploy log and the browser, not an integrity check of the
     * whole tree. One entry per subsystem that has already been the subject
     * of a "deployed but unchanged" report.
     */
    public const FINGERPRINTED = [
        'app/Http/Controllers/PpeTypeController.php',
        'app/Http/Controllers/SettingsController.php',
        'app/Models/PpeType.php',
        'config/ioms.php',
    ];

    /**
     * The commit the working tree is checked out at, read from `.git`
     * directly rather than by shelling out to `git`.
     *
     * Shared hosting frequently has no `git` on the PHP process's PATH even
     * where the deploy shell has one, and `exec()` is commonly disabled
     * outright -- so a shell call here would report "unknown" on exactly the
     * hosts this is meant to diagnose. Reading the files git itself writes
     * works wherever the checkout is readable, which is everywhere the
     * application runs from one.
     */
    public function checkout(): array
    {
        $unknown = ['commit' => null, 'branch' => null, 'detached' => null];

        try {
            $gitDir = base_path('.git');

            // A worktree or submodule checkout has `.git` as a FILE pointing
            // at the real directory.
            if (is_file($gitDir)) {
                $pointer = trim(File::get($gitDir));
                if (str_starts_with($pointer, 'gitdir:')) {
                    $gitDir = trim(substr($pointer, 7));
                }
            }

            if (! is_dir($gitDir) || ! is_readable($gitDir.'/HEAD')) {
                return $unknown;
            }

            $head = trim(File::get($gitDir.'/HEAD'));

            // Detached HEAD: the file holds the commit itself.
            if (! str_starts_with($head, 'ref:')) {
                return ['commit' => $head, 'branch' => null, 'detached' => true];
            }

            $ref = trim(substr($head, 4));
            $branch = preg_replace('#^refs/heads/#', '', $ref);
            $refFile = $gitDir.'/'.$ref;

            if (is_readable($refFile)) {
                return ['commit' => trim(File::get($refFile)), 'branch' => $branch, 'detached' => false];
            }

            // A packed ref, which is what a freshly cloned checkout has.
            if (is_readable($gitDir.'/packed-refs')) {
                foreach (preg_split('/\R/', File::get($gitDir.'/packed-refs')) as $line) {
                    if (str_ends_with(trim($line), ' '.$ref)) {
                        return ['commit' => strtok(trim($line), ' '), 'branch' => $branch, 'detached' => false];
                    }
                }
            }

            return ['commit' => null, 'branch' => $branch, 'detached' => false];
        } catch (Throwable) {
            return $unknown;
        }
    }

    /**
     * A short content hash per release-critical file, plus one combined
     * signature over all of them.
     *
     * This is what makes a stale deploy VISIBLE rather than merely suspected:
     * the deploy prints the signature of the files it just pulled, the
     * operations console prints the signature the WEB process computes, and
     * if those two strings differ the web workers are not running the code
     * that is on disk.
     *
     * Note what it can and cannot see. `md5_file()` reads the file from disk,
     * so it reports what was PULLED. It does not read what opcache compiled.
     * The comparison that catches opcache is therefore not file-vs-file but
     * `code_version` (a constant the PHP engine resolves from the compiled
     * class, and so stale when opcache is stale) against the signature below.
     */
    public function fingerprint(): array
    {
        $files = [];
        $combined = '';

        foreach (self::FINGERPRINTED as $relative) {
            try {
                $path = base_path($relative);
                $hash = is_readable($path) ? md5_file($path) : null;
            } catch (Throwable) {
                $hash = null;
            }

            $files[$relative] = $hash ? substr($hash, 0, 12) : null;
            $combined .= (string) $hash;
        }

        return [
            'files' => $files,
            'signature' => substr(md5($combined), 0, 12),
        ];
    }

    /**
     * What the PHP engine has actually compiled, where it will say.
     *
     * `opcache_get_status(false)` needs no file list and is cheap. It is
     * wrapped because some hosts restrict it to the CLI, and a restricted
     * answer must read as "cannot tell" rather than as "fine".
     */
    public function opcache(): array
    {
        try {
            if (! function_exists('opcache_get_status')) {
                return ['available' => false, 'enabled' => false, 'validate_timestamps' => null, 'revalidate_freq' => null];
            }

            $status = @opcache_get_status(false);

            return [
                'available' => true,
                'enabled' => is_array($status) ? (bool) ($status['opcache_enabled'] ?? false) : null,
                /*
                 * The two settings that decide whether a `git pull` is picked
                 * up on its own. With timestamps validated, new code is seen
                 * within `revalidate_freq` seconds and a stale deploy heals
                 * itself; with them OFF, the previous release is served until
                 * the pool is restarted -- indefinitely, silently.
                 */
                'validate_timestamps' => filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL),
                'revalidate_freq' => (int) ini_get('opcache.revalidate_freq'),
            ];
        } catch (Throwable) {
            return ['available' => false, 'enabled' => null, 'validate_timestamps' => null, 'revalidate_freq' => null];
        }
    }

    /**
     * True when the engine is in the configuration that lets a deployed
     * release go on being ignored without saying so.
     */
    public function mayServeStaleCode(): bool
    {
        $opcache = $this->opcache();

        return ($opcache['enabled'] ?? false) === true && ($opcache['validate_timestamps'] ?? true) === false;
    }

    /** The whole answer, assembled once, for a console panel or a deploy log. */
    public function snapshot(): array
    {
        return [
            'version' => (string) config('ioms.version'),
            'stage' => (string) config('ioms.stage'),
            'build' => (string) config('ioms.build'),
            'checkout' => $this->checkout(),
            'fingerprint' => $this->fingerprint(),
            'opcache' => $this->opcache(),
            'may_serve_stale_code' => $this->mayServeStaleCode(),
            'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
        ];
    }
}
