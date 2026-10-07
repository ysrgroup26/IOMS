<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
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
 * v2.106.0 -- WHERE THE COMMERCIAL GUARD ACTUALLY LIVES.
 *
 * A sweep of every mutating route flagged `subscription/renew` and both
 * `subscription/plan-change` verbs as carrying no role middleware and no
 * guard in the method body, beside five siblings that check
 * `canManageSystemSettings()` inline. THAT READING WAS WRONG, and this
 * class exists so nobody repeats it.
 *
 * The guard is in `currentSubscription()`, the shared helper every one of
 * these mutations calls as its first statement:
 *
 *     abort_unless($request->user()->canManageSystemSettings(), 403);
 *
 * One guard, on the one path all of them pass through, is better than
 * eight copies -- but it is invisible to any check that reads a method body
 * or a route's middleware list, which is how it came to be suspected.
 *
 * These tests therefore pin the BEHAVIOUR rather than the shape: the
 * protection is proven from outside, whatever the implementation looks
 * like, and the last test pins that every mutation still goes through the
 * guarded path rather than around it.
 *
 * The routes deliberately carry no role middleware: they sit in the group
 * EnforceSubscriptionWriteAccess keeps writable while a subscription is
 * lapsed, because blocking the page that fixes the problem would be absurd.
 */
class CommercialAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'professional')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'y-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YC', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    /* ==================================================================
     * AN ORDINARY MEMBER MAY NOT MAKE A COMMERCIAL DECISION
     * ================================================================== */

    /** @return array<string, array{0: string}> */
    public static function ordinaryRoles(): array
    {
        return [
            'hse' => ['hse'],
            'warehouse' => ['warehouse'],
            'manager' => ['manager'],
            'hrd' => ['hrd'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryRoles')]
    public function test_an_ordinary_member_cannot_renew_the_subscription(string $role): void
    {
        $member = $this->user($role);

        $this->actingAs($member)
            ->post(route('subscription.renew'))
            ->assertForbidden();

        $this->assertSame(
            0,
            Invoice::where('tenant_id', $this->tenant->id)->count(),
            'Raising an invoice is a commercial act, not an ordinary one.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ordinaryRoles')]
    public function test_an_ordinary_member_cannot_change_the_plan(string $role): void
    {
        $member = $this->user($role);
        $business = Package::where('slug', 'business')->sole();

        $this->actingAs($member)
            ->post(route('subscription.plan-change'), [
                'package_id' => $business->id,
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
            ])
            ->assertForbidden();

        $this->assertNull(
            $this->subscription->fresh()->pending_package_id,
            "The organisation's plan is the administrator's decision."
        );
    }

    public function test_an_ordinary_member_cannot_cancel_a_scheduled_plan_change(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        // A DOWNGRADE is what gets scheduled for the end of the period; an
        // upgrade raises an invoice and applies when it is paid.
        $starter = Package::where('slug', 'starter')->sole();

        $this->actingAs($admin)
            ->post(route('subscription.plan-change'), [
                'package_id' => $starter->id,
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
            ]);

        $scheduled = $this->subscription->fresh()->pending_package_id;
        $this->assertNotNull($scheduled, 'A downgrade is scheduled rather than invoiced.');

        $this->actingAs($this->user('hse'))
            ->delete(route('subscription.plan-change'))
            ->assertForbidden();

        $this->assertSame(
            $scheduled,
            $this->subscription->fresh()->pending_package_id,
            'An ordinary member must not undo what the administrator scheduled.'
        );
    }

    /* ==================================================================
     * THE ADMINISTRATOR IS UNAFFECTED
     * ================================================================== */

    public function test_the_administrator_can_still_renew(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)
            ->post(route('subscription.renew'))
            ->assertRedirect();

        $this->assertGreaterThan(
            0,
            Invoice::where('tenant_id', $this->tenant->id)->count(),
            'The administrator must still be able to renew.'
        );
    }

    public function test_the_administrator_can_still_change_the_plan(): void
    {
        $admin = $this->user(User::ROLE_SUPER_ADMIN);
        $business = Package::where('slug', 'business')->sole();

        // An upgrade is sold now: an invoice is raised and the customer is
        // sent to pay it, rather than the change being scheduled.
        $this->actingAs($admin)
            ->post(route('subscription.plan-change'), [
                'package_id' => $business->id,
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
            ])
            ->assertRedirect();

        $this->assertSame(
            1,
            Invoice::where('tenant_id', $this->tenant->id)
                ->where('purpose', Invoice::PURPOSE_PLAN_CHANGE)
                ->count(),
            'An upgrade raises an invoice for the administrator.'
        );
    }

    /**
     * THE GUARD IS SHARED, AND EVERY MUTATION MUST GO THROUGH IT.
     *
     * `currentSubscription()` carries the capability check, so a mutation
     * that resolved the subscription some other way would silently lose it
     * -- which is exactly what a reader checking method bodies would not
     * notice. Pinned here so the one guard stays on the one path.
     */
    public function test_every_commercial_mutation_resolves_through_the_guarded_helper(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SubscriptionController.php'));

        preg_match('/private function currentSubscription\(.*?\n    \}/s', $source, $helper);

        $this->assertNotEmpty($helper, 'currentSubscription() should exist.');
        $this->assertStringContainsString(
            'canManageSystemSettings',
            $helper[0],
            'The shared helper is where the commercial capability is checked.'
        );

        foreach ([
            'renew', 'changePlan', 'cancelPlanChange', 'updateAdditionalUsers',
            'purchaseAddOn', 'cancelAddOn',
        ] as $method) {
            preg_match('/public function '.$method.'\(.*?\n    \}/s', $source, $body);

            $this->assertNotEmpty($body, "{$method}() should exist.");
            $this->assertStringContainsString(
                'currentSubscription(',
                $body[0],
                "{$method}() must resolve through the guarded helper, not around it."
            );
        }
    }
}
