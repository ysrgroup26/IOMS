<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceTenantEntitlement;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RestrictDemoTenant;
use App\Http\Middleware\RestrictDepartmentAccess;
use App\Http\Middleware\RestrictPlatformAdminFromTenantRoutes;
use App\Support\ErrorMessagePresenter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // v2.55.0 -- CORRECT SCHEME AND HOST BEHIND THE HOSTING PROXY.
        //
        // IOMS is deployed on shared hosting (cPanel), which terminates TLS
        // at a proxy and forwards to PHP over plain HTTP. Without trusted
        // proxies Laravel reads the request as http:// on the internal host,
        // which produces http:// links in password-reset and invoice emails
        // and makes `$request->isSecure()` false on a site that genuinely is
        // secure.
        //
        // Unset (the default) trusts nothing, which is right for local
        // development and for any environment reached directly. Setting
        // TRUSTED_PROXIES="*" trusts the hosting layer's own forwarding
        // headers -- correct when the application is only ever reachable
        // through that proxy, which is the case on cPanel.
        //
        // This is deliberately NOT hardcoded to "*": trusting forwarding
        // headers from an untrusted network lets a client spoof its own
        // scheme and host.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)),
            );
        }

        // v2.62.0 -- RESOLVETENANT MUST PRECEDE ROUTE MODEL BINDING.
        //
        // The comment below used to say "ResolveTenant runs FIRST". It did
        // not. `web(append:)` puts it at the END of the group, which is
        // AFTER Laravel's own SubstituteBindings -- so every route-model
        // binding in the application (`show(Task $task)`,
        // `show(Employee $employee)`, every implicit binding in the router)
        // resolved its record with NO tenant in context.
        //
        // That was invisible while tenant-owned models had no global scope:
        // an unscoped binding query finds the row, and the controller's own
        // `assertInCurrentTenant()` then rejects a foreign one. Once
        // CompanyOwnedScope existed, the same binding query ran with
        // TenantScope's fail-closed `tenant_id = -1` and returned 404 for a
        // record the user legitimately owns.
        //
        // Removing SubstituteBindings and re-adding it after ResolveTenant
        // is the whole fix. Order matters in both directions and both are
        // now asserted by TenantIsolationTest:
        //
        //   StartSession    must come first -- ResolveTenant reads
        //                   $request->user(), which needs the session.
        //   ResolveTenant   next, so the tenant exists...
        //   SubstituteBindings  ...before any record is looked up by id.
        $middleware->web(remove: [SubstituteBindings::class], append: [
            ResolveTenant::class,
            // Re-added here rather than left in place: bindings must be
            // resolved in tenant context. See the note above.
            SubstituteBindings::class,
            HandleInertiaRequests::class,
            IdentifyTenant::class,
            // v1.11.0: entitlement (does the tenant's subscription allow
            // using the product at all) checked before department scope
            // (which department can THIS user reach) -- a tenant-wide
            // block should never depend on which department a route
            // happens to belong to. Default no-op -- see its own doc
            // comment for the config('saas.enforce_entitlement') gate.
            EnforceTenantEntitlement::class,
            // v2.53.0: the IOMS Sandbox is a REAL tenant, so it is already
            // bounded by TenantScope and RBAC exactly like a customer.
            // This adds the one property a shared demo needs on top --
            // read-mostly, so one visitor cannot break the demonstration
            // for the next. It grants nothing; see its own doc comment.
            RestrictDemoTenant::class,
            RestrictDepartmentAccess::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Sanctum stateful API middleware for the 'web' group (SPA-style auth
        // via cookies, even though the whole app is server-rendered Inertia).
        $middleware->statefulApi();

        // v2.51.0. A payment gateway posts server-to-server and has no
        // session or CSRF token, so this one path is exempt. It is NOT
        // unauthenticated: PaymentWebhookController verifies the
        // provider's signature against the server key before reading a
        // single field, and rejects anything unsigned with 403. The
        // exemption is a single literal path -- never a wildcard.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payment/midtrans',
        ]);

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
            /* v2.68.0 -- THE FRIENDLY PAGE WAS UNREACHABLE FOR THE CASE
               THAT NEEDS IT MOST.

               This guard used to require the `X-Inertia` header, which is
               present only on an XHR navigation made from inside the
               running SPA. Every FULL-PAGE request therefore fell through
               to Laravel's default handler and rendered a bare
               "404 NOT FOUND" with no navigation and no way back -- the
               exact break-out of the SPA this block was written to
               prevent. Verified in a browser: a direct GET of an unmatched
               URL returned the plain page, not Errors/Show.

               Those are the situations a person actually hits an error in:
               a stale bookmark, a link pasted from a chat, a refresh after
               the session expired (419), a deep link into a department
               they cannot see (403). None of them carry `X-Inertia`.

               A full-page navigation is now included. Asset requests are
               still excluded -- a missing .css/.js/.png should stay a cheap
               default 404 rather than render a React page no one will look
               at -- by testing for a file extension on the path.

               Rendering only. The status code, `$alwaysFriendly`, the
               production-only handling of 500/503, and every abort()/
               abort_unless() upstream are untouched: a 403 is still a 403
               and still refuses. */
            $isAssetRequest = (bool) preg_match('/\.[A-Za-z0-9]{1,8}$/', $request->path());

            $shouldRenderFriendlyPage = $request->header('X-Inertia')
                || $request->wantsJson()
                || ($request->acceptsHtml() && ! $isAssetRequest);

            if (! $shouldRenderFriendlyPage) {
                // e.g. a raw asset 404 -- leave Laravel's own default
                // handling alone.
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
                    'message' => ErrorMessagePresenter::forStatus($status, $exception),
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
