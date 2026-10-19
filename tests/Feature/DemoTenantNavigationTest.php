<?php

namespace Tests\Feature;

use App\Http\Middleware\RestrictDemoTenant;
use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.124.0 -- THE SANDBOX STOPS OFFERING WHAT THE SANDBOX REFUSES.
 *
 * `RestrictDemoTenant` blocks `settings.*`, `subscription.*` and
 * `platform.*` for the IOMS Sandbox, and that is correct: a visitor must
 * not change the demonstration's identity or read commercial data.
 *
 * What was wrong is that the product went on offering those doors. Crawled
 * in a browser against the seeded Sandbox: the administrator was offered
 * Settings and Subscription & Billing from the sidebar AND from nine
 * places on the Administration Overview, and the HSE account's Admin
 * Space entry pointed at Settings. Every one answered 403.
 *
 * Same decision as ADR 061, reached from the other direction: that one
 * bound an ALLOW-list to the navigation, this binds a DENY-list.
 */
class DemoTenantNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(bool $demo): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => $demo ? 'Sandbox' : 'Customer',
            'slug' => ($demo ? 'sandbox-' : 'customer-').uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
            'is_demo' => $demo,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YC'.rand(100, 999),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(30),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());

        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        return [$tenant, $company, $admin];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    /** The refusal itself, unchanged -- so the assertions below mean something. */
    public function test_the_sandbox_still_refuses_settings_and_billing(): void
    {
        [, , $admin] = $this->tenant(demo: true);

        $this->actingAs($admin)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('subscription.billing'))->assertForbidden();
    }

    public function test_the_sandbox_is_told_which_prefixes_are_refused(): void
    {
        [, , $admin] = $this->tenant(demo: true);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'auth.user.blocked_route_prefixes',
                RestrictDemoTenant::blockedPrefixes(),
            ));
    }

    /** An ordinary customer has no deny-list and must not be told it has one. */
    public function test_a_customer_tenant_carries_no_deny_list(): void
    {
        [, , $admin] = $this->tenant(demo: false);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.blocked_route_prefixes', null));

        // And it still reaches the pages the Sandbox is refused.
        $this->actingAs($admin)->get(route('settings.index'))->assertOk();
    }

    /**
     * The navigation layer and the Administration Overview both ask the
     * shared helper rather than hard-coding which tenants see what.
     */
    public function test_the_navigation_and_the_overview_read_the_deny_list(): void
    {
        $workspaces = file_get_contents(resource_path('js/lib/workspaces.js'));

        $this->assertStringContainsString('export function filterNavByBlockedPrefixes(', $workspaces);
        $this->assertStringContainsString('export function isRouteBlockedForUser(', $workspaces);
        $this->assertStringContainsString('blocked_route_prefixes', $workspaces);

        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
        $this->assertStringContainsString('filterNavByServerRules(', $layout);

        $overview = file_get_contents(resource_path('js/Pages/Admin/Overview.jsx'));
        $this->assertStringContainsString('isRouteBlockedForUser', $overview);
        $this->assertStringContainsString("blocked('settings.index')", $overview);
        $this->assertStringContainsString("blocked('subscription.billing')", $overview);
    }
}
