<?php

namespace Tests\Feature;

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
 * v2.103.0 -- PTW ACCESS GRANTED, AND NO WAY TO USE IT.
 *
 * `User::canCreatePtw()` is the single shared gate for raising a Permit To
 * Work: `canManageHse() || ptw_access`. The second half is the entire
 * point of the feature -- a foreman or a supervisor who is not HSE is
 * granted PTW Access so that they can raise permits, and the grant is
 * quota-checked and billed.
 *
 * `PermitToWorkController::index()` sent the page
 * `['manage' => canManageHse()]`, and the only thing that prop gates is
 * the "New Permit" button. So an account that had been granted PTW Access
 * could raise a permit by URL -- the server allows it -- and had no button
 * to do it with. The customer grants the access, pays for it, and the
 * capability appears to do nothing.
 *
 * This is the MIRROR of the defects v2.98.0 and v2.103.0 fixed elsewhere:
 * there the interface offered more than the server allowed, here it
 * offered less. Both are the same rule broken in opposite directions -- a
 * navigation gate must report the access the backend actually grants.
 *
 * Nothing about PTW Usage changes: the meter, its two pools and the quota
 * check are untouched. This is about who is shown the button.
 */
class PtwAccessOfferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'yard-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YC', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** The gate itself, which was always right. */
    public function test_a_granted_account_may_raise_a_permit(): void
    {
        $granted = $this->user('manager', ['ptw_access' => true]);

        $this->assertTrue($granted->canCreatePtw());
        $this->actingAs($granted)->get(route('permits-to-work.create'))->assertOk();
    }

    /**
     * THE DEFECT. The server said yes and the page offered no way to do it.
     */
    public function test_a_granted_account_is_offered_the_action_it_may_perform(): void
    {
        $granted = $this->user('manager', ['ptw_access' => true]);

        $this->actingAs($granted)
            ->get(route('permits-to-work.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.manage', true));
    }

    /** HSE keeps it, which is how it worked before PTW Access existed. */
    public function test_an_hse_user_is_still_offered_the_action(): void
    {
        $hse = $this->user('hse');

        $this->actingAs($hse)
            ->get(route('permits-to-work.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.manage', true));
    }

    /**
     * And an account with NEITHER is still not offered it, so widening the
     * prop did not widen the capability.
     */
    public function test_an_account_without_the_grant_is_not_offered_the_action(): void
    {
        $plain = $this->user('manager');

        $this->assertFalse($plain->canCreatePtw());

        $this->actingAs($plain)
            ->get(route('permits-to-work.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.manage', false));

        // The boundary is still the server's.
        $this->actingAs($plain)->get(route('permits-to-work.create'))->assertForbidden();
    }

    /**
     * The prop reports exactly the gate, so the two cannot drift: whatever
     * `canCreatePtw()` answers is what the page is told.
     */
    public function test_the_offer_tracks_the_gate_for_every_shape_of_account(): void
    {
        foreach ([
            ['manager', [], false],
            ['manager', ['ptw_access' => true], true],
            ['warehouse', ['ptw_access' => true], true],
            ['hse', [], true],
            ['hse', ['ptw_access' => true], true],
        ] as [$role, $overrides, $expected]) {
            $user = $this->user($role, $overrides);

            $this->assertSame($expected, $user->canCreatePtw());

            $this->actingAs($user)
                ->get(route('permits-to-work.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('can.manage', $expected));
        }
    }
}
