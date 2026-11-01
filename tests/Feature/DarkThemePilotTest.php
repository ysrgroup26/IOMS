<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use App\Support\DarkTheme;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\StarterPilotSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * v2.139.0 -- MIDNIGHT FROSTED GLASS IS A STARTER PILOT (ADR 082).
 *
 * Who is offered the dark theme is ONE server answer, read in two places:
 * the shared `darkTheme` prop (whether the top bar shows the switch) and the
 * inline script app.blade.php runs before first paint (whether the page may
 * be painted dark at all). These pin that both agree, that only the listed
 * plans get it, that widening the rollout is configuration, and that the
 * dark stylesheet cannot leak into Light Mode.
 */
class DarkThemePilotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(StarterPilotSeeder::class);
    }

    private function pilot(string $email): User
    {
        return User::withoutGlobalScopes()->where('email', $email)->sole();
    }

    private function userOnPlan(string $slug): User
    {
        $package = Package::where('slug', $slug)->sole();
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $company = Company::withoutGlobalScopes()->create(['name' => ucfirst($slug), 'code' => strtoupper(substr($slug, 0, 4)), 'tenant_id' => $tenant->id, 'is_active' => true]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => Subscription::TYPE_SUBSCRIPTION,
            'billing_cycle' => Subscription::CYCLE_YEARLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addYear(),
        ]);
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant);

        return User::create([
            'name' => 'Admin '.$slug,
            'email' => $slug.'-'.uniqid().'@plan.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function starterRoles(): array
    {
        return [
            'company admin' => ['starter.admin@ioms.local'],
            'hse user' => ['starter.hse@ioms.local'],
        ];
    }

    /** @dataProvider starterRoles */
    public function test_a_starter_account_is_offered_the_dark_theme_before_first_paint_and_in_the_top_bar(string $email): void
    {
        $response = $this->actingAs($this->pilot($email))->get('/hse/dashboard');

        $response->assertOk();
        $response->assertSee('var DARK_THEME_AVAILABLE = true;', false);
        $response->assertInertia(fn (Assert $page) => $page->where('darkTheme', true));
    }

    /** @return array<string, array{0: string}> */
    public static function plans(): array
    {
        return ['professional' => ['professional'], 'business' => ['business'], 'enterprise' => ['enterprise']];
    }

    /**
     * v2.140.0 -- the rollout: `['*']` offers it to an account on ANY plan.
     *
     * @dataProvider plans
     */
    public function test_every_plan_is_offered_the_dark_theme_after_the_rollout(string $plan): void
    {
        $response = $this->actingAs($this->userOnPlan($plan))->get('/hse/dashboard');

        $response->assertOk();
        $response->assertSee('var DARK_THEME_AVAILABLE = true;', false);
        $response->assertInertia(fn (Assert $page) => $page->where('darkTheme', true));
    }

    public function test_a_platform_operator_and_a_my_work_login_are_offered_it_too(): void
    {
        $operator = User::create([
            'name' => 'Operator', 'email' => 'op-'.uniqid().'@ioms.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $this->assertTrue(DarkTheme::availableTo($operator));

        $myWork = $this->userOnPlan('starter');
        $myWork->forceFill(['user_type' => User::TYPE_MY_WORK])->save();
        $this->assertTrue(DarkTheme::availableTo($myWork->fresh()));
    }

    public function test_a_guest_page_is_never_painted_dark(): void
    {
        $this->get('/login')->assertOk()->assertSee('var DARK_THEME_AVAILABLE = false;', false);
        $this->assertFalse(DarkTheme::availableTo(null));
    }

    public function test_a_plan_list_still_narrows_it_and_an_empty_list_turns_it_off(): void
    {
        $business = $this->userOnPlan('business');

        config(['ioms.dark_theme.plans' => ['starter']]);
        $this->actingAs($business)->get('/hse/dashboard')
            ->assertSee('var DARK_THEME_AVAILABLE = false;', false)
            ->assertInertia(fn (Assert $page) => $page->where('darkTheme', false));
        $this->actingAs($this->pilot('starter.admin@ioms.local'))->get('/hse/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('darkTheme', true));

        config(['ioms.dark_theme.plans' => []]);
        $this->actingAs($this->pilot('starter.admin@ioms.local'))->get('/hse/dashboard')
            ->assertSee('var DARK_THEME_AVAILABLE = false;', false)
            ->assertInertia(fn (Assert $page) => $page->where('darkTheme', false));
    }

    public function test_the_pilot_seeder_builds_a_starter_tenant_and_reruns_without_duplicating(): void
    {
        $this->seed(StarterPilotSeeder::class);

        $tenant = Tenant::where('slug', StarterPilotSeeder::TENANT_SLUG)->sole();
        $this->assertSame('starter', $tenant->subscription->package->slug);
        $this->assertSame(['hse'], $tenant->workspaces()->where('tier', 'department')->pluck('key')->all());

        $admin = $this->pilot('starter.admin@ioms.local');
        $hse = $this->pilot('starter.hse@ioms.local');
        $this->assertTrue($admin->isTenantAdmin());
        $this->assertSame(User::ROLE_HSE, $hse->role);
        $this->assertSame('hse', $hse->department_key);

        $this->assertSame(1, Tenant::where('slug', StarterPilotSeeder::TENANT_SLUG)->count());
        $this->assertSame(2, User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_pilot_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            (new StarterPilotSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    /**
     * Light Mode is "visually unchanged" because nothing the dark theme adds
     * can match without `.dark` on <html>. Every selector in the Midnight
     * section of app.css is pinned to that, apart from the switch's own two
     * classes, which exist in both themes.
     */
    public function test_every_midnight_rule_is_scoped_to_the_dark_class(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $header = strrpos($css, 'v2.139.0 -- MIDNIGHT FROSTED GLASS (ADR 082)');
        $this->assertNotFalse($header, 'The Midnight section header is missing from app.css.');
        $section = substr($css, strpos($css, '@layer components {', $header));

        $section = preg_replace('#/\*.*?\*/#s', '', $section);
        preg_match_all('/([^{}]+)\{[^{}]*\}/', $section, $rules);

        $unscoped = [];
        foreach ($rules[1] as $selectorList) {
            $selectorList = trim(preg_replace('/^@layer components\s*\{/', '', trim($selectorList)));
            if ($selectorList === '' || str_starts_with($selectorList, '@')) {
                continue;
            }
            foreach (explode(',', $selectorList) as $selector) {
                $selector = trim(preg_replace('/^@[^{]+\{/', '', $selector));
                if ($selector === '' || preg_match('/^(html)?\.dark\b/', $selector) || preg_match('/^\.theme-switch(-selected|ing)\b/', $selector)) {
                    continue;
                }
                $unscoped[] = $selector;
            }
        }

        $this->assertSame([], $unscoped, 'A Midnight rule that is not scoped to .dark would change Light Mode.');
    }
}
