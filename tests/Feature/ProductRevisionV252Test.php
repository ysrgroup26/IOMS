<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\HandoverRecord;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\RegulationRegister;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NumberGeneratorService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v2.52.0 -- the consolidated product revision.
 *
 * Each group below pins one of the distinctions this release exists to
 * make correct, because every one of them had previously been collapsed
 * into something simpler and wrong.
 */
class ProductRevisionV252Test extends TestCase
{
    use RefreshDatabase;

    private function tenantWithCompany(string $name = 'ACME Shipyard'): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => str($name)->slug()->value()]);
        $company = Company::withoutGlobalScopes()->create(['name' => $name, 'tenant_id' => $tenant->id, 'is_active' => true]);
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
     * 1. CAPACITY MODEL: TOTAL USERS. PTW Access is not a sold seat.
     * ================================================================ */

    /**
     * The SEEDER and the MIGRATION must produce the same catalog.
     *
     * v2.60.0: the figures themselves moved to
     * Tests\Support\ApprovedCatalogue and PricingConsistencyTest owns
     * asserting them. What is left here is the invariant this test was
     * really about -- a fresh install (seeder) and an upgraded deployment
     * (migration) must not end up on different prices, which is the
     * v2.51.0 defect that started all of this.
     */
    public function test_the_seeder_and_the_migration_agree_on_the_catalog(): void
    {
        $fromMigration = Package::orderBy('slug')->get()
            ->mapWithKeys(fn (Package $p) => [$p->slug => [
                (float) $p->price_monthly, (float) $p->price_yearly, $p->max_users, $p->max_companies,
            ]])->all();

        $this->seed(\Database\Seeders\PackageSeeder::class);

        $fromSeeder = Package::orderBy('slug')->get()
            ->mapWithKeys(fn (Package $p) => [$p->slug => [
                (float) $p->price_monthly, (float) $p->price_yearly, $p->max_users, $p->max_companies,
            ]])->all();

        $this->assertSame(
            $fromMigration,
            $fromSeeder,
            'A fresh install and an upgraded deployment would be on different prices.'
        );

        foreach (\Tests\Support\ApprovedCatalogue::PLANS as $slug => [$monthly, $yearly, $users, $units]) {
            $this->assertSame([$monthly, $yearly, $users, $units], $fromSeeder[$slug] ?? null, "{$slug} drifted.");
            $this->assertFalse((bool) Package::where('slug', $slug)->value('is_custom'), "{$slug} is a standardized plan");
        }
    }

    /**
     * v2.53.0 -- PTW Access is NOT a purchasable capacity.
     *
     * It had been a second seat pool alongside `max_users`, so every plan
     * card read as two numbers a buyer then had to reconcile. Capacity is
     * one number now, and no plan may carry a PTW ceiling.
     */
    public function test_no_plan_sells_ptw_access_as_a_capacity(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);

        foreach (Package::all() as $package) {
            $this->assertNull(
                $package->max_ptw_users,
                "{$package->slug}: PTW Access is an internal permission, not a sold seat allowance."
            );
        }

        // The entitlement layer agrees: granting PTW Access is never refused
        // for want of an allowance.
        $entitlements = app(\App\Services\EntitlementService::class);
        $this->assertNull($entitlements->ptwUserQuota(null));
        $this->assertTrue($entitlements->canEnablePtwAccess(null));
    }

    /**
     * Operating-unit capacity rises with the tier, and only Enterprise is
     * unlimited.
     *
     * v2.60.0 replaces "only Enterprise is multi-company". Under the
     * four-tier model Professional runs two Operating Units and Business
     * four -- capacity is now part of the ladder rather than a single
     * Enterprise-only switch. What has NOT changed is the two ends:
     * Starter is one unit, and NULL (unlimited) belongs to Enterprise
     * alone.
     */
    public function test_operating_unit_capacity_climbs_and_only_enterprise_is_unlimited(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);

        $this->assertSame(1, Package::where('slug', 'starter')->value('max_companies'));

        $previous = 0;

        foreach (\Tests\Support\ApprovedCatalogue::slugs() as $slug) {
            $units = Package::where('slug', $slug)->value('max_companies');

            if ($slug === 'enterprise') {
                $this->assertNull($units, 'Enterprise is the only unlimited tier.');

                continue;
            }

            $this->assertNotNull($units, "{$slug} must have a stated Operating Unit ceiling.");
            $this->assertGreaterThan($previous, $units, "{$slug} must allow more Operating Units than the tier below it.");
            $previous = $units;
        }
    }

    /** The capacity migration alone must produce the catalog on an already-seeded deployment. */
    public function test_the_capacity_migration_corrects_stale_and_null_entitlements(): void
    {
        // A deployment carrying the previous release's values, including the
        // NULL PTW allowance that silently meant "unlimited".
        //
        // v2.60.0: written OVER the migrated Starter row rather than
        // inserted beside it -- the four-tier migration has already run on
        // this database, and the point of the test is to hand the v2.52.0
        // migration the stale state it was written to correct.
        Package::updateOrCreate(['slug' => 'starter'], [
            'name' => 'Starter', 'price_monthly' => 499000, 'price_yearly' => 4990000,
            'currency' => 'IDR', 'max_users' => 15, 'max_ptw_users' => null, 'max_companies' => 1,
            'is_active' => true, 'is_public' => true, 'is_custom' => false,
        ]);
        // And a hand-made plan that violates the subset rule outright.
        Package::create([
            'name' => 'Custom', 'slug' => 'legacy-custom', 'price_monthly' => 100, 'price_yearly' => 1000,
            'currency' => 'IDR', 'max_users' => 5, 'max_ptw_users' => 50, 'max_companies' => 1,
            'is_active' => true, 'is_public' => false, 'is_custom' => false,
        ]);

        // Both pricing migrations, in deploy order: v2.52.0 set the
        // catalog, v2.53.0 retired the PTW seat allowance on top of it.
        (require database_path('migrations/2026_09_18_100230_standardize_plan_capacity_and_launch_pricing.php'))->up();
        (require database_path('migrations/2026_09_19_100240_retire_ptw_seat_entitlement.php'))->up();

        $starter = Package::where('slug', 'starter')->firstOrFail();
        $this->assertEquals(299000, (float) $starter->price_monthly);
        $this->assertSame(10, $starter->max_users);
        $this->assertNull($starter->max_ptw_users, 'PTW Access is no longer a sold capacity.');

        $custom = Package::where('slug', 'legacy-custom')->firstOrFail();
        $this->assertNull($custom->max_ptw_users, 'A custom plan loses its PTW ceiling too -- it is not sold any more.');
        $this->assertEquals(100, (float) $custom->price_monthly, 'A custom plan keeps its own price.');
    }

    /* ================================================================
     * 2. MY WORK is a WORKSPACE; PTW ACCESS is a PERMISSION
     * ================================================================ */

    public function test_a_field_account_lands_on_my_work_and_an_office_account_does_not(): void
    {
        [$tenant] = $this->tenantWithCompany();

        $field = $this->user($tenant, ['email' => 'foreman@acme.test', 'is_field_user' => true]);
        $office = $this->user($tenant, ['email' => 'manager@acme.test', 'is_field_user' => false]);

        $this->assertSame('my-work', $field->landingRouteName());
        $this->assertSame('dashboard', $office->landingRouteName());

        $this->post(route('login'), ['email' => 'foreman@acme.test', 'password' => 'secret-pass-1'])
            ->assertRedirect(route('my-work'));

        $this->post(route('logout'));

        $this->post(route('login'), ['email' => 'manager@acme.test', 'password' => 'secret-pass-1'])
            ->assertRedirect(route('dashboard'));
    }

    /**
     * The two are independent in BOTH directions. A field worker without
     * PTW Access, and an HSE officer with PTW authority who is not a field
     * account, are both normal configurations.
     */
    public function test_field_workspace_and_ptw_access_are_independent(): void
    {
        [$tenant] = $this->tenantWithCompany();

        $fieldWorker = $this->user($tenant, ['role' => 'manager', 'is_field_user' => true, 'ptw_access' => false]);
        $foreman = $this->user($tenant, ['role' => 'manager', 'is_field_user' => true, 'ptw_access' => true]);
        $officeHse = $this->user($tenant, ['role' => 'hse', 'is_field_user' => false]);

        $this->assertTrue($fieldWorker->isFieldUser());
        $this->assertFalse($fieldWorker->canCreatePtw(), 'My Work must not imply PTW Access.');

        $this->assertTrue($foreman->isFieldUser());
        $this->assertTrue($foreman->canCreatePtw());

        $this->assertFalse($officeHse->isFieldUser());
        $this->assertTrue($officeHse->canCreatePtw(), 'PTW authority does not require being a field account.');
    }

    /** Granting the field workspace must not consume a PTW seat or grant PTW authority. */
    public function test_marking_a_user_as_field_grants_no_ptw_capability(): void
    {
        [$tenant] = $this->tenantWithCompany();
        $admin = $this->user($tenant, ['email' => 'admin@acme.test']);
        $worker = $this->user($tenant, ['role' => 'manager', 'email' => 'worker@acme.test']);

        $this->actingAs($admin)
            ->put(route('settings.users.field-access', $worker->id), ['is_field_user' => true])
            ->assertRedirect();

        $worker->refresh();
        $this->assertTrue($worker->is_field_user);
        $this->assertFalse((bool) $worker->ptw_access, 'The field workspace flag must never enable PTW Access.');
        $this->assertFalse($worker->canCreatePtw());
    }

    /** A user without PTW Access cannot create a permit, whatever the UI shows. */
    public function test_a_field_worker_without_ptw_access_cannot_create_a_permit(): void
    {
        [$tenant] = $this->tenantWithCompany();
        $worker = $this->user($tenant, ['role' => 'manager', 'is_field_user' => true, 'ptw_access' => false]);

        $this->actingAs($worker)->get(route('permits-to-work.create'))->assertForbidden();
    }

    /* ================================================================
     * 3. PROJECT IDENTITY vs WORK LOCATION
     * ================================================================ */

    public function test_a_permit_keeps_its_work_identity_without_a_formal_project(): void
    {
        [, $company] = $this->tenantWithCompany();

        $permit = new PermitToWork([
            'company_id' => $company->id,
            'project_id' => null,
            'work_reference' => 'Docking MV Sinar Mas',
            'location' => 'Graving Dock 2',
        ]);

        // The permit knows what the job is even though Management has not
        // created a Project Master row -- it must never read "No Project"
        // for work that plainly has a name.
        $this->assertSame('Docking MV Sinar Mas', $permit->workIdentity());
        $this->assertSame('Graving Dock 2', $permit->location, 'Work location stays a separate fact.');
    }

    public function test_a_formal_project_takes_precedence_over_the_free_text_name(): void
    {
        [, $company] = $this->tenantWithCompany();

        $project = Project::create(['company_id' => $company->id, 'name' => 'Newbuild Hull 118']);

        $permit = new PermitToWork(['company_id' => $company->id, 'project_id' => $project->id, 'work_reference' => 'ignored']);
        $permit->setRelation('project', $project);

        $this->assertSame('Newbuild Hull 118', $permit->workIdentity());
    }

    /* ================================================================
     * 4. BAST is not a Goods Receipt
     * ================================================================ */

    public function test_bast_and_goods_receipt_are_different_documents(): void
    {
        // Different numbering series, because they are different documents
        // in different registers -- not two names for one thing.
        $this->assertArrayHasKey('handover_record', NumberGeneratorService::DEFAULTS);
        $this->assertSame('BAST', NumberGeneratorService::DEFAULTS['handover_record']['prefix']);
        $this->assertSame('GR', NumberGeneratorService::DEFAULTS['goods_receipt']['prefix']);

        // Different templates.
        $this->assertTrue(view()->exists('pdf.handover-record'));
        $this->assertTrue(view()->exists('pdf.goods-receipt'));

        // And the receiving document no longer calls itself a Berita Acara.
        $goodsReceipt = file_get_contents(resource_path('views/pdf/goods-receipt.blade.php'));
        $this->assertStringNotContainsString("'docTitle' => 'Goods Receipt / Berita Acara", $goodsReceipt);
        $this->assertStringContainsString('Bukti Penerimaan Barang', $goodsReceipt);
    }

    public function test_a_bast_is_numbered_tenant_safely_and_scoped_to_its_tenant(): void
    {
        [$tenantA, $companyA] = $this->tenantWithCompany('Alpha Yard');
        $admin = $this->user($tenantA, ['email' => 'a-admin@acme.test']);

        $this->actingAs($admin)->post(route('handover-records.store'), [
            'company_id' => $companyA->id,
            'handover_type' => HandoverRecord::TYPE_WORK_COMPLETION,
            'title' => 'Penyelesaian Overhaul Crane #4',
            'handover_date' => now()->toDateString(),
            'first_party_name' => 'Budi Santoso',
            'second_party_name' => 'Andi Wijaya',
            'status' => HandoverRecord::STATUS_DRAFT,
        ])->assertRedirect();

        $record = HandoverRecord::firstOrFail();
        $this->assertStringStartsWith('BAST-', $record->bast_number);
        $this->assertSame($companyA->id, $record->company_id);

        // A second tenant cannot see or open it.
        [$tenantB] = $this->tenantWithCompany('Beta Fabrication');
        $outsider = $this->user($tenantB, ['email' => 'b-admin@acme.test']);

        $this->actingAs($outsider)->get(route('handover-records.show', $record->id))->assertNotFound();
    }

    /** An accepted BAST is evidence -- it cannot simply be deleted. */
    public function test_an_accepted_bast_cannot_be_deleted(): void
    {
        [$tenant, $company] = $this->tenantWithCompany();
        $admin = $this->user($tenant, ['email' => 'admin2@acme.test']);

        $record = HandoverRecord::create([
            'bast_number' => 'BAST-2026-00001', 'company_id' => $company->id,
            'handover_type' => HandoverRecord::TYPE_WORK_COMPLETION, 'title' => 'Serah terima',
            'handover_date' => now()->toDateString(),
            'first_party_name' => 'A', 'second_party_name' => 'B',
            'status' => HandoverRecord::STATUS_ACCEPTED,
        ]);

        $this->actingAs($admin)->delete(route('handover-records.destroy', $record->id))->assertStatus(422);
        $this->assertDatabaseHas('handover_records', ['id' => $record->id]);
    }

    /* ================================================================
     * 5. Regulations & Standards Register
     * ================================================================ */

    public function test_a_regulation_is_numbered_and_its_attachment_stays_private(): void
    {
        Storage::fake('private');

        [$tenant, $company] = $this->tenantWithCompany();
        $hse = $this->user($tenant, ['role' => 'hse', 'email' => 'hse@acme.test']);

        $this->actingAs($hse)->post(route('hse-regulations.store'), [
            'company_id' => $company->id,
            'category' => 'Occupational Safety',
            'document_type' => 'Peraturan Menteri',
            'regulation_number' => '5',
            'year' => 2018,
            'title' => 'Keselamatan dan Kesehatan Kerja Lingkungan Kerja',
            'issuing_authority' => 'Kementerian Ketenagakerjaan',
            'status' => RegulationRegister::STATUS_ACTIVE,
            'document' => UploadedFile::fake()->create('permenaker-5-2018.pdf', 64, 'application/pdf'),
        ])->assertRedirect();

        $entry = RegulationRegister::firstOrFail();

        $this->assertStringStartsWith('REG-', $entry->register_number);
        $this->assertSame('Peraturan Menteri No. 5 Tahun 2018', $entry->citation());

        // The file is on the PRIVATE disk, never the public one.
        $this->assertNotNull($entry->document_path);
        Storage::disk('private')->assertExists($entry->document_path);

        // And the path itself never reaches a serialized payload.
        $this->assertArrayNotHasKey('document_path', $entry->toArray());
    }

    public function test_one_tenant_cannot_read_another_tenants_regulation_document(): void
    {
        Storage::fake('private');

        [$tenantA, $companyA] = $this->tenantWithCompany('Alpha Yard');
        $entry = RegulationRegister::create([
            'company_id' => $companyA->id, 'category' => 'Occupational Safety',
            'document_type' => 'Undang-Undang', 'title' => 'Keselamatan Kerja',
            'status' => RegulationRegister::STATUS_ACTIVE,
            'document_path' => 'regulations/secret.pdf', 'document_name' => 'secret.pdf',
        ]);
        Storage::disk('private')->put('regulations/secret.pdf', 'confidential');

        [$tenantB] = $this->tenantWithCompany('Beta Fabrication');
        $outsider = $this->user($tenantB, ['email' => 'outsider@acme.test']);

        $this->actingAs($outsider)
            ->get(route('secure-documents.show', ['type' => 'regulation-document', 'id' => $entry->id]))
            ->assertNotFound();
    }

    /** A review date in the past is what makes the register a living document. */
    public function test_an_overdue_review_is_flagged(): void
    {
        [, $company] = $this->tenantWithCompany();

        $due = new RegulationRegister([
            'company_id' => $company->id, 'status' => RegulationRegister::STATUS_ACTIVE,
            'review_date' => now()->subMonth(),
        ]);
        $notDue = new RegulationRegister([
            'company_id' => $company->id, 'status' => RegulationRegister::STATUS_ACTIVE,
            'review_date' => now()->addYear(),
        ]);
        $superseded = new RegulationRegister([
            'company_id' => $company->id, 'status' => RegulationRegister::STATUS_SUPERSEDED,
            'review_date' => now()->subYear(),
        ]);

        $this->assertTrue($due->isDueForReview());
        $this->assertFalse($notDue->isDueForReview());
        $this->assertFalse($superseded->isDueForReview(), 'A superseded entry is not awaiting review.');
    }

    /* ================================================================
     * 6. Department naming and the IOMS mailboxes
     * ================================================================ */

    public function test_departments_use_their_full_english_names(): void
    {
        $registry = file_get_contents(resource_path('js/lib/workspaces.js'));

        $this->assertStringContainsString("label: 'Health, Safety & Environment'", $registry);
        $this->assertStringContainsString("label: 'Human Resources'", $registry);
        $this->assertStringNotContainsString("label: 'HSE',", $registry);
        $this->assertStringNotContainsString("label: 'HR',", $registry);
    }

    public function test_the_four_ioms_mailboxes_are_centrally_configured(): void
    {
        foreach (['support', 'billing', 'noreply', 'hello'] as $box) {
            $address = config("ioms.emails.{$box}");
            $this->assertNotEmpty($address, "ioms.emails.{$box} must be configured.");
            $this->assertStringContainsString('@', $address);
        }

        // Four distinct inboxes -- a prospect writing to sales and a
        // customer chasing an invoice must not land in the same place.
        $this->assertCount(4, array_unique(config('ioms.emails')));
    }
}
