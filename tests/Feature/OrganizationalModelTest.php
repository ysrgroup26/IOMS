<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.54.0 -- THE ORGANIZATIONAL MODEL.
 *
 *     IOMS -> Organization -> Operating Unit(s) -> Departments
 *
 * Tenant is the Organization, Company is an Operating Unit. These tests
 * pin the three things that make that model real rather than a rename:
 * capacity is counted and ENFORCED in operating units, authorization is
 * per operating unit and server-side, and an all-units grant is stored as
 * "unrestricted" so a later unit is not silently excluded.
 */
class OrganizationalModelTest extends TestCase
{
    use RefreshDatabase;

    private function organization(string $name, ?int $maxUnits = null): array
    {
        $tenant = Tenant::create([
            'name' => $name, 'slug' => str($name)->slug()->value(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $package = Package::create([
            'name' => $name.' Plan', 'slug' => str($name)->slug()->value().'-plan',
            'price_monthly' => 100000, 'price_yearly' => 1000000, 'currency' => 'IDR',
            'max_users' => null, 'max_companies' => $maxUnits, 'is_active' => true, 'is_public' => false,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => 'active', 'type' => 'paid', 'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(),
        ]);

        app(CurrentTenant::class)->set($tenant);

        return [$tenant->fresh(), $package];
    }

    private function unit(Tenant $tenant, string $name): Company
    {
        return Company::withoutGlobalScopes()->create([
            // Unique WITHIN the organization -- the composite index this
            // release introduced is the rule, and the helper obeys it.
            'name' => $name, 'code' => strtoupper(str_replace(' ', '', $name)),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
    }

    private function admin(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Admin', 'email' => uniqid().'@acme.test', 'password' => bcrypt('secret-pass-1'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
    }

    /* ================================================================
     * 1. CAPACITY IS COUNTED IN OPERATING UNITS -- AND ENFORCED
     * ================================================================ */

    public function test_operating_unit_limit_comes_from_the_plan(): void
    {
        [$tenant] = $this->organization('Starter Org', 1);

        $this->assertSame(1, app(EntitlementService::class)->operatingUnitLimit($tenant));
    }

    public function test_a_null_limit_means_unlimited_operating_units(): void
    {
        [$tenant] = $this->organization('Enterprise Org', null);
        $this->unit($tenant, 'Yard One');
        $this->unit($tenant, 'Yard Two');
        $this->unit($tenant, 'Yard Three');

        $this->assertTrue(app(EntitlementService::class)->canCreateOperatingUnit($tenant));
    }

    public function test_professional_allows_a_second_operating_unit_but_not_a_third(): void
    {
        [$tenant] = $this->organization('Professional Org', 2);
        $service = app(EntitlementService::class);

        $this->unit($tenant, 'Yard One');
        $this->assertTrue($service->canCreateOperatingUnit($tenant), 'A second unit must be allowed on a 2-unit plan.');

        $this->unit($tenant, 'Yard Two');
        $this->assertFalse($service->canCreateOperatingUnit($tenant), 'A third unit must be refused on a 2-unit plan.');
    }

    /**
     * The count is a property of the ORGANIZATION, never of the person
     * asking. Company carries CompanyAuthorizationScope, so counting
     * through the scoped model would let an administrator restricted to
     * one unit count 1 and create past their plan's limit.
     */
    public function test_the_usage_count_ignores_the_current_users_own_restrictions(): void
    {
        [$tenant] = $this->organization('Restricted Org', 2);
        $unitOne = $this->unit($tenant, 'Yard One');
        $this->unit($tenant, 'Yard Two');

        $admin = $this->admin($tenant);
        $admin->companies()->sync([$unitOne->id]);
        $this->actingAs($admin);

        $this->assertSame(2, app(EntitlementService::class)->operatingUnitsUsedCount($tenant));
        $this->assertFalse(app(EntitlementService::class)->canCreateOperatingUnit($tenant));
    }

    public function test_creating_an_operating_unit_past_the_plan_limit_is_refused_server_side(): void
    {
        [$tenant] = $this->organization('Capped Org', 1);
        $this->unit($tenant, 'Yard One');
        $admin = $this->admin($tenant);

        $this->actingAs($admin)
            ->post(route('settings.companies.store'), ['name' => 'Yard Two', 'code' => 'YT'])
            ->assertStatus(422);

        $this->assertSame(1, Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_creating_an_operating_unit_within_the_limit_succeeds(): void
    {
        [$tenant] = $this->organization('Growing Org', 2);
        $this->unit($tenant, 'Yard One');
        $admin = $this->admin($tenant);

        $this->actingAs($admin)
            ->post(route('settings.companies.store'), ['name' => 'Yard Two', 'code' => 'YT'])
            ->assertRedirect();

        $this->assertSame(2, Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    /* ================================================================
     * 1b. AN OPERATING UNIT'S NAME IS UNIQUE WITHIN AN ORGANIZATION
     * ================================================================ */

    /**
     * THE DEFECT THIS RELEASE FOUND, and it is the v2.53.0
     * document-number defect a second time: `companies.name` and
     * `companies.code` carried GLOBAL unique indexes while the
     * application validated them per tenant.
     *
     * It broke provisioning, not just data entry.
     * TenantProvisioningService::activate() creates the first operating
     * unit named after the registration, with a code taken from the
     * first six characters of the slugged name -- so two customers
     * called "PT Maju Jaya", or merely two whose names start alike,
     * collided on a unique key AFTER their payment had been confirmed.
     */
    public function test_two_organizations_may_use_the_same_operating_unit_name(): void
    {
        [$tenantA] = $this->organization('Org A', null);
        Company::withoutGlobalScopes()->create([
            'name' => 'PT Maju Jaya', 'code' => 'PTMAJU', 'tenant_id' => $tenantA->id, 'is_active' => true,
        ]);

        [$tenantB] = $this->organization('Org B', null);
        $second = Company::withoutGlobalScopes()->create([
            'name' => 'PT Maju Jaya', 'code' => 'PTMAJU', 'tenant_id' => $tenantB->id, 'is_active' => true,
        ]);

        $this->assertTrue($second->exists);
        $this->assertSame(2, Company::withoutGlobalScopes()->where('name', 'PT Maju Jaya')->count());
    }

    /** The rule still holds INSIDE one organization: two units there may not share a name. */
    public function test_one_organization_may_not_repeat_an_operating_unit_name(): void
    {
        [$tenant] = $this->organization('Org A', null);
        $admin = $this->admin($tenant);

        $this->actingAs($admin)->post(route('settings.companies.store'), ['name' => 'Yard One', 'code' => 'Y1'])
            ->assertRedirect();

        $this->actingAs($admin)->post(route('settings.companies.store'), ['name' => 'Yard One', 'code' => 'Y2'])
            ->assertSessionHasErrors('name');
    }

    /* ================================================================
     * 2. LEGAL ENTITY IS AN ATTRIBUTE, NOT A SCOPE
     * ================================================================ */

    public function test_an_operating_unit_may_record_the_legal_entity_it_trades_as(): void
    {
        [$tenant] = $this->organization('Multi Entity Org', null);
        $admin = $this->admin($tenant);

        $this->actingAs($admin)->post(route('settings.companies.store'), [
            'name' => 'Yard One', 'code' => 'Y1',
            'legal_entity_name' => 'PT Nusantara Marine', 'legal_entity_registration' => '01.234.567.8-901.000',
        ])->assertRedirect();

        $unit = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('PT Nusantara Marine', $unit->legal_entity_name);
        $this->assertSame('PT Nusantara Marine', $unit->legalEntityName());
    }

    /** With no legal entity recorded -- the ordinary case -- the unit speaks for itself. */
    public function test_legal_entity_name_falls_back_to_the_operating_unit_name(): void
    {
        [$tenant] = $this->organization('Single Entity Org', null);
        $unit = $this->unit($tenant, 'Yard One');

        $this->assertSame('Yard One', $unit->legalEntityName());
    }

    /* ================================================================
     * 3. PER-OPERATING-UNIT AUTHORIZATION IS SERVER-SIDE AND REACHABLE
     * ================================================================ */

    public function test_granting_specific_units_restricts_what_the_user_can_reach(): void
    {
        [$tenant] = $this->organization('Enterprise Org', null);
        $unitOne = $this->unit($tenant, 'Yard One');
        $unitTwo = $this->unit($tenant, 'Yard Two');

        $admin = $this->admin($tenant);
        $member = $this->admin($tenant);

        $this->actingAs($admin)
            ->put(route('settings.users.companies', $member->id), ['company_ids' => [$unitOne->id]])
            ->assertRedirect();

        $this->actingAs($member->fresh());
        $reachable = Company::query()->pluck('id')->all();

        $this->assertContains($unitOne->id, $reachable);
        $this->assertNotContains($unitTwo->id, $reachable, 'A restricted user must not reach an unGranted operating unit.');
    }

    /**
     * Granting EVERY unit is recorded as "no restriction" rather than as
     * a full list -- otherwise adding a third unit later would silently
     * exclude everyone who was unrestricted when they were granted.
     */
    public function test_granting_every_unit_is_stored_as_unrestricted(): void
    {
        [$tenant] = $this->organization('Enterprise Org', null);
        $unitOne = $this->unit($tenant, 'Yard One');
        $unitTwo = $this->unit($tenant, 'Yard Two');

        $admin = $this->admin($tenant);
        $member = $this->admin($tenant);

        $this->actingAs($admin)->put(route('settings.users.companies', $member->id), [
            'company_ids' => [$unitOne->id, $unitTwo->id],
        ])->assertRedirect();

        $this->assertSame(0, $member->companies()->withoutGlobalScopes()->count());
        $this->assertNull($member->fresh()->authorizedCompanyIds());
    }

    /** An empty submission means "all units", never "none" -- nobody is locked out of everything by an accidental save. */
    public function test_an_empty_grant_leaves_the_user_unrestricted(): void
    {
        [$tenant] = $this->organization('Enterprise Org', null);
        $unitOne = $this->unit($tenant, 'Yard One');
        $this->unit($tenant, 'Yard Two');

        $admin = $this->admin($tenant);
        $member = $this->admin($tenant);
        $member->companies()->sync([$unitOne->id]);

        $this->actingAs($admin)->put(route('settings.users.companies', $member->id), ['company_ids' => []])
            ->assertRedirect();

        $this->assertNull($member->fresh()->authorizedCompanyIds());
    }

    /** An id from ANOTHER organization is filtered out rather than written -- Rule::exists alone reads the raw table. */
    public function test_a_grant_cannot_name_another_organizations_operating_unit(): void
    {
        [$tenantA] = $this->organization('Org A', null);
        $unitA1 = $this->unit($tenantA, 'A Yard One');
        $this->unit($tenantA, 'A Yard Two');

        [$tenantB] = $this->organization('Org B', null);
        $unitB1 = $this->unit($tenantB, 'B Yard One');

        app(CurrentTenant::class)->set($tenantA);
        $admin = $this->admin($tenantA);
        $member = $this->admin($tenantA);

        $this->actingAs($admin)->put(route('settings.users.companies', $member->id), [
            'company_ids' => [$unitA1->id, $unitB1->id],
        ])->assertRedirect();

        $this->assertSame([$unitA1->id], $member->fresh()->authorizedCompanyIds());
    }

    public function test_an_administrator_cannot_change_grants_for_another_organizations_user(): void
    {
        [$tenantA] = $this->organization('Org A', null);
        $adminA = $this->admin($tenantA);

        [$tenantB] = $this->organization('Org B', null);
        $memberB = $this->admin($tenantB);

        app(CurrentTenant::class)->set($tenantA);

        $this->actingAs($adminA)
            ->put(route('settings.users.companies', $memberB->id), ['company_ids' => []])
            ->assertNotFound();
    }

    /* ================================================================
     * 4. ORGANIZATIONAL CONTEXT IS DERIVED, NOT SELECTED
     * ================================================================ */

    public function test_organization_context_reports_the_units_the_user_may_reach(): void
    {
        [$tenant] = $this->organization('Nusantara Group', null);
        $unitOne = $this->unit($tenant, 'Yard One');
        $this->unit($tenant, 'Yard Two');

        $member = $this->admin($tenant);
        $member->companies()->sync([$unitOne->id]);

        $context = $member->fresh()->organizationContext();

        $this->assertSame('Nusantara Group', $context['organization']);
        $this->assertTrue($context['restricted']);
        $this->assertSame(['Yard One'], array_column($context['operating_units'], 'name'));
    }

    public function test_an_unrestricted_user_sees_every_operating_unit_in_the_organization(): void
    {
        [$tenant] = $this->organization('Nusantara Group', null);
        $this->unit($tenant, 'Yard One');
        $this->unit($tenant, 'Yard Two');

        $context = $this->admin($tenant)->organizationContext();

        $this->assertFalse($context['restricted']);
        $this->assertCount(2, $context['operating_units']);
    }
}
