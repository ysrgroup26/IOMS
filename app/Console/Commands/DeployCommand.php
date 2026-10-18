<?php

namespace App\Console\Commands;

use App\Services\ReleaseStatusService;
use App\Services\SchemaStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * RC1 deployment architecture redesign (docs/ADR/027, revised by
 * ADR/028: production has no Node.js). The single "deploy → cache →
 * ready" step every environment (shared hosting/cPanel, VPS, future
 * cloud) runs identically after `composer install` -- frontend assets
 * are built and committed on a developer's own machine beforehand (see
 * README § 5), never built here or anywhere in the production deploy
 * flow. No environment-specific manual steps live here or anywhere else
 * in the deploy flow.
 *
 * Deliberately a plain Artisan command, not a shell script, for the
 * parts that touch Laravel itself: every step below already has to run
 * through `php artisan` anyway, and keeping the orchestration in PHP
 * means it's testable, portable across shells (the shared host's `sh`,
 * a VPS's `bash`, a future CI runner), and covered by the same "no
 * assumptions, verify first" discipline as the rest of the codebase --
 * see `up()`'s own idempotency guard, and the migrations this command
 * runs are themselves self-healing against a partial prior run (ADR-025,
 * ADR-026).
 *
 * Deliberately does NOT catch exceptions from any step. If migrate,
 * seed, or any cache step throws, this command exits non-zero and the
 * application stays in maintenance mode -- serving the 503 "down for
 * maintenance" page is a safe failure state; silently finishing "up"
 * after a broken deploy (leaving whatever partial/inconsistent state
 * the failure left behind, invisible to users until they hit a 500) is
 * not. The operator sees a red failing command in their deploy log and
 * fixes it before manually running `php artisan up`.
 */
class DeployCommand extends Command
{
    protected $signature = 'app:deploy {--seed : Also run database seeders (safe to pass on every deploy -- all seeders are idempotent, see docs/CONVENTIONS.md)} {--no-maintenance : Skip maintenance mode (fine for a brand-new install with no real users yet)}';

    protected $description = 'Single post-build deployment step: clear stale cache, migrate, optionally seed, link storage, rebuild caches. Run after composer install (frontend assets are pre-built and committed, not built here).';

    /**
     * Schema this release's code depends on, as table => columns.
     *
     * v2.118.0. `migrate` reporting success is not the same fact as the
     * schema being right: a migration that failed PART WAY on MySQL leaves
     * its earlier statements applied and is not recorded as run, and the
     * next deploy then fails on a duplicate column instead of completing --
     * observed on this very codebase while reproducing the release that
     * prompted this check. Asserting the columns the running code reads for
     * turns that into one named failure at deploy time rather than a 500 on
     * whichever page touches the missing column first.
     *
     * One entry per subsystem that has already been reported broken after a
     * deploy. It is not a schema mirror and must not grow into one.
     */
    private const REQUIRED_SCHEMA = [
        'ppe_types' => ['company_id', 'source_default_id'],
        'users' => ['user_type', 'is_field_user', 'ptw_access'],
        'subscriptions' => ['additional_users', 'additional_my_work_packs'],
    ];

