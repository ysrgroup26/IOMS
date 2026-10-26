<?php

namespace Tests\Feature;

use App\Services\ReleaseStatusService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * v2.133.0 -- the frontend is part of the release.
 *
 * IOMS serves the bundle committed under public/build; no host builds
 * JavaScript. So a commit that adds or renames a page without committing a
 * rebuilt bundle ships PHP that renders a component the browser does not
 * have. These tests pin that the committed bundle is complete, and that the
 * check app:deploy and the release panel rely on can tell when it is not.
 */
class FrontendBundleTest extends TestCase
{
    public function test_the_committed_bundle_contains_every_page_this_release_ships(): void
    {
        $fe = app(ReleaseStatusService::class)->frontend();

        $this->assertTrue($fe['manifest'], 'public/build/manifest.json is missing or unreadable');
        $this->assertTrue($fe['entry_exists'], 'the manifest entry for resources/js/app.jsx does not exist');
        $this->assertGreaterThan(0, $fe['pages_expected']);
        $this->assertSame([], $fe['missing_pages'],
            'public/build is stale: run `npm run build` and commit public/build. Missing: '.implode(', ', $fe['missing_pages']));
        $this->assertContains('Hse/Equipment/Index', $this->pagesIn($fe));
    }

    public function test_a_stale_bundle_is_reported_page_by_page(): void
    {
        $tmp = storage_path('framework/testing/fe-'.uniqid());
        File::ensureDirectoryExists($tmp.'/build/assets');
        File::put($tmp.'/build/manifest.json', json_encode(['resources/js/app.jsx' => ['file' => 'assets/app-old.js', 'isEntry' => true]]));
        // An older bundle: it knows the old Master page and not the register.
        File::put($tmp.'/build/assets/app-old.js', 'const p={"./Pages/Hse/Master.jsx":1,"./Pages/Dashboard.jsx":2};');
        $this->app->usePublicPath($tmp);

        try {
            $fe = app(ReleaseStatusService::class)->frontend();
            $this->assertTrue($fe['entry_exists']);
            $this->assertSame(2, $fe['pages_in_bundle']);
            $this->assertContains('Hse/Equipment/Index', $fe['missing_pages']);
            $this->assertContains('Hse/Equipment/Show', $fe['missing_pages']);
            $this->assertNotContains('Hse/Master', $fe['missing_pages']);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    public function test_a_missing_entry_file_is_not_reported_as_fine(): void
    {
        $tmp = storage_path('framework/testing/fe-'.uniqid());
        File::ensureDirectoryExists($tmp.'/build');
        File::put($tmp.'/build/manifest.json', json_encode(['resources/js/app.jsx' => ['file' => 'assets/gone.js']]));
        $this->app->usePublicPath($tmp);

        try {
            $fe = app(ReleaseStatusService::class)->frontend();
            $this->assertTrue($fe['manifest']);
            $this->assertFalse($fe['entry_exists']);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    private function pagesIn(array $fe): array
    {
        $entry = (string) file_get_contents(public_path('build/'.$fe['entry']));
        preg_match_all('#"\./Pages/([A-Za-z0-9/_-]+)\.jsx"#', $entry, $m);

        return array_unique($m[1]);
    }
}
