<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HseEquipmentType;
use App\Models\PermitToWork;
use App\Models\SafetyEquipment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.53.0 -- the consistency pass.
 *
 * Each group pins a boundary that this release either created or repaired.
 */
class ProductConsistencyV253Test extends TestCase
{
    use RefreshDatabase;

    private function tenantWithCompany(string $name, array $tenantAttributes = []): array
    {
        $tenant = Tenant::create(array_merge([
            'name' => $name, 'slug' => str($name)->slug()->value(), 'status' => Tenant::STATUS_ACTIVE,
        ], $tenantAttributes));

        $company = Company::withoutGlobalScopes()->create([
            'name' => $name, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        app(CurrentTenant::class)->set($tenant);

        return [$tenant, $company];
    }

    private function user(Tenant $tenant, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'User', 'email' => uniqid().'@acme.test', 'password' => bcrypt('secret-pass-1'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ], $attributes));
    }

    /* ================================================================
     * 1. DOCUMENT NUMBERS ARE UNIQUE PER COMPANY, NOT GLOBALLY
     * ================================================================ */

    /**
     * THE DEFECT THIS RELEASE FOUND. Counters became per-tenant in
     * v2.41.0 while the number columns kept a GLOBAL unique index, so
     * every tenant's first permit is PTW-<year>-00001 and only the first
     * tenant in the database could ever have it. The second customer to
     * sign up could not create their first permit at all.
     */
    public function test_two_companies_may_hold_the_same_document_number(): void
    {
        [$tenantA, $companyA] = $this->tenantWithCompany('Alpha Yard');
        $userA = $this->user($tenantA);

        PermitToWork::create([
            'ptw_number' => 'PTW-2026-00001', 'company_id' => $companyA->id,
            'permit_type' => 'hot_work', 'work_description' => 'Welding.',
            'start_datetime' => now(), 'end_datetime' => now()->addHours(4),
            'requested_by' => $userA->id, 'status' => PermitToWork::STATUS_DRAFT,
        ]);

        [$tenantB, $companyB] = $this->tenantWithCompany('Beta Fabrication');
        $userB = $this->user($tenantB);

        // The same number, in a different company. This threw a duplicate
        // key error before v2.53.0.
        $second = PermitToWork::create([
            'ptw_number' => 'PTW-2026-00001', 'company_id' => $companyB->id,
            'permit_type' => 'hot_work', 'work_description' => 'Cutting.',
            'start_datetime' => now(), 'end_datetime' => now()->addHours(4),
            'requested_by' => $userB->id, 'status' => PermitToWork::STATUS_DRAFT,
        ]);

        $this->assertNotNull($second->id);

        // v2.62.0: `withoutGlobalScopes()`. This assertion is about what
        // the DATABASE holds -- two rows sharing a number across two
        // companies -- not about what one tenant may see. Since
        // CompanyOwnedScope arrived, a plain count answers the second
        // question and returns 1, which is the scope working, not the
        // uniqueness rule failing.
        $this->assertSame(2, PermitToWork::withoutGlobalScopes()->where('ptw_number', 'PTW-2026-00001')->count());

        // And the isolation half of the same fact: each tenant sees only
        // its own permit under that number.
        $this->actingAs($userB);
        app(\App\Support\CurrentTenant::class)->set($tenantB);
        $visible = PermitToWork::where('ptw_number', 'PTW-2026-00001')->get();
        $this->assertCount(1, $visible);
        $this->assertSame($companyB->id, $visible->first()->company_id);
    }

    /* ================================================================
     * 2. PTW WORK IDENTITY: four concepts, four fields
     * ================================================================ */

    public function test_work_reference_carries_the_identity_when_no_project_exists(): void
    {
        [, $company] = $this->tenantWithCompany('Gamma Works');

        $permit = new PermitToWork([
            'company_id' => $company->id,
            'project_id' => null,
            'work_reference' => 'Twin Sister 307',
            'location' => 'Engine Room',
            'work_description' => 'Hot work for pipe repair at the engine room.',
        ]);

        // Three answers to three different questions -- what job, where,
        // and what is being done. None of them stands in for another.
        $this->assertSame('Twin Sister 307', $permit->workIdentity());
        $this->assertSame('Engine Room', $permit->location);
        $this->assertStringContainsString('pipe repair', $permit->work_description);
    }

