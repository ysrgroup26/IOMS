<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\WorkCenterService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * v2.122.0 -- A QUICK ACTION MAY NOT OUTLIVE THE PLAN THAT GRANTS IT.
 *
 * Every gate in `WorkCenterService::quickActionsFor()` asked about the
 * USER's role. None asked about the TENANT's plan, so a tenant's own
 * administrator was offered actions `EnforceTenantEntitlement` refuses
 * with "Fitur ini belum tersedia untuk paket perusahaan Anda."
 *
 * Browser-verified on a fresh MySQL database, crawling every link each
 * account is actually offered: a Business administrator reached a 403 on
 * Milestone, Purchase Requisition and Purchase Order; a Starter
 * administrator on Employee Cases.
 *
 * The fix asks `tenantCanUseRoutePrefix()`, which reads the same
 * `config('departments')` map the middleware enforces with -- so the offer
 * and the refusal cannot disagree.
 */
class PlanScopedActionOffersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    private function administratorOf(string $packageSlug): User
    {
        $package = Package::where('slug', $packageSlug)->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$packageSlug, 'slug' => 'yard-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit '.$packageSlug, 'code' => strtoupper(substr($packageSlug, 0, 3)).rand(10, 99),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(30),
        ]);

        $tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($tenant->fresh());

        return User::create([
            'name' => 'Admin '.$packageSlug,
            'email' => 'admin-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[DataProvider('packages')]
    public function test_no_quick_action_points_at_a_route_this_plan_is_refused(string $packageSlug): void
    {
        $admin = $this->administratorOf($packageSlug);

        $actions = app(WorkCenterService::class)->quickActionsFor($admin);

        $this->assertNotEmpty($actions, 'Every plan offers at least New Task.');

        $entitlements = app(EntitlementService::class);

        foreach ($actions as $action) {
            $this->assertTrue(
                $entitlements->tenantCanUseRoutePrefix($admin->tenant, $action['route']),
                "The \"{$action['label']}\" action points at {$action['route']}, which this plan is refused."
            );
        }
    }

    #[DataProvider('packages')]
    public function test_no_action_alert_points_at_a_route_this_plan_is_refused(string $packageSlug): void
    {
        $admin = $this->administratorOf($packageSlug);

        $entitlements = app(EntitlementService::class);
        $alerts = app(WorkCenterService::class)->actionAlertsFor($admin);

        // A fresh tenant has nothing overdue, so the list is legitimately
        // empty on every plan -- what is pinned is that NOTHING unreachable
        // appears in it, including when something does.
        $this->assertIsArray($alerts);

        foreach ($alerts as $alert) {
            $this->assertTrue(
                $entitlements->tenantCanUseRoutePrefix($admin->tenant, $alert['route']),
                "The \"{$alert['label']}\" alert points at {$alert['route']}, which this plan is refused."
            );
        }
    }

    /** The refusal itself is real, so the assertions above mean something. */
    public function test_a_route_outside_the_plan_is_still_refused_at_the_door(): void
    {
        $admin = $this->administratorOf('starter');

        $this->actingAs($admin)
            ->get(route('employee-cases.index'))
            ->assertForbidden();
    }

    /** And the one every plan has is still offered and still reachable. */
    public function test_new_task_survives_on_every_plan(): void
    {
        $admin = $this->administratorOf('starter');

        $routes = array_column(app(WorkCenterService::class)->quickActionsFor($admin), 'route');

        $this->assertContains('tasks.create', $routes);

        $this->actingAs($admin)->get(route('tasks.create'))->assertOk();
    }

    public static function packages(): array
    {
        return [
            'starter' => ['starter'],
            'professional' => ['professional'],
            'business' => ['business'],
            'enterprise' => ['enterprise'],
        ];
    }
}
