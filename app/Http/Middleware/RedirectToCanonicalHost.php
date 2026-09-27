<?php

namespace App\Http\Middleware;

use App\Services\SearchIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v2.81.0 -- ONE ADDRESS, NOT TWO.
 *
 * `https://iomsuite.com` is the canonical origin (ADR 039) and everything
 * that describes the site already says so: canonical tags, the sitemap,
 * Open Graph and structured data are all built from
 * `config('ioms.public_url')` rather than from the request, and
 * `SearchIdentity::isCanonicalHost()` already marks any other host
 * `noindex`.
 *
 * What was missing is the redirect itself. A visitor or a crawler arriving
 * on `www.iomsuite.com` was served the page, saw a canonical tag pointing
 * somewhere else, and stayed on the wrong address -- which splits links,
 * makes analytics read as two sites, and leaves a second host that must be
 * kept identical forever.
 *
 * WHAT IT REDIRECTS, and nothing else: a request whose host is exactly the
 * canonical host with `www.` in front of it. Not "any host that is not
 * canonical" -- that would bounce a health check by IP, an internal
 * hostname, a preview domain, and the legacy `ioms.web.id`, which is a
 * separate decision with its own consequences and is deliberately left
 * alone (it already serves and is already `noindex`).
 *
 * WHY 301, AND WHY ONLY GET/HEAD:
 *
 *  - 301 is permanent, which is what tells a search engine to transfer the
 *    address rather than treat it as a temporary detour.
 *  - A 301 on a POST is the dangerous case. Clients are permitted to
 *    convert it to a GET and drop the body, so a payment provider posting a
 *    webhook to the www host would have its notification silently discarded
 *    and IOMS would never learn the payment succeeded. Unsafe methods are
 *    therefore passed straight through, untouched, and a webhook posted to
 *    the www host keeps working exactly as it does today.
 *
 * PRODUCTION ONLY. Local development, tests and any non-production copy
 * are reached on hosts that are not this one, and a redirect there would
 * send a developer to the live site.
 *
 * This middleware CANNOT replace a web-server or DNS level redirect for
 * the cases PHP never sees (a request to the www host that the hosting
 * layer does not route to this application at all). It is the half that
 * lives in the repository; the other half is recorded in
 * docs/ADR/039-public-search-identity.md as a manual production step.
 */
class RedirectToCanonicalHost
{
    public function __construct(private readonly SearchIdentity $search) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldRedirect($request)) {
            return $next($request);
        }

        // Path and query are preserved: a redirect that drops the visitor on
        // the home page loses the page they actually asked for, and a search
        // engine reads it as a soft 404 rather than a moved address.
        $target = $this->search->origin().$request->getRequestUri();

        return redirect()->away($target, 301);
    }

    private function shouldRedirect(Request $request): bool
    {
        if (! config('seo.redirect_www', true)) {
            return false;
        }

        if (! app()->isProduction()) {
            return false;
        }

        if (! $request->isMethodSafe()) {
            return false;
        }

        $canonical = (string) parse_url($this->search->origin(), PHP_URL_HOST);

        if ($canonical === '') {
            return false;
        }

        return strcasecmp($request->getHost(), 'www.'.$canonical) === 0;
    }
}
