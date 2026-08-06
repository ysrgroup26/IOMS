<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RestrictDepartmentAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // ResolveTenant runs FIRST (Milestone 2) -- everything after
            // it, including HandleInertiaRequests' own shared props and
            // every Company-scoped query in the request, needs the tenant
            // context already resolved.
            ResolveTenant::class,
            HandleInertiaRequests::class,
            IdentifyTenant::class,
            RestrictDepartmentAccess::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Sanctum stateful API middleware for the 'web' group (SPA-style auth
        // via cookies, even though the whole app is server-rendered Inertia).
        $middleware->statefulApi();

        $middleware->alias([
            'role' => CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
