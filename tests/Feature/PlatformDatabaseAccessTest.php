<?php

namespace Tests\Feature;

use App\Http\Requests\RestoreDatabaseRequest;
use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DatabaseBackupService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SEC-010 — A CUSTOMER MAY NOT HOLD A COPY OF EVERY OTHER CUSTOMER.
 *
 * `/settings/backup` and `/settings/restore` sat in the tenant-side
 * `role:super_admin` group. `super_admin` is a CUSTOMER role — one
 * organization's own administrator — and the handlers behind those routes
 * ran `mysqldump` and `mysql <` against the whole connection, which has no
 * notion of tenancy at all.
 *
 * So the feature worked exactly as written, in the wrong place: any
 * customer's administrator could download a complete dump of every other
 * customer's employees, incidents, permits and invoices, and could
 * overwrite the database for every tenant on the installation.
 *
 * The capability is legitimate — somebody has to be able to back up the
 * installation — so it moved to the platform console behind
 * `role:platform_admin` rather than being deleted. This file asserts both
 * halves: that no tenant role can reach it, and that the operator still
 * can.
 *
 * NOTHING HERE SHELLS OUT. `DatabaseBackupService` is swapped for a
 * recording fake, so a test can prove that an unauthorized request never
 * reached the dump at all — which is a stronger claim than "it returned
 * 403".
 */
class PlatformDatabaseAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    /** Records whether the dump/restore was ever actually invoked. */
    private object $backups;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
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

        $this->backups = new class extends DatabaseBackupService
        {
            public bool $dumped = false;

            public bool $restored = false;

            public function isSupported(): bool
            {
                return true;
            }

            public function unsupportedReason(): ?string
            {
                return null;
            }

            public function dump(string $path): array
            {
                $this->dumped = true;
                file_put_contents($path, "-- fake dump\n");

                return ['ok' => true, 'output' => ''];
            }

            public function restore(string $path): array
            {
                $this->restored = true;

                return ['ok' => true, 'output' => ''];
            }
        };

        $this->app->instance(DatabaseBackupService::class, $this->backups);
    }

    private function tenantUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    private function operator(): User
    {
        return User::create([
            'name' => 'Operator', 'email' => 'ops-'.uniqid().'@iomsuite.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    private function dumpFile(string $name = 'ioms-backup.sql', string $body = "-- MySQL dump 10.13\nCREATE TABLE a (id int);\n"): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dump').'.sql';
        file_put_contents($path, $body);

        return new UploadedFile($path, $name, 'application/sql', null, true);
    }

    /** @param array<string, mixed> $overrides */
    private function restorePayload(array $overrides = []): array
    {
        return array_merge([
            'backup_file' => $this->dumpFile(),
            'confirmation' => RestoreDatabaseRequest::CONFIRMATION,
            'database' => config('database.connections.mysql.database'),
        ], $overrides);
    }

    /* ------------------------------------------------------------------ */
    /* 1. The route a customer used to reach is gone */
    /* ------------------------------------------------------------------ */

    public function test_the_old_tenant_side_routes_no_longer_exist(): void
    {
        $this->assertFalse(app('router')->has('settings.backup'), 'A tenant-side backup route must not exist.');
        $this->assertFalse(app('router')->has('settings.restore'), 'A tenant-side restore route must not exist.');
    }

    public function test_the_old_tenant_side_urls_are_not_served(): void
    {
        $admin = $this->tenantUser(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get('/settings/backup')->assertNotFound();
        $this->actingAs($admin)->post('/settings/restore', [])->assertNotFound();

        $this->assertFalse($this->backups->dumped);
        $this->assertFalse($this->backups->restored);
    }

    public function test_the_settings_page_no_longer_offers_backup_or_restore(): void
    {
        $admin = $this->tenantUser(User::ROLE_SUPER_ADMIN);

        $response = $this->actingAs($admin)->get(route('settings.index'));
        $response->assertOk();

        $this->assertStringNotContainsString(
            'BackupTab',
            file_get_contents(resource_path('js/Pages/Settings/Index.jsx')),
            'The customer Settings page must not render a database backup tab.'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 2. No tenant role may reach the platform surface */
    /* ------------------------------------------------------------------ */

    /**
     * The headline assertion. A tenant `super_admin` is the account that
     * previously held this power.
     */
    public function test_a_tenant_super_admin_cannot_reach_platform_backup(): void
    {
        $admin = $this->tenantUser(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('platform.database'))->assertForbidden();
        $this->actingAs($admin)->get(route('platform.database.backup'))->assertForbidden();
        $this->actingAs($admin)->post(route('platform.database.restore'), $this->restorePayload())->assertForbidden();

        $this->assertFalse($this->backups->dumped, 'No dump may be produced for a tenant account.');
        $this->assertFalse($this->backups->restored, 'No restore may run for a tenant account.');
    }

    public function test_no_other_tenant_role_can_reach_it_either(): void
    {
        foreach ([User::ROLE_HSE, User::ROLE_HRD, User::ROLE_MANAGER, User::ROLE_WAREHOUSE] as $role) {
            $user = $this->tenantUser($role);

            $this->actingAs($user)->get(route('platform.database'))->assertForbidden();
            $this->actingAs($user)->get(route('platform.database.backup'))->assertForbidden();
            $this->actingAs($user)->post(route('platform.database.restore'), $this->restorePayload())->assertForbidden();
        }

        $this->assertFalse($this->backups->dumped);
        $this->assertFalse($this->backups->restored);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('platform.database'))->assertRedirect(route('login'));
        $this->get(route('platform.database.backup'))->assertRedirect(route('login'));
        $this->post(route('platform.database.restore'), $this->restorePayload())->assertRedirect(route('login'));

        $this->assertFalse($this->backups->dumped);
        $this->assertFalse($this->backups->restored);
    }

    /**
     * A second tenant's administrator is no different from the first's —
     * stated separately because "cross-tenant" is the shape of the
     * original exposure.
     */
    public function test_another_tenants_administrator_cannot_reach_it(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Pesaing', 'slug' => 'p-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Lain', 'code' => 'YL', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);

        $intruder = User::create([
            'name' => 'Intruder', 'email' => 'x-'.uniqid().'@p.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id, 'is_active' => true,
        ]);

        $this->actingAs($intruder)->get(route('platform.database.backup'))->assertForbidden();

        $this->assertFalse($this->backups->dumped);
    }

    /* ------------------------------------------------------------------ */
    /* 3. The operator keeps the capability */
    /* ------------------------------------------------------------------ */

    public function test_a_platform_operator_sees_the_maintenance_page(): void
    {
        $response = $this->actingAs($this->operator())->get(route('platform.database'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Platform/Database'));
    }

    public function test_a_platform_operator_can_download_a_backup(): void
    {
        $response = $this->actingAs($this->operator())->get(route('platform.database.backup'));

        $response->assertOk();
        $this->assertTrue($this->backups->dumped);
        $this->assertDatabaseHas('activity_logs', ['action' => 'exported']);
    }

    public function test_a_platform_operator_can_restore_with_every_confirmation_given(): void
    {
        $this->actingAs($this->operator())
            ->post(route('platform.database.restore'), $this->restorePayload())
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->backups->restored);
        $this->assertDatabaseHas('activity_logs', ['action' => 'restored']);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Destructive-operation safeguards */
    /* ------------------------------------------------------------------ */

    public function test_a_restore_without_the_typed_word_does_not_run(): void
    {
        $this->actingAs($this->operator())
            ->post(route('platform.database.restore'), $this->restorePayload(['confirmation' => '']))
            ->assertSessionHasErrors('confirmation');

        $this->assertFalse($this->backups->restored);
    }

    public function test_a_restore_with_the_wrong_word_does_not_run(): void
    {
        $this->actingAs($this->operator())
            ->post(route('platform.database.restore'), $this->restorePayload(['confirmation' => 'ya']))
            ->assertSessionHasErrors('confirmation');

        $this->assertFalse($this->backups->restored);
    }

    /** Naming the wrong database is how a production restore happens by accident. */
    public function test_a_restore_naming_a_different_database_does_not_run(): void
    {
        $this->actingAs($this->operator())
            ->post(route('platform.database.restore'), $this->restorePayload(['database' => 'some_other_db']))
            ->assertSessionHasErrors('database');

        $this->assertFalse($this->backups->restored);
    }

    public function test_a_restore_with_no_file_does_not_run(): void
    {
        $this->actingAs($this->operator())
            ->post(route('platform.database.restore'), $this->restorePayload(['backup_file' => null]))
            ->assertSessionHasErrors('backup_file');

        $this->assertFalse($this->backups->restored);
    }

    /**
     * An archive or an executable renamed `.sql`. The extension is a
     * claim; the first bytes are evidence.
     */
    public function test_a_file_that_is_not_a_sql_dump_does_not_run(): void
    {
        $notADump = $this->dumpFile('payload.sql', "PK\x03\x04binary rubbish");

        $this->actingAs($this->operator())
            ->post(route('platform.database.restore'), $this->restorePayload(['backup_file' => $notADump]))
            ->assertSessionHasErrors('backup_file');

        $this->assertFalse($this->backups->restored);
    }

    public function test_a_wrong_extension_is_refused(): void
    {
        $exe = UploadedFile::fake()->create('payload.exe', 8, 'application/x-msdownload');

        $this->actingAs($this->operator())
            ->post(route('platform.database.restore'), $this->restorePayload(['backup_file' => $exe]))
            ->assertSessionHasErrors('backup_file');

        $this->assertFalse($this->backups->restored);
    }

    /**
     * The request's own authorize() refuses a tenant account even if the
     * route group were ever edited wrongly — which has happened in this
     * codebase before, to a whole module.
     */
    public function test_the_request_itself_refuses_a_tenant_account(): void
    {
        $request = RestoreDatabaseRequest::create('/platform/database/restore', 'POST');
        $request->setUserResolver(fn () => $this->tenantUser(User::ROLE_SUPER_ADMIN));

        $this->assertFalse($request->authorize());

        $operatorRequest = RestoreDatabaseRequest::create('/platform/database/restore', 'POST');
        $operatorRequest->setUserResolver(fn () => $this->operator());

        $this->assertTrue($operatorRequest->authorize());
    }
}
