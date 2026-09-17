<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * v2.72.0 -- THE TECHNICAL HALF OF SEARCH IDENTITY.
 *
 * Neither of these files existed. Without `robots.txt` a crawler has no
 * instruction about the authenticated application at all, and without a
 * sitemap the marketing pages are found only by following links from the
 * home page -- which works, eventually, and is not what a product that
 * wants its brand recognised should rely on.
 *
 * SERVED AS ROUTES, NOT STATIC FILES, for one reason: the canonical host
 * is not known at build time. IOMS is deployed on shared hosting behind a
 * proxy (see bootstrap/app.php's TRUSTED_PROXIES note), so the absolute
 * URLs here have to be generated from the live request rather than baked
 * into a committed file that would be wrong on every other environment.
 *
 * WHAT THIS DOES NOT DO: it does not make the site appear in Google, and
 * nothing about shipping it should be reported as if it did. It makes the
 * site technically eligible and correctly described. Indexing is the
 * search engine's decision and its own timetable.
 */
class SiteIdentityController extends Controller
{
    /**
     * The public marketing surface, in the order a reader would meet it.
     *
     * Deliberately hand-listed rather than derived from the router: most
     * routes in this application are NOT for crawlers (the authenticated
     * app, the platform console, every token-scoped onboarding URL), and
     * a sitemap generated from "all GET routes" would leak exactly those.
     * An allow-list is the safe default here, the same reasoning
     * RestrictDemoTenant uses for its own.
     */
    private const PUBLIC_ROUTES = [
        ['home', '1.0', 'weekly'],
        ['platform-overview', '0.9', 'monthly'],
        ['solutions', '0.9', 'monthly'],
        ['how-it-works', '0.8', 'monthly'],
        ['pricing', '0.9', 'weekly'],
        ['faq', '0.7', 'monthly'],
        ['contact', '0.6', 'yearly'],
        ['get-started', '0.8', 'monthly'],
        ['legal.privacy', '0.3', 'yearly'],
        ['legal.terms', '0.3', 'yearly'],
        ['legal.refunds', '0.3', 'yearly'],
    ];

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            '',
            '# The authenticated product, the platform console and every',
            '# token-scoped onboarding URL. None of these are public pages,',
            '# and all of them sit behind authentication or an unguessable',
            '# token -- this states the intent rather than relying on it.',
            'Disallow: /dashboard',
            'Disallow: /settings',
            'Disallow: /platform',
            'Disallow: /subscription',
            'Disallow: /my-work',
            'Disallow: /get-started/',
            'Disallow: /webhooks/',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = '';

        foreach (self::PUBLIC_ROUTES as [$name, $priority, $frequency]) {
            $urls .= sprintf(
                "    <url>\n        <loc>%s</loc>\n        <changefreq>%s</changefreq>\n        <priority>%s</priority>\n    </url>\n",
                htmlspecialchars(route($name), ENT_XML1),
                $frequency,
                $priority
            );
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .$urls
            ."</urlset>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
