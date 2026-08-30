<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1.11.0 (SaaS Finalization Pass, Part 12). The backend authority for
 * "does this tenant's commercial record allow using the product at all
 * right now" -- separate from, and runs alongside, `RestrictDepartmentAccess`
 * (which answers "is THIS user allowed to reach THIS department", not
 * "is the tenant even licensed at all"). Explicit product requirement:
 * "Direct URL access must also be denied if the tenant lacks the
 * entitlement" -- a frontend-only check (hiding a workspace from
 * navigation) is not sufficient, matching this codebase's own long-
 * standing "never rely on frontend filtering alone" rule.
 *
 * Registered globally (same as RestrictDepartmentAccess), but skips:
 * - Unauthenticated requests (nothing to check yet).
 * - Platform Admins (`tenant_id` null) -- they operate ACROSS tenants
 *   from `/platform/*`, which is a completely separate, already-gated
 *   surface (`role:platform_admin` middleware); this class has no
 *   opinion about it.
 * - A small allowlist so a user whose tenant has gone unusable can still
 *   log out, see the global Dashboard (which itself renders a clear
 *   "subscription inactive" state rather than crashing), reach Settings
 *   to see WHY (Tenant Admin's own Subscription tab), and receive
 *   notifications -- being locked out of literally everything, including
 *   the page that explains what's wrong, would be a worse product
 *   experience than the block itself.
 *
 * DEPLOY SAFETY (v1.11.1 update): gated behind
 * `config('saas.enforce_entitlement')`, now default TRUE -- safe as of
 * this pass because `abort_unless(...)` below only fires on
 * `Subscription::isBlocked()`, which is TRUE only for an explicit
 * `suspended`/`cancelled` status (always a deliberate Platform Admin
 * action). It is deliberately NOT true for an expired-by-date or
 * completely missing Subscription row -- exactly the two states a stale
 * `SubscriptionSeeder`-computed `ends_at` (seed time + 1 year, possibly
 * seeded long ago and never refreshed -- see the `public/build` staleness
 * incident in docs/CONVENTIONS.md for the same failure class) could
 * otherwise produce. Those two states instead surface as a "degraded"
 * warning (EntitlementService::tenantIsDegraded(), shown in Settings >
 * Subscription) without blocking anything. Still overridable per-install
 * via `SAAS_ENFORCE_ENTITLEMENT=false` in `.env`.
 *
 * v2.13.0 (SaaS Phase 1): `enforce_workspace_entitlement` now defaults
 * TRUE (see config/saas.php's own doc comment for the two safety nets
 * that made this responsible to enable) and both denial messages below
 * were translated to natural Bahasa Indonesia per this codebase's
 * language convention (sidebar/navigation stays English; user-facing
 * page/error content is Indonesian) -- these messages render verbatim
 * on the Errors/Show.jsx page via Laravel's abort_unless() message.
 *
 * PRODUCTION INCIDENT (v1.11.2.3): registered globally on the `web`
 * middleware group as a bare class string
 * (`bootstrap/app.php`'s `$middleware->web(append: [...])`), Laravel's
 * Pipeline always invokes `handle($request, $next)` for it -- exactly 2
 * arguments. A THIRD `handle()` parameter is only ever populated for
 * ROUTE middleware referenced with an explicit `:parameter` string (e.g.
 * `role:admin`, which Laravel splits and appends after `$next`); it is
 * never resolved via the container just because it's type-hinted, the
 * way constructor injection is. Type-hinting `EntitlementService` as a
 * third `handle()` param here made EVERY web request -- including guest
 * `/login`, before authentication could even run -- throw
 * `ArgumentCountError: Too few arguments... 2 passed... exactly 3
 * expected`, a full site outage (confirmed from the production stack
 * trace, not guessed). Fixed by constructor-injecting `EntitlementService`
 * instead (services ARE container-resolved when Laravel instantiates the
 * middleware class itself) -- `handle()` now matches Laravel's actual
 * global-middleware contract, `handle(Request $request, Closure $next):
 * Response`, exactly.
 */
class EnforceTenantEntitlement
{
    private const ALLOWLIST_PREFIXES = [
        'dashboard', 'home', 'settings', 'logout', 'login', 'password',
        'notifications', 'work-center', 'search',
    ];

    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('saas.enforce_entitlement', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->tenant_id === null) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $prefix = $routeName ? explode('.', $routeName)[0] : null;

        if (! $prefix || in_array($prefix, self::ALLOWLIST_PREFIXES, true)) {
            return $next($request);
        }

        abort_unless(
            $this->entitlements->tenantIsUsable($user->tenant),
            403,
            'Langganan perusahaan Anda sedang tidak aktif. Hubungi administrator perusahaan Anda atau lihat halaman Settings untuk detail.'
        );

        // v1.11.15 (SaaS Package + Ecosystem pass, Part 26/27): the
        // per-workspace (department) entitlement check -- see this
        // class's own doc comment on `enforce_workspace_entitlement` for
        // why this is a NEW, separate, off-by-default check rather than
        // folded into the subscription-usability block above. Reuses the
        // exact same `config('departments')` prefix map
        // `RestrictDepartmentAccess` already uses (a workspace key IS a
        // department key in this app -- Workspace::key/config
        // ('departments') top-level keys are the same vocabulary), so no
        // second copy of that mapping was introduced.
        if (config('saas.enforce_workspace_entitlement', false)) {
            $owningWorkspace = collect(config('departments', []))
                ->search(fn (array $prefixes) => in_array($prefix, $prefixes, true));

            if ($owningWorkspace !== false) {
                abort_unless(
                    $this->entitlements->tenantCanUseWorkspace($user->tenant, $owningWorkspace),
                    403,
                    'Fitur ini belum tersedia untuk paket perusahaan Anda.'
                );
            }
        }

        return $next($request);
    }
}
