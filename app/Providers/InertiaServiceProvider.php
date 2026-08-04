<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class InertiaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Inertia::setRootView('app');

        // Cache-busts the Inertia asset version whenever the built manifest changes,
        // so users are prompted to reload after a new frontend deploy.
        Inertia::version(fn () => md5_file(public_path('build/manifest.json')) ?: null);
    }
}
