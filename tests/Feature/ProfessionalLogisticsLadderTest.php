<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Module;
use App\Models\Package;
use App\Models\Position;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Documents\Library\DocumentLibrary;
use App\Services\EntitlementService;
use App\Services\PricingService;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v2.143.0 (ADR 092) -- the owner's ladder:
 *
 *   Starter       HSE
 *   Professional  HSE + Warehouse Logistics
 *   Business      HSE + Warehouse Logistics + People / HRD + Management
 *
 * Pins three things: the catalogue grants exactly that, prices and
 * capacities did not move, and the migration moves EXISTING Professional
 * tenants onto the new scope without touching their data, their price, a
 * Platform Admin's hand-made grant, or a tenant that has since upgraded.
 */
class ProfessionalLogisticsLadderTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2027_01_21_100000_professional_opens_logistics_not_people.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['saas.enforce_entitlement' => true, 'saas.enforce_workspace_entitlement' => true]);
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
    }

    public function test_the_catalogue_grants_the_owners_ladder(): void
    {
        $scope = fn (string $slug) => Package::where('slug', $slug)->sole()->departmentWorkspaceKeys();

        $this->assertEqualsCanonicalizing(['hse'], $scope('starter'));
        $this->assertEqualsCanonicalizing(['hse', 'logistics'], $scope('professional'));
        $this->assertEqualsCanonicalizing(['hse', 'logistics', 'hr', 'management'], $scope('business'));

        // People / HRD and Management are Business only; HSE is everywhere.
        foreach (['starter', 'professional'] as $slug) {
            $this->assertNotContains('hr', $scope($slug));
            $this->assertNotContains('management', $scope($slug));
        }
        foreach (['starter', 'professional', 'business'] as $slug) {
            $this->assertContains('hse', $scope($slug));
        }

        // Material Request is the Logistics module and travels with it.
        $this->assertContains('material_requests', Package::where('slug', 'professional')->sole()->defaultModuleKeys());
        $this->assertNotContains('material_requests', Package::where('slug', 'starter')->sole()->defaultModuleKeys());
    }

    public function test_prices_and_capacities_did_not_move(): void
    {
        $expected = [
            'starter' => [189000.0, 2268000.0, 3, 12],
            'professional' => [555000.0, 6105000.0, 10, 12],
            'business' => [1249000.0, 14988000.0, 25, 13],   // v2.144.0: 13 months, first year only
        ];

        foreach ($expected as $slug => [$monthly, $yearly, $users, $months]) {
            $p = Package::where('slug', $slug)->sole();
            $this->assertSame($monthly, (float) $p->price_monthly, "{$slug} monthly");
            $this->assertSame($yearly, (float) $p->price_yearly, "{$slug} yearly");
            $this->assertSame($users, (int) $p->max_users, "{$slug} users");
            $this->assertSame($months, $p->annualMonths(), "{$slug} annual months");
        }
    }

    public function test_the_pricing_surface_says_what_professional_now_opens(): void
    {
        $card = collect(app(PricingService::class)->publicPlans())->firstWhere('slug', 'professional');
        $labels = collect($card['workspaces'] ?? [])->map(fn ($w) => is_array($w) ? ($w['key'] ?? $w['label'] ?? '') : $w)->implode(' ');

        $this->assertStringContainsStringIgnoringCase('Logistics', $labels);
        $this->assertStringNotContainsStringIgnoringCase('HRD', $labels);
    }

    public function test_existing_professional_tenants_move_without_losing_data_or_price(): void
    {
        $pro = Package::where('slug', 'professional')->sole();
        $business = Package::where('slug', 'business')->sole();

        // An existing Professional tenant on the OLD grant (HSE + HRD),
        // plus a hand-made grant a Platform Admin added (Finance).
        $old = $this->tenant('Old Professional', $pro, ['hse', 'hr', 'finance']);
        $agreed = Subscription::where('tenant_id', $old->id)->value('agreed_price_monthly');

        // A tenant that WAS Professional and has since upgraded to Business:
        // its older Professional row must not take People / HRD away.
        $upgraded = $this->tenant('Upgraded', $pro, ['hse', 'hr', 'logistics', 'management']);
        $this->subscribe($upgraded, $business);

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $keys = fn (Tenant $t) => DB::table('tenant_workspaces')->where('tenant_id', $t->id)
            ->join('workspaces', 'workspaces.id', '=', 'tenant_workspaces.workspace_id')->pluck('workspaces.key')->sort()->values()->all();

        $this->assertSame(['finance', 'hse', 'logistics'], $keys($old), 'HR closed, Logistics opened, the hand-made grant kept.');
        $this->assertTrue(DB::table('tenant_modules')->where('tenant_id', $old->id)
            ->where('module_id', Module::where('key', 'material_requests')->value('id'))->exists());
        $this->assertSame(['hr', 'hse', 'logistics', 'management'], $keys($upgraded), 'An upgraded tenant keeps People / HRD.');

        // The subscription and its agreed price are untouched.
        $this->assertSame($agreed, Subscription::where('tenant_id', $old->id)->value('agreed_price_monthly'));
        $this->assertSame($pro->id, Subscription::where('tenant_id', $old->id)->value('package_id'));

        // And the access path agrees with the rows.
        $fresh = Tenant::find($old->id);
        $this->assertFalse(app(EntitlementService::class)->tenantCanUseWorkspace($fresh, 'hr'));
        $this->assertTrue(app(EntitlementService::class)->tenantCanUseWorkspace($fresh, 'logistics'));

        // One-way by the owner's decision: down() changes nothing.
        $migration->down();
        $this->assertSame(['finance', 'hse', 'logistics'], $keys($old));
        $this->assertSame(['hr', 'hse', 'logistics', 'management'], $keys($upgraded));
    }

    /** The Document Library asks the plan too: no export of a register whose screen the plan does not open. */
    public function test_the_document_library_follows_the_plan(): void
    {
        $visible = function (string $slug): array {
            $package = Package::where('slug', $slug)->sole();
            $tenant = $this->tenant('Lib '.$slug, $package, $package->defaultWorkspaceKeys());
            $tenant->modules()->sync(Module::whereIn('key', $package->defaultModuleKeys())->pluck('id'));
            $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
            $user = User::create(['name' => 'Admin '.$slug, 'email' => $slug.uniqid().'@yard.test', 'password' => 'secret-pass-1',
                'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true]);

            return collect(app(DocumentLibrary::class)->visibleTo($user->fresh()))->map->key()->all();
        };

        $starter = $visible('starter');
        foreach (['leave-register', 'stock-position', 'stock-movement-register', 'material-request-register', 'project-manpower'] as $key) {
            $this->assertNotContains($key, $starter, "Starter must not export {$key}.");
        }
        $this->assertContains('permit-to-work-register', $starter);
        $this->assertContains('employee-register', $starter);

        $pro = $visible('professional');
        $this->assertContains('stock-position', $pro);
        $this->assertContains('material-request-register', $pro);
        $this->assertNotContains('leave-register', $pro, 'People / HRD is Business only.');

        $business = $visible('business');
        foreach (['leave-register', 'stock-position', 'material-request-register'] as $key) {
            $this->assertContains($key, $business);
        }
    }

    /** A Module grant is a route gate: Starter cannot open Material Request by URL; Professional can. */
    public function test_material_request_route_follows_the_plan(): void
    {
        foreach (['starter' => 403, 'professional' => 200] as $slug => $status) {
            $user = $this->adminOn($slug);
            $this->actingAs($user)->get(route('material-requests.index'))->assertStatus($status);
        }
    }

    /** Global search never returns a category whose screen the plan does not open. */
    public function test_global_search_follows_the_plan(): void
    {
        $user = $this->adminOn('professional');
        $department = Department::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Produksi']);
        $position = Position::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Welder']);
        $employee = Employee::create(['employee_id' => 'EMP-ZZQ', 'full_name' => 'Zulkarnain Cari', 'company_id' => $user->company_id,
            'department_id' => $department->id, 'position_id' => $position->id, 'status' => 'active', 'join_date' => '2026-01-15']);
        LeaveRequest::withoutGlobalScopes()->create(['company_id' => $user->company_id, 'employee_id' => $employee->id, 'leave_number' => 'LV-ZZQ-001',
            'leave_type' => 'annual', 'start_date' => now()->toDateString(), 'end_date' => now()->toDateString(), 'days' => 1, 'status' => 'pending', 'requested_by' => $user->id]);
        $this->assertSame(1, LeaveRequest::withoutGlobalScopes()->where('leave_number', 'LV-ZZQ-001')->count());

        $this->actingAs($user)->getJson(route('search', ['q' => 'ZZQ']))->assertOk()->assertJsonCount(0, 'leave_requests');
    }

    private function adminOn(string $slug): User
    {
        $package = Package::where('slug', $slug)->sole();
        $tenant = $this->tenant('Gate '.$slug, $package, $package->defaultWorkspaceKeys());
        $tenant->modules()->sync(Module::whereIn('key', $package->defaultModuleKeys())->pluck('id'));
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        return User::create(['name' => 'Admin '.$slug, 'email' => $slug.uniqid().'@gate.test', 'password' => 'secret-pass-1',
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true])->fresh();
    }

    private function tenant(string $name, Package $package, array $grants): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => $name, 'code' => 'C'.random_int(100, 999), 'tenant_id' => $tenant->id, 'is_active' => true]);
        $this->subscribe($tenant, $package);
        $tenant->workspaces()->sync(Workspace::whereIn('key', $grants)->pluck('id'));

        return $tenant;
    }

    private function subscribe(Tenant $tenant, Package $package): void
    {
        Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(20),
            'agreed_price_monthly' => $package->price_monthly,
            'agreed_price_yearly' => $package->price_yearly,
            'agreed_currency' => $package->currency,
        ]);
    }
}
