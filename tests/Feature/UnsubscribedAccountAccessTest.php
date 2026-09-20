<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.74.0 -- AN ACCOUNT WITHOUT AN ORGANIZATION REACHES NOTHING
 * OPERATIONAL.
 *
 * The security half of the account/organization split. Before this
 * release the question could not arise, because you could not be signed
 * in without a tenant unless you were the platform operator. Now that you
 * can, `RequireOrganization` decides where such an account may go -- and
 * it is a FAIL-CLOSED allow-list in the global middleware stack rather
 * than a route-group guard, so a route added tomorrow is protected
 * without anybody remembering to add it to a list.
 *
 * These tests exist because that property is invisible: nothing goes
 * wrong until somebody adds a route and nobody notices it is reachable.
 */
class UnsubscribedAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        return User::create([
            'name' => 'No Org',
            'email' => 'noorg@contoh.test',
            'password' => bcrypt('x'),
            'role' => User::ROLE_ACCOUNT,
            'tenant_id' => null,
            'company_id' => null,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Every operational surface redirects to the Account area rather than
     * rendering an empty, correctly-scoped-to-nothing page.
     *
     * A redirect, not a 403: this account has done nothing wrong and is
     * not being refused -- it is being sent where it actually belongs.
     */
    public function test_operational_routes_redirect_to_the_account_area(): void
    {
        $user = $this->account();

        $routes = [
            '/dashboard', '/my-work', '/incidents', '/investigations',
            '/permits-to-work', '/employees', '/settings', '/work-center',
            '/material-requests', '/projects', '/corrective-actions',
        ];

        foreach ($routes as $route) {
            $this->actingAs($user)
                ->get($route)
                ->assertRedirect('/account');
        }
    }

    /** The places this account IS supposed to be must stay reachable. */
    public function test_the_account_and_subscribe_surfaces_are_reachable(): void
    {
        $user = $this->account();

        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($user)->get('/subscribe')->assertOk();
    }

    /**
     * A platform admin also has no tenant. They must be entirely
     * unaffected -- `hasNoOrganization()` excludes them precisely so this
     * middleware never sees them.
     */
    public function test_a_platform_admin_is_not_caught_by_the_guard(): void
    {
        $operator = User::create([
            'name' => 'Operator',
            'email' => 'operator@ioms.test',
            'password' => bcrypt('x'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $this->assertTrue($operator->isPlatformAdmin());
        $this->assertFalse($operator->hasNoOrganization());

        // Not redirected to /account -- the platform console is their own
        // surface and the guard must not intercept it.
        $this->actingAs($operator)->get(route('platform.dashboard'))->assertOk();
    }

    /** A user WITH an organization is likewise untouched. */
    public function test_a_tenant_user_is_not_caught_by_the_guard(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-ok']);
        $company = Company::withoutGlobalScopes()->create(['name' => 'C', 'tenant_id' => $tenant->id]);

        $user = User::create([
            'name' => 'Tenant User', 'email' => 'tenant@contoh.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    /**
     * An account cannot buy before confirming its address: the invoice and
     * the activation notice would go to an address nobody has proved.
     *
     * This is the ONE thing email verification gates in IOMS -- see
     * EmailVerificationController on why it is not enforced more broadly.
     */
    public function test_an_unverified_account_cannot_enter_the_subscribe_flow(): void
    {
        $user = $this->account();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user)->get('/subscribe')->assertRedirect('/account');
        $this->actingAs($user)
            ->get('/subscribe/professional/organization')
            ->assertRedirect('/account');
    }

    /**
     * The landing route is where every sign-in ends up, so it has to know
     * about the organization-less case or the guard just bounces people
     * in a loop.
     */
    public function test_an_organization_less_account_lands_in_the_account_area(): void
    {
        $this->assertSame('account.overview', $this->account()->landingRouteName());
    }
}
