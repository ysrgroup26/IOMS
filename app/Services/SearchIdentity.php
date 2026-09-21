<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * v2.75.0 -- WHAT A SEARCH ENGINE MAY SEE, AND HOW EACH PAGE DESCRIBES ITSELF.
 *
 * Stateless, like the other services here. Every consumer of search
 * identity -- the root layout, robots.txt, the sitemap, the X-Robots-Tag
 * middleware and the shared Inertia props -- asks this one class, so the
 * question "is this page indexable?" has exactly one answer.
 *
 * THREE CONDITIONS, all required, for a response to be indexable:
 *
 *   1. the route is listed in config/seo.php (an allow-list);
 *   2. the request arrived on the canonical host (config ioms.public_url)
 *      -- the legacy ioms.web.id and any other alias serve the same pages,
 *      and must not compete with the real address;
 *   3. the application is running in production -- a staging or
 *      development copy that became reachable must never be indexed.
 *
 * Canonical URLs are built from config('ioms.public_url') and NOT from the
 * request, so they are correct whichever host served the page.
 */
class SearchIdentity
{
    /** config/seo.php's entry for a route, or null when it is not public. */
    public function page(?string $routeName): ?array
    {
        // NOT config('seo.pages.'.$routeName): route names such as
        // legal.privacy contain a dot, which config() reads as nesting, so
        // every legal page silently resolved to "not public". Caught by
        // PublicSearchIdentityTest.
        return $routeName ? ($this->pages()[$routeName] ?? null) : null;
    }

    /** @return array<string, array> every indexable page, keyed by route name. */
    public function pages(): array
    {
        return config('seo.pages', []);
    }

    /** The canonical origin, e.g. https://iomsuite.com (no trailing slash). */
    public function origin(): string
    {
        return rtrim((string) config('ioms.public_url'), '/');
    }

    /** The canonical absolute URL of a named public route. */
    public function canonicalUrl(string $routeName): string
    {
        $path = route($routeName, [], false);

        return $this->origin().($path === '/' ? '/' : $path);
    }

    /** An absolute URL for a public asset, on the canonical origin. */
    public function assetUrl(string $path): string
    {
        return $this->origin().'/'.ltrim($path, '/');
    }

    public function isCanonicalHost(Request $request): bool
    {
        return strcasecmp($request->getHost(), (string) parse_url($this->origin(), PHP_URL_HOST)) === 0;
    }

    /** Whether THIS host, in THIS environment, may be indexed at all. */
    public function hostIsIndexable(Request $request): bool
    {
        return app()->isProduction() && $this->isCanonicalHost($request);
    }

    public function isIndexable(Request $request): bool
    {
        return $this->hostIsIndexable($request)
            && $this->page($request->route()?->getName()) !== null;
    }
}
