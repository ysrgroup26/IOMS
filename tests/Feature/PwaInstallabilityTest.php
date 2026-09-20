<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.73.0 -- PWA INSTALLABILITY, AND THE CACHING BOUNDARY.
 *
 * The manifest assertions are ordinary contract tests. The service-worker
 * ones are the important half, and they are unusual: they assert over the
 * SOURCE of `public/service-worker.js` rather than its behaviour, because
 * PHPUnit cannot run a service worker.
 *
 * That is a real limit and is stated plainly here rather than papered
 * over. What these tests can do is stop the one change that would be
 * catastrophic and is very easy to make by accident -- somebody adding a
 * navigation fallback or an offline page "so the app works on site",
 * which would serve one tenant's cached dashboard to the next person who
 * signs in on that device. The behaviour itself was verified in a browser
 * (see docs/kb/Verification Status), where the cache was confirmed to
 * hold only build and branding assets after visiting six authenticated
 * pages and downloading a PDF.
 */
class PwaInstallabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_public_and_describes_an_installable_app(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();

        $manifest = $response->json();

        // The three fields a browser actually requires before it will
        // offer installation at all.
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['start_url']);

        // A 192 and a 512 PNG are the practical minimum; without both,
        // Chromium silently declines to show an install prompt.
        $sizes = collect($manifest['icons'])->pluck('sizes')->unique();
        $this->assertTrue($sizes->contains('192x192'));
        $this->assertTrue($sizes->contains('512x512'));

        // `any` and `maskable` must be SEPARATE drawings -- see
        // config/branding.php on why one icon declared as both is a
        // compromise rather than a shortcut.
        $purposes = collect($manifest['icons'])->pluck('purpose')->unique();
        $this->assertTrue($purposes->contains('any'));
        $this->assertTrue($purposes->contains('maskable'));

        foreach ($manifest['icons'] as $icon) {
            $this->assertSame(
                'image/png',
                $icon['type'],
                'A manifest icon must be a raster image; no browser installs from an SVG icon alone.'
            );
        }
    }

    /** Every icon the manifest promises has to actually be on disk. */
    public function test_every_manifest_icon_exists(): void
    {
        foreach ($this->get('/manifest.webmanifest')->json('icons') as $icon) {
            $path = public_path(parse_url($icon['src'], PHP_URL_PATH));

            $this->assertFileExists($path, "Manifest icon {$icon['src']} is declared but missing from public/.");
        }
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * A service worker cache is keyed by URL and shared across everything
     * in a browser profile -- it has no idea who was signed in when an
     * entry was written. Caching an authenticated response therefore
     * means the next person to sign in on that device can be served the
     * previous tenant's page without the request ever reaching the
     * server, bypassing every scope and every authorization check in this
     * codebase at once.
     *
     * So the worker is allowed to cache exactly two public prefixes, and
     * this test fails if that list grows.
     */
    public function test_the_service_worker_caches_only_public_build_and_brand_assets(): void
    {
        $source = file_get_contents(public_path('service-worker.js'));

        $this->assertNotFalse($source, 'public/service-worker.js is missing; the app is no longer installable.');

        preg_match('/const CACHEABLE_PREFIXES = \[(.*?)\];/s', $source, $matches);

        $this->assertNotEmpty($matches, 'CACHEABLE_PREFIXES could not be found -- the caching allow-list has been restructured.');

        preg_match_all("/'([^']+)'/", $matches[1], $prefixes);

        $this->assertEqualsCanonicalizing(
            ['/build/', '/branding/'],
            $prefixes[1],
            'The service worker may only cache public build artefacts and brand assets. '
            .'Anything else is authenticated, tenant-scoped, or both -- see the file header and docs/ADR/037.'
        );
    }

    /**
     * A navigation fallback is the specific change that would turn this
     * from a safe worker into a data-leak, because it makes the worker
     * answer PAGE requests -- which are exactly the authenticated,
     * tenant-scoped responses it must never serve.
     */
    public function test_the_service_worker_has_no_navigation_or_offline_fallback(): void
    {
        $source = file_get_contents(public_path('service-worker.js'));

        foreach (["mode === 'navigate'", 'request.mode', 'offline.html', 'navigationPreload'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "The service worker must not handle navigations ({$forbidden}). Serving a cached page would hand "
                .'one tenant of this installation the previous tenant\'s data. See docs/ADR/037.'
            );
        }
    }
}
