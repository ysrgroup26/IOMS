<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\PpeType;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.106.0 -- ONE CUSTOMER COULD REWRITE EVERY OTHER CUSTOMER'S PPE CATALOGUE.
 *
 * Found by sweeping every route that binds a model with NO global isolation
 * scope. Such a model resolves by primary key alone, so the controller must
 * assert ownership itself -- and on `ppe_types` there was no ownership to
 * assert, because the table is deliberately installation-wide.
 *
 * `ppe_types` being shared was a recorded v2.63.0 decision and is fine for
 * READING: six generic rows holding nothing that identifies a customer.
 * What was never asked is who may CHANGE them. Create, update and delete
 * are gated by `role:super_admin` -- the TENANT administrator -- and
 * `PpeTypePolicy` only ever checks a role capability.
 *
 * So any customer's administrator could rename, re-interval or delete a PPE
 * type every other customer was issuing. `destroy()` had noticed half of
 * it and refuses to delete a type in use anywhere; `update()` had no guard
 * at all, and a rename is just as destructive to the customer reading the
 * other end of it. Adding a type was equally leaky in the other direction:
 * one customer's "Sarung Tangan Las" appeared in everybody's dropdown.
 *
 * Resolved with the pattern this codebase already uses for exactly this
 * (KpiCategory, NumberingFormat, NumberingSequence): a nullable
 * `company_id` where null means the shared built-in and a value means the
 * customer's own. Both are visible; only the second is theirs to change.
 */
class SharedMasterDataOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Company $companyA;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        [$this->tenantA, $this->companyA, $this->adminA] = $this->makeTenant('a');
    }

    /** @return array{0: Tenant, 1: Company, 2: User} */
    private function makeTenant(string $suffix): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$suffix, 'slug' => 'y-'.$suffix.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Co '.$suffix, 'code' => strtoupper($suffix).'C',
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($tenant->fresh());

        $admin = User::create([
            'name' => 'Admin '.$suffix, 'email' => 'admin-'.$suffix.'-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
        ]);

        return [$tenant, $company, $admin];
    }

    private function sharedType(string $name = 'Safety Helmet'): PpeType
    {
        // A built-in: no owner, visible to everyone.
        return PpeType::withoutGlobalScopes()->create([
            'company_id' => null,
            'name' => $name,
            'replacement_interval_months' => 12,
            'is_active' => true,
        ]);
    }

    /* ==================================================================
     * THE SHARED BUILT-INS ARE READABLE BY EVERYONE
     * ================================================================== */

    public function test_a_shared_type_is_visible_to_every_tenant(): void
    {
        $shared = $this->sharedType();

        app(CurrentTenant::class)->set($this->tenantA->fresh());
        $this->actingAs($this->adminA);

        $this->assertTrue(
            PpeType::whereKey($shared->id)->exists(),
            'The built-in catalogue is shared on purpose and must stay readable.'
        );
    }

    /* ==================================================================
     * THE DEFECT: ONE CUSTOMER CHANGING EVERYBODY'S DATA
     * ================================================================== */

    public function test_a_tenant_cannot_rename_a_shared_type(): void
    {
        $shared = $this->sharedType();

        $this->actingAs($this->adminA)
            ->put(route('ppe-types.update', $shared->id), [
                'name' => 'Diubah Sepihak',
                'replacement_interval_months' => 1,
                'is_active' => true,
            ]);

        $this->assertSame(
            'Safety Helmet',
            $shared->fresh()->name,
            'A built-in belongs to the installation, not to whichever customer is looking at it.'
        );
        $this->assertSame(12, (int) $shared->fresh()->replacement_interval_months);
    }

    public function test_a_tenant_cannot_delete_a_shared_type(): void
    {
        $shared = $this->sharedType();

        $this->actingAs($this->adminA)
            ->delete(route('ppe-types.destroy', $shared->id));

        $this->assertNotNull($shared->fresh(), 'A built-in must survive one customer deciding it is unwanted.');
    }

    /* ==================================================================
     * A CUSTOMER'S OWN ADDITIONS ARE THEIRS, AND ONLY THEIRS
     * ================================================================== */

    public function test_a_type_a_tenant_adds_belongs_to_that_tenant(): void
    {
        $this->actingAs($this->adminA)
            ->post(route('ppe-types.store'), [
                'name' => 'Sarung Tangan Las',
                'replacement_interval_months' => 6,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $created = PpeType::withoutGlobalScopes()->where('name', 'Sarung Tangan Las')->sole();

        $this->assertSame(
            $this->companyA->id,
            $created->company_id,
            "A customer's own type must not join the shared catalogue."
        );
    }

    public function test_another_tenant_never_sees_it(): void
    {
        $this->actingAs($this->adminA)
            ->post(route('ppe-types.store'), [
                'name' => 'Sarung Tangan Las',
                'replacement_interval_months' => 6,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        [$tenantB, , $adminB] = $this->makeTenant('b');
        app(CurrentTenant::class)->set($tenantB->fresh());
        $this->actingAs($adminB);

        $this->assertSame(
            0,
            PpeType::where('name', 'Sarung Tangan Las')->count(),
            "One customer's addition must not appear in another customer's dropdown."
        );
    }

    public function test_a_tenant_may_edit_its_own_type(): void
    {
        $this->actingAs($this->adminA)
            ->post(route('ppe-types.store'), [
                'name' => 'Sarung Tangan Las',
                'replacement_interval_months' => 6,
                'is_active' => true,
            ]);

        $own = PpeType::withoutGlobalScopes()->where('name', 'Sarung Tangan Las')->sole();

        $this->actingAs($this->adminA)
            ->put(route('ppe-types.update', $own->id), [
                'name' => 'Sarung Tangan Las Panjang',
                'replacement_interval_months' => 3,
                'is_active' => true,
            ]);

        $this->assertSame('Sarung Tangan Las Panjang', $own->fresh()->name);
    }

    /** And another tenant cannot reach it by id. */
    public function test_a_tenants_own_type_cannot_be_edited_across_tenants(): void
    {
        $this->actingAs($this->adminA)
            ->post(route('ppe-types.store'), [
                'name' => 'Milik A',
                'replacement_interval_months' => 6,
                'is_active' => true,
            ]);

        $own = PpeType::withoutGlobalScopes()->where('name', 'Milik A')->sole();

        [$tenantB, , $adminB] = $this->makeTenant('b');
        app(CurrentTenant::class)->set($tenantB->fresh());

        $this->actingAs($adminB)
            ->put(route('ppe-types.update', $own->id), [
                'name' => 'Dibajak',
                'replacement_interval_months' => 1,
                'is_active' => true,
            ])
            ->assertNotFound();

        $this->assertSame('Milik A', $own->fresh()->name);
    }
}
