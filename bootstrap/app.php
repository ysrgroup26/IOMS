<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceTenantEntitlement;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RestrictDepartmentAccess;
use App\Http\Middleware\RestrictPlatformAdminFromTenantRoutes;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
            // v1.11.0: entitlement (does the tenant's subscription allow
            // using the product at all) checked before department scope
            // (which department can THIS user reach) -- a tenant-wide
            // block should never depend on which department a route
            // happens to belong to. Default no-op -- see its own doc
            // comment for the config('saas.enforce_entitlement') gate.
            EnforceTenantEntitlement::class,
            RestrictDepartmentAccess::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Sanctum stateful API middleware for the 'web' group (SPA-style auth
        // via cookies, even though the whole app is server-rendered Inertia).
        $middleware->statefulApi();

        $middleware->alias([
            'role' => CheckRole::class,
            'restrict.platform-admin' => RestrictPlatformAdminFromTenantRoutes::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // v1.11.3.2 (production UX fix, Part 3). Before this, an
        // abort_unless(...) 403 -- e.g. RestrictDepartmentAccess's "This
        // page belongs to a different department." -- fell through to
        // Laravel's default plain-text/Blade error response: a jarring
        // full white-screen break out of the Inertia SPA, no navigation,
        // no way back except the browser Back button. This intercepts
        // expected HTTP error statuses and renders the same styled
        // Inertia "Errors/Show" page every other page in the app uses,
        // with a real link back to the user's own Dashboard -- WITHOUT
        // weakening the underlying check itself. RestrictDepartmentAccess/
        // EnforceTenantEntitlement/every other abort_unless() in this
        // codebase is completely untouched; this only changes how the
        // resulting 403/404/419/429 response is RENDERED.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! $request->header('X-Inertia') && ! $request->wantsJson()) {
                // Non-Inertia, non-JSON request (e.g. a raw asset 404) --
                // leave Laravel's own default handling alone.
                return $response;
            }

            $status = $response->getStatusCode();

            // 401/403/404/419/429 are expected, non-bug application states
            // (auth/permission/not-found/session-expired/rate-limit) -- the
            // friendly page is what should render in every environment,
            // including local dev, since there's no debug value in seeing
            // Ignition for these. 500/503 stay on Laravel's normal handling
            // locally (Ignition's stack trace is genuinely useful there);
            // only production hides that behind the same friendly page.
            $alwaysFriendly = in_array($status, [401, 403, 404, 419, 429], true);
            $friendlyInProduction = in_array($status, [500, 503], true) && app()->environment('production');

            if ($alwaysFriendly || $friendlyInProduction) {
                return Inertia::render('Errors/Show', [
                    'status' => $status,
                    'message' => in_array($status, [500, 503], true) ? null : $exception->getMessage(),
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
