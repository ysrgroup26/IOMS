<?php

namespace Tests\Feature;

use App\Services\SearchIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.75.0 -- WHAT GOOGLE MAY INDEX, AND HOW EACH PAGE DESCRIBES ITSELF.
 *
 * Driven against https://iomsuite.com in the production environment,
 * because that is the only combination in which anything is indexable (see
 * App\Services\SearchIdentity). Every other combination is asserted to be
 * noindex, which is the half that protects private pages.
 */
class PublicSearchIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://iomsuite.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PackageSeeder::class);
        config(['ioms.public_url' => self::ORIGIN]);
    }

    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    /** @return array<string, array> */
    private function pages(): array
    {
        return app(SearchIdentity::class)->pages();
    }

    private function publicUrl(string $routeName): string
    {
        return self::ORIGIN.route($routeName, [], false);
    }

    private function meta(string $html, string $attr, string $name): ?string
    {
        return preg_match('#<meta '.$attr.'="'.preg_quote($name, '#').'" content="([^"]*)"#', $html, $m)
            ? html_entity_decode($m[1], ENT_QUOTES)
            : null;
    }

    /** @return array<int, array> every JSON-LD block, decoded. */
    private function structuredData(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(function ($json) {
            $decoded = json_decode(trim($json), true);
            $this->assertNotNull($decoded, 'Structured data is not valid JSON: '.json_last_error_msg());

            return $decoded;
        }, $m[1]);
    }

    /* ------------------------------------------------------------------ */
    /* robots.txt                                                          */
    /* ------------------------------------------------------------------ */

    public function test_production_robots_allows_public_pages_and_names_the_canonical_sitemap(): void
    {
        $this->asProduction();

        $body = $this->get(self::ORIGIN.'/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString("User-agent: *\nAllow: /", $body);
        $this->assertStringContainsString('Sitemap: https://iomsuite.com/sitemap.xml', $body);

        foreach (['/dashboard', '/account', '/subscribe', '/platform', '/settings', '/get-started/'] as $private) {
            $this->assertStringContainsString("Disallow: {$private}\n", $body);
        }

        // NOT disallowed: a crawler must be able to fetch these to obey
        // their noindex. A disallowed URL can still be indexed from links.
        $this->assertStringNotContainsString('Disallow: /login', $body);
        $this->assertStringNotContainsString("Disallow: /register\n", $body);
        $this->assertStringNotContainsString("Disallow: /\n", $body);
    }

    public function test_a_non_production_copy_disallows_everything(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
    }

    /* ------------------------------------------------------------------ */
    /* sitemap.xml                                                         */
    /* ------------------------------------------------------------------ */

    public function test_the_sitemap_lists_exactly_the_public_pages_at_their_canonical_urls(): void
    {
        // Served from the LEGACY host on purpose: the sitemap must still
        // name iomsuite.com.
        $xml = $this->get('http://ioms.web.id/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'sitemap.xml is not well-formed XML.');

        $locs = [];
        foreach ($doc->url as $url) {
            $locs[] = (string) $url->loc;
        }

        $expected = array_map(fn ($name) => $this->publicUrl($name), array_keys($this->pages()));
        $this->assertSame($expected, $locs);

        foreach ($locs as $loc) {
            $this->assertStringStartsWith('https://iomsuite.com/', $loc);
        }

        foreach (['/login', '/register', '/get-started', '/account', '/subscribe', '/dashboard', '/sandbox'] as $private) {
            $this->assertStringNotContainsString('https://iomsuite.com'.$private.'<', $xml, "{$private} is in the sitemap.");
        }
    }

    /* ------------------------------------------------------------------ */
    /* The public pages                                                    */
    /* ------------------------------------------------------------------ */

    public function test_every_public_page_is_indexable_with_its_own_title_description_and_canonical(): void
    {
        $this->asProduction();

        $titles = [];
        $descriptions = [];

        foreach ($this->pages() as $name => $page) {
            $response = $this->get($this->publicUrl($name))->assertOk();
            $html = $response->getContent();

            $this->assertFalse($response->headers->has('X-Robots-Tag'), "{$name} is sent X-Robots-Tag.");
            $this->assertStringNotContainsString('name="robots"', $html, "{$name} carries a robots meta tag.");

            $this->assertStringContainsString('<title inertia>'.e($page['title']).'</title>', $html);
            $this->assertSame($page['description'], $this->meta($html, 'name', 'description'));
            $this->assertSame(1, substr_count($html, 'rel="canonical"'), "{$name} must have exactly one canonical.");
            $this->assertStringContainsString('<link rel="canonical" href="'.$this->publicUrl($name).'">', $html);

            // Social cards.
            $this->assertSame($this->publicUrl($name), $this->meta($html, 'property', 'og:url'));
            $this->assertSame($page['title'], $this->meta($html, 'property', 'og:title'));
            $this->assertSame($page['description'], $this->meta($html, 'property', 'og:description'));
            $this->assertStringStartsWith('https://iomsuite.com/branding/', $this->meta($html, 'property', 'og:image'));
            $this->assertSame('summary_large_image', $this->meta($html, 'name', 'twitter:card'));
            $this->assertSame($page['title'], $this->meta($html, 'name', 'twitter:title'));

            // The browser tab matches the server title.
            $response->assertInertia(fn ($p) => $p->where('seoTitle', $page['title']));

            $titles[] = $page['title'];
            $descriptions[] = $page['description'];

            $this->assertLessThanOrEqual(65, mb_strlen($page['title']), "{$name} title will be truncated.");
            $this->assertLessThanOrEqual(170, mb_strlen($page['description']), "{$name} description will be truncated.");
        }

        $this->assertSame($titles, array_unique($titles), 'Two public pages share a title.');
        $this->assertSame($descriptions, array_unique($descriptions), 'Two public pages share a description.');
    }

    public function test_the_product_name_is_never_expanded(): void
    {
        $this->asProduction();

        foreach (array_keys($this->pages()) as $name) {
            // The <head> only: the release notes shared with every page
            // quote the forbidden expansion in order to forbid it.
            $head = strstr($this->get($this->publicUrl($name))->getContent(), '</head>', true);

            $this->assertStringNotContainsString('Integrated Operations Management System', $head);
            $this->assertStringContainsString('IOMS', $head);
        }
    }

    public function test_legal_documents_are_marked_as_indonesian_and_the_rest_as_english(): void
    {
        $this->asProduction();

        $this->get($this->publicUrl('legal.privacy'))->assertSee('<html lang="id"', false)
            ->assertSee('content="id_ID"', false);
        $this->get($this->publicUrl('home'))->assertSee('<html lang="en"', false);
        $this->get($this->publicUrl('pricing'))->assertSee('<html lang="en"', false);
    }

    /* ------------------------------------------------------------------ */
    /* Structured data                                                     */
    /* ------------------------------------------------------------------ */

    public function test_the_home_page_describes_the_organization_the_website_and_the_software(): void
    {
        $this->asProduction();

        $blocks = $this->structuredData($this->get($this->publicUrl('home'))->getContent());
        $this->assertCount(1, $blocks, 'One JSON-LD graph, not several competing blocks.');

        $graph = collect($blocks[0]['@graph'])->keyBy('@type');
        $this->assertSame('https://schema.org', $blocks[0]['@context']);
        $this->assertEqualsCanonicalizing(['Organization', 'WebSite', 'SoftwareApplication'], $graph->keys()->all());

        $org = $graph['Organization'];
        $this->assertSame('IOMS', $org['name']);
        $this->assertSame('https://iomsuite.com/', $org['url']);
        $this->assertSame('https://iomsuite.com/branding/ioms-logo.png', $org['logo']);

        $this->assertSame('https://iomsuite.com/', $graph['WebSite']['url']);
        $this->assertSame($org['@id'], $graph['WebSite']['publisher']['@id']);

        $app = $graph['SoftwareApplication'];
        $this->assertSame('BusinessApplication', $app['applicationCategory']);
        $this->assertSame('AggregateOffer', $app['offers']['@type']);
        $this->assertSame('IDR', $app['offers']['priceCurrency']);
        $this->assertLessThanOrEqual((float) $app['offers']['highPrice'], (float) $app['offers']['lowPrice']);

        // No URL anywhere in the graph points off the canonical origin.
        array_walk_recursive($blocks, function ($value, $key) {
            if (in_array($key, ['url', 'logo', 'image', '@id'], true)) {
                $this->assertStringStartsWith('https://iomsuite.com/', $value, "{$key} is not canonical.");
            }
        });
    }

    public function test_other_public_pages_carry_only_the_organization(): void
    {
        $this->asProduction();

        $blocks = $this->structuredData($this->get($this->publicUrl('pricing'))->getContent());

        $this->assertSame(['Organization'], array_column($blocks[0]['@graph'], '@type'));
    }

    /* ------------------------------------------------------------------ */
    /* What must NOT be indexed                                            */
    /* ------------------------------------------------------------------ */

    public function test_sign_in_and_registration_are_noindex_and_carry_no_public_metadata(): void
    {
        $this->asProduction();

        foreach (['/login', '/register', '/forgot-password'] as $path) {
            $response = $this->get(self::ORIGIN.$path)->assertOk();
            $html = $response->getContent();

            $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
            $this->assertStringNotContainsString('rel="canonical"', $html, "{$path} declares a canonical.");
            $this->assertStringNotContainsString('application/ld+json', $html, "{$path} carries structured data.");
            $response->assertInertia(fn ($p) => $p->where('seoTitle', null));
        }
    }

    public function test_private_and_redirecting_routes_are_noindex(): void
    {
        $this->asProduction();

        foreach (['/get-started', '/account', '/subscribe', '/dashboard', '/register/welcome', '/sandbox'] as $path) {
            $this->get(self::ORIGIN.$path)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    /** The legacy domain serves the same pages but must not compete with the real one. */
    public function test_the_legacy_host_is_noindex_but_points_at_the_canonical_page(): void
    {
        $this->asProduction();

        $html = $this->get('https://ioms.web.id/pricing')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://iomsuite.com/pricing">', $html);
        $this->assertStringNotContainsString('ioms.web.id', $this->meta($html, 'property', 'og:url'));
    }

    public function test_a_non_production_environment_is_never_indexable(): void
    {
        // Canonical host, but the testing environment.
        $this->get($this->publicUrl('pricing'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