    /** Starter is HSE-only and must still be able to raise a permit without Project Management. */
    public function test_a_permit_can_be_created_without_any_project(): void
    {
        [$tenant, $company] = $this->tenantWithCompany('Delta Marine');
        $hse = $this->user($tenant, ['role' => 'hse', 'ptw_access' => true]);

        $this->actingAs($hse)->post(route('permits-to-work.store'), [
            'company_id' => $company->id,
            'permit_type' => 'hot_work',
            'work_reference' => 'Docking Area 2',
            'location' => 'Main Deck / Port Side',
            'work_description' => 'Grinding and welding on deck plating.',
            'start_datetime' => now()->addHour()->format('Y-m-d\TH:i'),
            'end_datetime' => now()->addHours(6)->format('Y-m-d\TH:i'),
        ])->assertRedirect();

        $permit = PermitToWork::firstOrFail();
        $this->assertNull($permit->project_id);
        $this->assertSame('Docking Area 2', $permit->work_reference);
        $this->assertSame('Docking Area 2', $permit->workIdentity());
    }

    /* ================================================================
     * 3. EQUIPMENT MASTER vs EQUIPMENT REGISTER
     * ================================================================ */

    /**
     * Which lifecycles apply is a property of the TYPE, not the unit. A
     * gas detector is calibrated, a fire extinguisher expires; forcing an
     * expiry date onto equipment that does not expire produces either
     * blank columns or invented data.
     */
    public function test_the_master_declares_which_lifecycles_a_type_has(): void
    {
        [, $company] = $this->tenantWithCompany('Epsilon Industries');

        $detector = HseEquipmentType::create([
            'company_id' => $company->id, 'name' => 'Gas Detector', 'code' => 'GAS-DET',
            'code_prefix' => 'GD', 'tracks_inspection' => true, 'tracks_calibration' => true,
            'tracks_service' => false, 'tracks_expiry' => false, 'is_active' => true,
        ]);

        $extinguisher = HseEquipmentType::create([
            'company_id' => $company->id, 'name' => 'Fire Extinguisher', 'code' => 'FIRE-EXT',
            'code_prefix' => 'FE', 'tracks_inspection' => true, 'tracks_calibration' => false,
            'tracks_service' => false, 'tracks_expiry' => true, 'is_active' => true,
        ]);

        $this->assertSame(['inspection', 'calibration'], $detector->trackedLifecycles());
        $this->assertSame(['inspection', 'expiry'], $extinguisher->trackedLifecycles());
    }

    /** A register entry is an actual unit, identified by its own code and linked to its type. */
    public function test_the_register_holds_units_linked_to_the_master(): void
    {
        [, $company] = $this->tenantWithCompany('Zeta Yard');

        $type = HseEquipmentType::create([
            'company_id' => $company->id, 'name' => 'Gas Detector', 'code' => 'GAS-DET',
            'code_prefix' => 'GD', 'is_active' => true,
        ]);

        $unit = SafetyEquipment::create([
            'company_id' => $company->id,
            'equipment_type_id' => $type->id,
            'equipment_code' => 'GD-001',
            'name' => 'Gas Detector GD-001',
            'type' => 'Gas Detector',
            'brand' => 'MSA',
            'model' => 'Altair 4XR',
            'serial_number' => 'GD001-SN',
            'commissioned_at' => now()->subYear()->toDateString(),
            'next_calibration_due' => now()->addMonths(2)->toDateString(),
            'status' => 'active',
        ]);

        $this->assertSame('GD-001', $unit->equipment_code);
        $this->assertSame($type->id, $unit->equipmentType->id);
        $this->assertCount(1, $type->equipment);
        // The lifecycle date it carries is the one its TYPE tracks.
        $this->assertNotNull($unit->next_calibration_due);
        $this->assertNull($unit->expiry_date);
    }

