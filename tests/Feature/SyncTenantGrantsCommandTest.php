<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.124.0 -- `tenants:sync-grants` RUNS.
 *
 * The command reads `$tenant->subscription->package` on tenants it loaded
 * without the relation, and `AppServiceProvider` calls
 * `Model::preventLazyLoading()` outside production -- so it died on its
 * first tenant with a LazyLoadingViolationException. An operator who
 * tried it locally or on staging could not run it at all. Found by
 * running it; pinned by running it.
 */
class SyncTenantGrantsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function tenantMissingGrants(): Tenant
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'yard-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YC'.rand(100, 999),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(30),
        ]);

        // One workspace short of the package baseline, which is what the
        // command exists to top up.
        $tenant->workspaces()->sync(Workspace::where('key', 'hse')->pluck('id'));

        return $tenant;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    public function test_the_command_completes_and_tops_up_the_baseline(): void
    {
        $tenant = $this->tenantMissingGrants();
        $before = $tenant->workspaces()->count();

        $this->artisan('tenants:sync-grants')->assertSuccessful();

        $this->assertGreaterThan(
            $before,
            $tenant->fresh()->workspaces()->count(),
            'The command reported success without granting anything.',
        );
    }

    /** A dry run reports and writes nothing -- the operator's safe first step. */
    public function test_a_dry_run_changes_nothing(): void
    {
        $tenant = $this->tenantMissingGrants();
        $before = $tenant->workspaces()->count();

        $this->artisan('tenants:sync-grants', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $tenant->fresh()->workspaces()->count());
    }

    public function test_it_runs_for_a_single_tenant_too(): void
    {
        $tenant = $this->tenantMissingGrants();

        $this->artisan('tenants:sync-grants', ['tenant' => $tenant->id])->assertSuccessful();

        $this->assertGreaterThan(1, $tenant->fresh()->workspaces()->count());
    }

    /**
     * The violation itself: lazy loading is prevented in this environment,
     * so if the command ever stops eager-loading the relation again, the
     * tests above fail here rather than in somebody's terminal.
     */
    public function test_lazy_loading_is_prevented_in_this_environment(): void
    {
        $this->assertTrue(
            \Illuminate\Database\Eloquent\Model::preventsLazyLoading(),
            'This test is only meaningful while lazy loading is prevented.',
        );
    }
}
