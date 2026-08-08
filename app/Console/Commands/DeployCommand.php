<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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

        $this->info('Rebuilding caches...');
        $this->call('config:cache');
        $this->call('route:cache');
        $this->call('view:cache');
        $this->call('event:cache');

        if ($maintenance) {
            $this->call('up');
        }

        $this->info('Deployment complete.');

        return self::SUCCESS;
    }
}