    /* ================================================================
     * 4. THE SANDBOX
     * ================================================================ */

    private function demoTenant(): array
    {
        [$tenant, $company] = $this->tenantWithCompany('IOMS Sandbox', [
            'slug' => config('ioms.sandbox.tenant_slug'), 'is_demo' => true,
        ]);

        $user = $this->user($tenant, [
            'email' => config('ioms.sandbox.user_email'),
            'role' => User::ROLE_HSE,
            'company_id' => $company->id,
            'ptw_access' => true,
        ]);

        return [$tenant, $company, $user];
    }

    /** A Sandbox visitor may read the product. */
    public function test_a_sandbox_visitor_can_browse(): void
    {
        [, , $demo] = $this->demoTenant();

        $this->actingAs($demo)->get(route('permits-to-work.index'))->assertOk();
    }

    /**
     * ...but may not change settings, billing, or anything outside the
     * short allow-list. A shared demo that one visitor can rewrite is not
     * a demo for the next one.
     */
    public function test_a_sandbox_visitor_cannot_reach_settings_or_billing(): void
    {
        [, , $demo] = $this->demoTenant();

        $this->actingAs($demo)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($demo)->get(route('subscription.billing'))->assertForbidden();
    }

    public function test_a_sandbox_visitor_cannot_perform_arbitrary_writes(): void
    {
        [, $company, $demo] = $this->demoTenant();

        // Not on the allow-list -> refused, whatever the payload.
        $this->actingAs($demo)->post(route('projects.store'), [
            'company_id' => $company->id, 'name' => 'Injected project',
        ])->assertForbidden();
    }

    /** And is bounded by exactly the same machinery as a customer account. */
    public function test_a_sandbox_visitor_cannot_reach_master_admin_or_another_tenant(): void
    {
        [, , $demo] = $this->demoTenant();

        // Master Admin: refused by role, as for any tenant user.
        $this->actingAs($demo)->get(route('platform.dashboard'))->assertForbidden();

        // Another customer's company is simply not visible -- TenantScope,
        // not a demo-specific rule.
        [, $otherCompany] = $this->tenantWithCompany('Real Customer');
        app(CurrentTenant::class)->set($demo->tenant);

        $this->assertFalse(Company::query()->pluck('id')->contains($otherCompany->id));
    }

    /* ================================================================
     * 5. PER-COMPANY AUTHORIZATION (Enterprise multi-company)
     * ================================================================ */

    /**
     * The default is "no grants = all companies in the tenant", so
     * upgrading changes nothing and no customer is locked out of their own
     * data by a migration.
     */
    public function test_a_user_without_grants_sees_every_company_in_their_tenant(): void
    {
        [$tenant, $first] = $this->tenantWithCompany('Multi Co A');
        $second = Company::withoutGlobalScopes()->create(['name' => 'Multi Co B', 'tenant_id' => $tenant->id, 'is_active' => true]);
        $user = $this->user($tenant);

        $this->actingAs($user);
        app(CurrentTenant::class)->set($tenant);

        $visible = Company::query()->pluck('id');

        $this->assertTrue($visible->contains($first->id));
        $this->assertTrue($visible->contains($second->id));
        $this->assertNull($user->authorizedCompanyIds());
    }

    /** Granting a company narrows the user to it. Authorization only ever removes access. */
    public function test_granting_a_company_restricts_the_user_to_it(): void
    {
        [$tenant, $granted] = $this->tenantWithCompany('Enterprise GAJ');
        $other = Company::withoutGlobalScopes()->create(['name' => 'Enterprise MTC', 'tenant_id' => $tenant->id, 'is_active' => true]);

        $user = $this->user($tenant);
        $user->companies()->attach($granted->id);

        $this->actingAs($user);
        app(CurrentTenant::class)->set($tenant);

        $visible = Company::query()->pluck('id');

        $this->assertTrue($visible->contains($granted->id));
        $this->assertFalse($visible->contains($other->id), 'A user must only see companies they are authorized for.');
        $this->assertTrue($user->hasCompanyRestrictions());
    }
}
