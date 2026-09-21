<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\SearchIdentity;
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
 * SERVED AS ROUTES, NOT STATIC FILES, because their content depends on
 * the environment (a non-production copy must say "Disallow: /").
 *
 * v2.75.0 -- the page list and every absolute URL now come from
 * App\Services\SearchIdentity: the pages from config/seo.php (the same
 * allow-list the noindex middleware and the layout use, so the sitemap
 * cannot list a page that is sent noindex), and the host from
 * config('ioms.public_url'), so the sitemap names https://iomsuite.com
 * whichever host -- legacy, proxy-internal or local -- served it.
 *
 * (Historical note from v2.72.0: the canonical host is not known at build
 * time. IOMS is deployed on shared hosting behind a
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
    public function __construct(private readonly SearchIdentity $search) {}

    /**
     * robots.txt.
     *
     * Deliberately does NOT disallow /login or /register: those are sent
     * `noindex`, and a crawler can only obey a noindex it is allowed to
     * fetch. A disallowed URL can still be indexed from links alone, as a
     * bare address with no description -- the opposite of the intent.
     *
     * The private prefixes below ARE disallowed: they redirect a crawler
     * to sign-in anyway, so there is nothing to read, and saying so saves
     * the crawl. The real protection is the noindex default, not this list.
     */
    public function robots(): Response
    {
        // A staging or development copy must never be crawled at all.
        if (! app()->isProduction()) {
            return $this->text(implode("\n", [
                'User-agent: *',
                '# Not the production site. See https://iomsuite.com.',
                'Disallow: /',
                '',
            ]));
        }

        $lines = [
            'User-agent: *',
            'Allow: /',
            '',
            '# The authenticated product, the platform console, the account',
            '# area and every token-scoped order URL. None are public pages;',
            '# every response from them is also sent X-Robots-Tag: noindex.',
            'Disallow: /dashboard',
            'Disallow: /settings',
            'Disallow: /platform',
            'Disallow: /subscription',
            'Disallow: /subscribe',
            'Disallow: /account',
            'Disallow: /my-work',
            'Disallow: /email/',
            'Disallow: /get-started/',
            'Disallow: /webhooks/',
            '',
            'Sitemap: '.$this->search->assetUrl('sitemap.xml'),
            '',
        ];

        return $this->text(implode("\n", $lines));
    }

    /**
     * sitemap.xml -- exactly the pages in config/seo.php, at their canonical
     * https://iomsuite.com addresses, and nothing else.
     */
    public function sitemap(): Response
    {
        $urls = '';

        foreach ($this->search->pages() as $routeName => $page) {
            $urls .= sprintf(
                "    <url>\n        <loc>%s</loc>\n        <changefreq>%s</changefreq>\n        <priority>%s</priority>\n    </url>\n",
                htmlspecialchars($this->search->canonicalUrl($routeName), ENT_XML1),
                $page['changefreq'] ?? 'monthly',
                $page['priority'] ?? '0.5'
            );
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .$urls
            ."</urlset>\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
