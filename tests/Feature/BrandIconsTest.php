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
}
