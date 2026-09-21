<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.70.0 -- WHAT A LAPSED SUBSCRIPTION ACTUALLY COSTS A CUSTOMER.
 *
 * When a period ends and the grace window runs out, IOMS stops accepting
 * NEW work and keeps serving everything already recorded. That is the
 * whole lapse: read-only, not a locked door.
 *
 * WHY NOT A LOCKOUT. IOMS is a system of record for safety compliance.
 * The records it holds — permits to work, incident reports, PPE issuance,
 * training and certification expiry, audit trails — are the evidence an
 * organization produces for a regulator, an insurer, or an investigation
 * after somebody is hurt. Withholding those because an invoice is late
 * would make a billing dispute into a safety and legal problem, and the
 * people harmed by it would be the ones who never saw the invoice.
 * Locking a customer out of their own compliance history is not leverage
 * anyone should want.
 *
 * It is also, bluntly, the more effective commercial design. A customer
 * who can still READ but cannot RECORD feels the lapse on the first shift
 * — every permit, inspection and toolbox meeting has to go somewhere else
 * — which is a far louder signal than a login screen they can simply stop
 * visiting.
 *
 * WHAT STAYS WRITABLE, and why each one:
 *
 *  - Paying. Blocking the route that fixes the problem would be absurd.
 *  - Logging out, signing in, resetting a password.
 *  - Marking a notification read, and a user's own profile.
 *
 * WHAT THIS IS NOT. It is not the suspension block — a `suspended` or
 * `cancelled` subscription is refused outright by EnforceTenantEntitlement
 * before this middleware is reached, because that is a deliberate
 * operator decision rather than the passage of time. This class only ever
 * acts on a subscription that lapsed on its own.
 *
 * NOTHING HERE DELETES OR HIDES ANYTHING. A lapsed tenant's data is
 * complete, readable and exportable, and becomes writable again the
 * moment a payment is verified. See
 * docs/ADR/033-subscription-lifecycle.md.
 */
class EnforceSubscriptionWriteAccess
{
    /**
     * Route-name prefixes that stay writable while lapsed.
     *
     * An allow-list, so a write route added later is refused by default
     * rather than silently becoming reachable — the same reasoning
     * RestrictDemoTenant uses for its own list.
     */
    private const WRITABLE_PREFIXES = [
        // The way out.
        'subscription.',
        // Session and credentials.
        'logout', 'login', 'password.',
        // Marking one's own notification read is a write only incidentally.
        'notifications.',
        // v2.77.0 -- a person's OWN account: profile, password, Google link,
        // verification email. The docblock above has promised that "a
        // user's own profile" stays writable since v2.70.0, but no prefix
        // implemented it, so a lapsed tenant user could not even change
        // their own password. These write only to that user's own row;
        // none of them touches tenant operational data.
        'account.',
        'verification.',
    ];

    public function __construct(private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('saas.enforce_entitlement', false)) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();

        // Platform Admins have no tenant and operate on the platform
        // surface, which has its own gate.
        if (! $user || $user->tenant_id === null) {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';

        foreach (self::WRITABLE_PREFIXES as $prefix) {
            if ($routeName === rtrim($prefix, '.') || str_starts_with($routeName, $prefix)) {
                return $next($request);
            }
        }

        $state = $this->entitlements->tenantLifecycleState($user->tenant);

        if ($state !== Subscription::LIFECYCLE_LAPSED) {
            return $next($request);
        }

        abort(403, 'Masa aktif langganan organisasi Anda telah berakhir, sehingga data baru belum dapat disimpan. '
            .'Seluruh data Anda tetap utuh dan dapat dibuka seperti biasa. Buka halaman Billing untuk melanjutkan langganan.');
    }
}
