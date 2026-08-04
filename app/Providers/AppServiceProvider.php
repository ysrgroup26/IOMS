<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
