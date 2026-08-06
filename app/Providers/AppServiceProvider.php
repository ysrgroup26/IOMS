<?php

namespace App\Providers;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
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
    }
}
