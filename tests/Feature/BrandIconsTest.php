<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.76.0 -- THE FAVICON IS A SMALL-FORMAT BRAND ASSET, NOT A SHRUNKEN LOGO.
 *
 * Square, opaque, the official mark on its navy ground, reachable at the
 * path crawlers ask for without reading any HTML (/favicon.ico), and
 * referenced from every page. See config/branding.php.
 */
class BrandIconsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_icon_asset_exists_is_square_and_opaque(): void
    {
        foreach (['favicon_48', 'favicon_png', 'apple_touch_icon', 'icon_192', 'icon_512', 'maskable_192', 'maskable_512'] as $key) {
            $path = public_path(config("branding.assets.{$key}"));
            $this->assertFileExists($path, "{$key} is missing.");

            [$width, $height] = getimagesize($path);
            $this->assertSame($width, $height, "{$key} is not square.");

            $corner = imagecolorat(imagecreatefrompng($path), 0, 0);
            $this->assertSame(0, ($corner >> 24) & 0x7F, "{$key} is transparent; it would float on a light search surface.");
        }

        // Google prefers a favicon of at least 48px.
        $this->assertSame(48, getimagesize(public_path(config('branding.assets.favicon_48')))[0]);
    }

    public function test_favicon_ico_is_a_real_multi_size_icon_at_the_root(): void
    {
        $ico = file_get_contents(public_path('favicon.ico'));

        // ICONDIR: reserved 0, type 1 (icon), then the image count.
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        $this->assertSame(['reserved' => 0, 'type' => 1, 'count' => 3], $header);

        $sizes = [];
        for ($i = 0; $i < 3; $i++) {
            $entry = unpack('Cwidth/Cheight', substr($ico, 6 + 16 * $i, 2));
            $sizes[] = $entry['width'];
            $this->assertSame($entry['width'], $entry['height']);
        }
        $this->assertSame([16, 32, 48], $sizes);
    }

    public function test_the_head_references_the_current_icons_and_no_legacy_asset(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);

        $head = strstr($this->get(route('home'))->getContent(), '</head>', true);

        $this->assertStringContainsString('/favicon.ico" sizes="16x16 32x32 48x48"', $head);
        $this->assertStringContainsString('/branding/ioms-favicon-48.png', $head);
        $this->assertStringContainsString('/branding/ioms-favicon.svg', $head);
        $this->assertStringContainsString('/branding/ioms-apple-touch-icon.png', $head);
        $this->assertStringContainsString('rel="manifest"', $head);

        // The pre-rebrand assets: a 2 MB photograph of a neon "icms" sign.
        foreach (['branding/icon.png', 'branding/wordmark.png'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $head);
        }
    }

    /* ------------------------------------------------------------------ */
    /* v2.78.2 -- ONE SOURCE, CONSUMED EVERYWHERE                          */
    /* ------------------------------------------------------------------ */

    /**
     * Every icon the head and the manifest declare is the file
     * config/branding.php names, and each one is really served.
     */
    public function test_every_declared_icon_resolves_to_the_canonical_file(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);

        $head = strstr($this->get(route('home'))->getContent(), '</head>', true);

        foreach (['favicon_ico', 'favicon_48', 'favicon', 'favicon_png', 'apple_touch_icon'] as $key) {
            $path = config("branding.assets.{$key}");

            $this->assertStringContainsString($path, $head, "The head does not use branding.assets.{$key}.");
            $this->assertFileExists(public_path($path));
        }

        $manifest = $this->get(route('manifest'))->assertOk()->json();

        $declared = collect($manifest['icons'])->pluck('src')->map(fn ($u) => parse_url($u, PHP_URL_PATH))->all();
        $expected = collect(['icon_192', 'icon_512', 'maskable_192', 'maskable_512'])
            ->map(fn ($k) => config("branding.assets.{$k}"))->all();

        $this->assertEqualsCanonicalizing($expected, $declared, 'The manifest names icons the branding config does not.');
    }

    /**
     * No surface hardcodes a brand file. The one place the logo is named in
     * React is BrandWordmark (via shared props); the one place icons are
     * named in HTML is the layout, through config.
     */
    public function test_no_component_hardcodes_a_brand_asset_path(): void
    {
        $offenders = [];

        foreach (['js' => resource_path('js'), 'views' => resource_path('views')] as $tree) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['jsx', 'js', 'php'], true)) {
                    continue;
                }

                // Comments explain the history; markup must not name a file.
                $source = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\{\{--.*?--\}\}#s'], '', file_get_contents($file->getPathname()));

                if (preg_match('#["\']/branding/#', $source) && ! str_contains($source, "config('branding")) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, "These name a brand file directly instead of using the canonical source:\n".implode("\n", $offenders));
    }

    /** The branding config declares each asset exactly once. */
    public function test_the_branding_config_names_each_asset_once(): void
    {
        $assets = config('branding.assets');

        $this->assertSame(array_unique(array_keys($assets)), array_keys($assets));

        // v2.78.2: `default_wordmark_path` / `default_icon_path` were a second,
        // unread declaration of assets.logo / assets.icon.
        $this->assertArrayNotHasKey('default_wordmark_path', config('branding'));
        $this->assertArrayNotHasKey('default_icon_path', config('branding'));
    }

    /**
     * v2.78.2 -- THE SERVICE WORKER MUST NOT PIN BRAND ASSETS FOREVER.
     *
     * Brand filenames are stable, so cache-first with no revalidation kept
     * serving the old logo and favicon after a deploy -- and no amount of
     * clearing the BROWSER cache touched it.
     */
    public function test_the_service_worker_revalidates_brand_assets(): void
    {
        $worker = file_get_contents(public_path('service-worker.js'));

        // Bumped, so the caches holding the old artwork are evicted once.
        $this->assertStringContainsString("const CACHE_NAME = 'ioms-static-v2'", $worker);

        // Hashed build files stay cache-first; stable brand files revalidate.
        $this->assertStringContainsString("const IMMUTABLE_PREFIXES = ['/build/']", $worker);
        $this->assertStringContainsString("const REVALIDATE_PREFIXES = ['/branding/']", $worker);
        $this->assertStringContainsString('shouldRevalidate(url)', $worker);

        // And the allow-list is still exactly those two, nothing authenticated.
        $this->assertStringNotContainsString("'/storage/'", $worker);
    }
}