    public function handle(): int
    {
        $maintenance = ! $this->option('no-maintenance');

        if ($maintenance) {
            $this->call('down', ['--retry' => 5]);
        }

        // Stale config:cache from the PREVIOUS deploy does not update
        // itself on `git pull` -- migrating against it is exactly what
        // caused the "config/permission.php not loaded" incident
        // (docs/ADR/024). Always clear first, every deploy, not just the
        // first one.
        $this->call('config:clear');

        $this->info('Running migrations...');
        $this->call('migrate', ['--force' => true]);

        if ($this->option('seed')) {
            $this->info('Seeding database...');
            $this->call('db:seed', ['--force' => true]);
        }

        // Deliberately unconditional, not guarded by our own
        // file_exists()/is_link() check -- both are unreliable across
        // platforms for a symlink whose target may not resolve the same
        // way everywhere (verified: `is_link()` false-negatives on an
        // otherwise-working Windows dev symlink, which would make a
        // guard here skip re-running when it shouldn't, or vice versa).
        // `storage:link` already handles "the link is already there"
        // itself, gracefully, on every platform -- it reports "already
        // exists" and returns without throwing, so calling it every
        // deploy is simplest and correct rather than re-implementing a
        // check Artisan's own command already owns.
        $this->info('Linking storage...');
        $this->call('storage:link');

        /*
         * v2.118.0 -- THE WEB WORKERS, NOT THIS PROCESS, ARE WHAT SERVE THE
         * RELEASE.
         *
         * This command runs under the CLI SAPI, which has its own opcache.
         * Resetting here therefore helps only where the CLI and the web
         * process share one (mod_php, and some FPM configurations); it
         * cannot reach a separate FPM pool, and it is NOT a substitute for
         * `opcache.validate_timestamps` being on. It is done because it is
         * free where it works, and reported honestly where it does not --
         * see the release report at the end of this command, which names
         * the condition in which a deployed release can go on being
         * ignored. Nothing in this repository touched opcache before this
         * release; the deploy log read clean while the previous release
         * kept answering requests.
         */
        if (function_exists('opcache_reset')) {
            $this->info('Resetting opcache for this process...');
            @opcache_reset();
        }

        $this->info('Rebuilding caches...');
        $this->call('config:cache');
        $this->call('route:cache');
        $this->call('view:cache');
        $this->call('event:cache');

        /*
         * v2.118.0 -- A DEPLOY THAT CANNOT SAY WHAT IT DEPLOYED IS NOT A
         * DEPLOY, IT IS A HOPE.
         *
         * Run BEFORE `up`, so a release that did not actually land keeps
         * serving the maintenance page rather than half of itself.
         */
        if (($verification = $this->reportRelease()) !== self::SUCCESS) {
            $this->error('Deployment verification FAILED -- the application has been left in maintenance mode.');
            $this->line('Fix the cause, re-run this command, and only then `php artisan up`.');

            return $verification;
        }

        if ($maintenance) {
            $this->call('up');
        }

        $this->info('Deployment complete.');

        return self::SUCCESS;
    }

    /**
     * Prints the identity of the release that is now on disk, and fails when
     * the database or the checkout contradicts it.
     *
     * The three facts an operator could not previously get at: WHICH COMMIT
     * and WHICH BRANCH the checkout is on, WHETHER the schema this code reads
     * for is actually there, and WHETHER the engine is configured in a way
     * that lets stale compiled code keep being served. Each one has produced a
     * release that was reported as delivered and was not running.
     */
    private function reportRelease(): int
    {
        $release = app(ReleaseStatusService::class);
        $state = $release->snapshot();
        $checkout = $state['checkout'];

        $this->newLine();
        $this->info('Release now on disk');
        $this->line(sprintf('  version    %s %s (build %s)', $state['version'], $state['stage'], $state['build']));
        $this->line(sprintf('  branch     %s', $checkout['branch'] ?? ($checkout['detached'] ? 'DETACHED HEAD' : 'unknown')));
        $this->line(sprintf('  commit     %s', $checkout['commit'] ? substr($checkout['commit'], 0, 12) : 'unknown'));
        $this->line(sprintf('  signature  %s', $state['fingerprint']['signature']));
        $this->line('  Compare that signature with Master Admin > the release panel, which is');
        $this->line('  computed by a WEB request. If the two differ, the web workers are still');
        $this->line('  serving the previous release and the pool needs restarting.');

        $failed = false;

        $pending = app(SchemaStatusService::class)->pendingMigrations();
        if ($pending !== []) {
            $failed = true;
            $this->newLine();
            $this->error(sprintf('%d migration(s) exist in this release and have NOT run here:', count($pending)));
            foreach (array_slice($pending, 0, 10) as $name) {
                $this->line('  - '.$name);
            }
        }

        foreach (self::REQUIRED_SCHEMA as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $failed = true;
                $this->error(sprintf('Table `%s` is missing -- this release\'s code reads it.', $table));

                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $failed = true;
                    $this->error(sprintf('Column `%s.%s` is missing -- this release\'s code reads it.', $table, $column));
                }
            }
        }

        if ($release->mayServeStaleCode()) {
            /*
             * Not a failure of THIS deploy: the code and schema can be
             * perfectly correct while the running workers ignore both. Said
             * loudly, with the remedy, because it is silent by nature.
             */
            $this->newLine();
            $this->warn('opcache is enabled with opcache.validate_timestamps=Off.');
            $this->warn('New code is NOT picked up until the PHP pool restarts, so this release');
            $this->warn('may not be what answers requests. Restart PHP for this account (cPanel:');
            $this->warn('MultiPHP Manager / "Restart PHP"), then re-check the release panel.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
