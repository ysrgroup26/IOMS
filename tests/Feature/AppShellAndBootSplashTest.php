<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.128.0 -- WHAT A PHONE SEES BEFORE THE APPLICATION DOES.
 *
 * Three things the owner reported from a handset, pinned here because each
 * is invisible to every other test in the suite:
 *
 *   - the boot splash, which is the first paint of an installed app and is
 *     rendered by the SERVER because React is the thing being waited for;
 *   - the chrome colours, which is where the "black area" came from: an
 *     Android status bar painted from `theme_color`, and an iOS status bar
 *     told to draw white glyphs over a white page;
 *   - the two PTW surfaces whose typography was wrong rather than broken --
 *     a company name set smaller than the document's own title, and a
 *     worked example clipped inside a one-line placeholder and then
 *     repeated underneath.
 *
 * The last two are asserted against the FILES rather than a rendered page,
 * deliberately: a font size and a component choice are what regress, and a
 * screenshot cannot be an assertion.
 */
class AppShellAndBootSplashTest extends TestCase
{
    use RefreshDatabase;

    private function signedIn(): User
    {
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'shell-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit', 'code' => 'SU', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());

        return User::create([
            'name' => 'Admin', 'email' => 'shell-'.uniqid().'@shell.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The boot splash
    |--------------------------------------------------------------------------
    */

    public function test_the_application_is_served_with_a_splash_already_painted(): void
    {
        $response = $this->actingAs($this->signedIn())->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="ioms-boot"', false);
        // The mark itself, inline -- not a link to it, which would paint one
        // request later than the HTML it is meant to accompany.
        $response->assertSee('<svg', false);
        $response->assertSee('#01c1ed', false);
    }

    public function test_the_splash_stays_off_the_public_pages_that_already_have_content(): void
    {
        $this->get('/')->assertOk()->assertDontSee('id="ioms-boot"', false);
    }

    /**
     * A client that will never run the remover must never be shown it. Done
     * with a class the head script adds rather than a <noscript> block,
     * because private pages deliberately carry no <noscript> at all -- see
     * LandingPositioningTest, which pins that the crawler summary is
     * public-only, and which a second kind of <noscript> here would blunt.
     */
    public function test_the_splash_is_hidden_for_a_client_without_javascript(): void
    {
        $response = $this->actingAs($this->signedIn())->get('/dashboard');

        $response->assertSee('#ioms-boot { display: none; }', false);
        $response->assertSee(".classList.add('ioms-js')", false);
        $response->assertSee('.ioms-js #ioms-boot {', false);
        $response->assertDontSee('<noscript>', false);
    }

    public function test_the_splash_is_removed_by_the_bundle_and_by_a_timeout(): void
    {
        $appJs = file_get_contents(resource_path('js/app.jsx'));

        $this->assertStringContainsString("getElementById('ioms-boot')?.remove()", $appJs,
            'The normal path: the splash comes down when React mounts.');

        $this->assertStringContainsString('setTimeout', file_get_contents(resource_path('views/app.blade.php')),
            'The safety net: a bundle that never arrives must not leave a permanent white screen.');
    }

    /*
    |--------------------------------------------------------------------------
    | The chrome colours -- where the dark band came from
    |--------------------------------------------------------------------------
    */

    public function test_the_browser_chrome_is_white_rather_than_the_product_navy(): void
    {
        $response = $this->actingAs($this->signedIn())->get('/dashboard');

        $response->assertSee('<meta name="theme-color" content="#ffffff">', false);
        $response->assertDontSee('content="#00004f"', false);
        // `black-translucent` draws white status-bar glyphs over the page,
        // which on a white application is white on white.
        $response->assertSee('<meta name="apple-mobile-web-app-status-bar-style" content="default">', false);
    }

    public function test_the_installed_app_boots_on_the_same_white_as_the_splash(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('#ffffff', $manifest['background_color']);
        $this->assertSame('#ffffff', $manifest['theme_color'],
            'Android paints the status bar with theme_color; navy here is the dark band that was reported.');
    }

    /*
    |--------------------------------------------------------------------------
    | The stacked lockup: official artwork, composed, not redrawn
    |--------------------------------------------------------------------------
    */

    public function test_the_stacked_lockup_exists_and_is_well_formed(): void
    {
        $path = public_path(ltrim(config('branding.assets.lockup_stacked'), '/'));

        $this->assertFileExists($path);

        $svg = (string) file_get_contents($path);

        $previous = libxml_use_internal_errors(true);
        $this->assertNotFalse(simplexml_load_string($svg), 'The lockup must parse: a broken SVG paints nothing.');
        libxml_use_internal_errors($previous);

        // Both official inks, and nothing fetched from anywhere.
        $this->assertStringContainsString('#01c1ed', $svg);
        $this->assertStringContainsString('#00004f', $svg);
        // Nothing fetched while painting: no <image>, no href. (The only
        // http:// in the file is the SVG namespace, which is a name.)
        $this->assertStringNotContainsString('<image', $svg);
        $this->assertStringNotContainsString('href', $svg);
    }

    public function test_the_lockup_uses_the_official_paths_rather_than_new_ones(): void
    {
        $lockup = (string) file_get_contents(public_path(ltrim(config('branding.assets.lockup_stacked'), '/')));
        $icon = (string) file_get_contents(public_path(ltrim(config('branding.assets.icon'), '/')));
        $logo = (string) file_get_contents(public_path(ltrim(config('branding.assets.logo'), '/')));

        // One path from each source, matched verbatim. If somebody redraws
        // the mark instead of composing the supplied artwork, this fails.
        preg_match('/<path class="st0" d="([^"]{40,})"/', $icon, $iconPath);
        preg_match('/<path class="st2" d="([^"]{40,})"/', $logo, $logoPath);

        $this->assertNotEmpty($iconPath[1] ?? null);
        $this->assertNotEmpty($logoPath[1] ?? null);
        $this->assertStringContainsString($iconPath[1], $lockup, 'The symbol must be the official one, path for path.');
        $this->assertStringContainsString($logoPath[1], $lockup, 'So must the word.');
    }

    /*
    |--------------------------------------------------------------------------
    | The permit header, and the permit form
    |--------------------------------------------------------------------------
    */

    public function test_the_company_name_outranks_the_document_title_on_a_permit(): void
    {
        $blade = (string) file_get_contents(resource_path('views/pdf/permit-to-work.blade.php'));

        preg_match('/\.doc-company-name \{[^}]*font-size: ([\d.]+)px/', $blade, $company);
        preg_match('/\.doc-title-main \{[^}]*font-size: ([\d.]+)px/', $blade, $title);

        $this->assertNotEmpty($company[1] ?? null);
        $this->assertNotEmpty($title[1] ?? null);
        $this->assertGreaterThan(
            (float) $title[1],
            (float) $company[1],
            'The issuer of a controlled document must not be set smaller than the document type.',
        );
    }

    public function test_the_permit_form_shows_each_example_once_inside_its_field(): void
    {
        $form = (string) file_get_contents(resource_path('js/Pages/PermitsToWork/Form.jsx'));

        $this->assertStringContainsString('ExampleInput', $form,
            'A one-line <input> clips a long placeholder; the example belongs in a box that grows.');

        // The helper lines that repeated the placeholder in full.
        $this->assertStringNotContainsString('Examples: Shipyard:', $form);
        $this->assertStringNotContainsString('Physical work area examples', $form);
    }

    /**
     * It must stay a single-line field. A textarea is used only because its
     * placeholder wraps -- not to collect multiple lines.
     */
    public function test_the_example_field_refuses_newlines(): void
    {
        $component = (string) file_get_contents(resource_path('js/Components/shared/form/ExampleInput.jsx'));

        $this->assertStringContainsString("event.key === 'Enter'", $component);
        $this->assertStringContainsString('preventDefault', $component);
        $this->assertStringContainsString('replace(/\\s*[\\r\\n]+\\s*/g', $component,
            'A pasted line break must collapse, or the submitted value differs from what an input would send.');
        // The mirror is the whole mechanism: without it an empty field is
        // one row tall however long its placeholder is.
        $this->assertStringContainsString('aria-hidden="true"', $component);
        $this->assertStringContainsString('whitespace-pre-wrap', $component);
    }
}
