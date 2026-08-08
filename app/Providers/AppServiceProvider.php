<?php

namespace App\Providers;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Milestone 2 (Tenancy Foundation): MUST be a singleton -- without
        // this, every app(CurrentTenant::class) call (ResolveTenant
        // middleware, TenantScope, DatabaseSeeder, ...) would resolve a
        // separate, empty instance instead of sharing the one the
        // middleware/seeder actually set, silently breaking tenant
        // isolation for the rest of the request/seed run.
        $this->app->singleton(CurrentTenant::class);
    }

    public function boot(): void
    {
        // Prevent accidental lazy-loading N+1 queries in local/dev.
        Model::preventLazyLoading(! app()->isProduction());

        if (env('APP_URL') && str_starts_with(env('APP_URL'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->registerSchemaMacros();
    }

    /**
     * RC1 deployment architecture redesign (docs/ADR/027). A deploy
     * interrupted between a `CREATE TABLE` succeeding and Laravel
     * recording the migration as run (a real risk on shared hosting,
     * where aggressive script-timeout kills are common -- verified live
     * via a deploy-interruption simulation during this audit, not
     * theoretical) leaves the table behind with no migration record.
     * Retrying `migrate` then fails with "table already exists,"
     * blocking every migration after it -- the exact incident class
     * `docs/ADR/025`/`026` already fixed twice, one migration at a time.
     *
     * `Schema::createIfMissing()` is the same fix, generically: a
     * drop-in replacement for `Schema::create()` that is a no-op if the
     * table is already there. Every migration going forward
     * (`docs/CONVENTIONS.md`) should use this instead of `Schema::create()`
     * for a brand-new table, so this incident class cannot recur without
     * a dedicated fix every time.
     */
    private function registerSchemaMacros(): void
    {
        Schema::macro('createIfMissing', function (string $table, \Closure $callback) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $callback);
            }
        });
    }
}
