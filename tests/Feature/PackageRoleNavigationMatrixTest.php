<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v2.107.0 -- EVERY NAVIGATION ITEM, EVERY PLAN, EVERY ROLE.
 *
 * IOMS has been bitten four times by the same defect in different places:
 * the interface offering a door the server has already closed (v2.98.0
 * Admin Space, v2.103.0 My Work) or hiding one it holds open (v2.103.0 PTW
 * Access). Each was found by hand, after a customer noticed.
 *
 * This is the sweep that would have found all four. It reads the real
 * navigation registry, applies the SAME three gates the sidebar applies --
 * `adminOnly` against isAdmin, `tenantAdminOnly` against isTenantAdmin,
 * `moduleKey` against the enabled modules -- plus the workspace access the
 * server itself computes, and then asks the server for every item it
 * concludes a customer would be shown.
 *
 * THE RULE, in one line: an item the navigation offers must not answer 403.
 *
 * It is deliberately one-directional. The opposite claim -- that everything
 * hidden is also refused -- is what `AccessControlBoundaryTest` already
 * asserts by direct URL, and is the stronger of the two because hiding is
 * never the boundary.
 */
class PackageRoleNavigationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every item in the navigation registry, as
     * [href => [workspace, moduleKey, adminOnly, tenantAdminOnly]].
     *
     * Parsed from the registry rather than mirrored, because a mirrored
     * copy is one more thing that can drift from the thing it describes.
     *
     * @return list<array{href: string, workspace: ?string, moduleKey: ?string, adminOnly: bool, tenantAdminOnly: bool}>
     */
    private function navigationItems(): array
    {
        $source = file_get_contents(resource_path('js/lib/workspaces.js'));
        $lines = preg_split('/\R/', $source);

        $items = [];
        $workspace = null;

        foreach ($lines as $line) {
            // A workspace header: `key: 'hse',`
            if (preg_match("/^\s*key:\s*'([a-z-]+)'/", $line, $m)) {
                $workspace = $m[1];
            }

            if (! preg_match("/href:\s*'([^']+)'/", $line, $m)) {
                continue;
            }

            // Items carrying query parameters are tab deep-links into a
            // page this matrix already covers through its own href.
            $items[] = [
                'href' => $m[1],
                'workspace' => $workspace,
                'moduleKey' => preg_match("/moduleKey:\s*'([^']+)'/", $line, $k) ? $k[1] : null,
                'capability' => preg_match("/capability:\s*'([^']+)'/", $line, $cap) ? $cap[1] : null,
                'adminOnly' => str_contains($line, 'adminOnly: true'),
                'tenantAdminOnly' => str_contains($line, 'tenantAdminOnly: true'),
            ];
        }

        return $items;
    }

    /** @return array{0: Tenant, 1: Company} */
    private function tenantOn(string $slug): array
    {
        $package = Package::where('slug', $slug)->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$slug, 'slug' => 'y-'.$slug.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Co', 'code' => 'CO', 'tenant_id' => $tenant->id, 'is_active' => true,
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

        return [$tenant, $company];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function planAndRole(): array
    {
        $cases = [];

        foreach (['starter', 'professional', 'business'] as $plan) {
            foreach ([User::ROLE_SUPER_ADMIN, 'hse', 'hrd', 'manager', 'warehouse'] as $role) {
                $cases["{$plan} / {$role}"] = [$plan, $role];
            }
        }

        return $cases;
    }

    /**
     * THE MATRIX. Fifteen combinations, every navigation item in each.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('planAndRole')]
    public function test_every_offered_navigation_item_is_reachable(string $plan, string $role): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
        config(['saas.enforce_entitlement' => true, 'saas.enforce_workspace_entitlement' => true]);

        [$tenant, $company] = $this->tenantOn($plan);

        $user = User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@y.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
        ]);

        $entitlements = app(EntitlementService::class);
        $enabledModules = $tenant->modules()->pluck('key')->all();

        $refused = [];
        $checked = 0;

        foreach ($this->navigationItems() as $item) {
            // The three gates the sidebar itself applies.
            if ($item['adminOnly'] && ! $user->isAdmin()) {
                continue;
            }
            if ($item['tenantAdminOnly'] && ! $user->isTenantAdmin()) {
                continue;
            }
            if ($item['moduleKey'] && ! in_array($item['moduleKey'], $enabledModules, true)) {
                continue;
            }

            /*
             * The sixth gate: a named capability, published on `auth.user`
             * and read by the sidebar. Mirrored here by calling the same
             * method the controller calls, so this test cannot pass because
             * the shared prop happens to be wrong.
             */
            if ($item['capability']) {
                $method = Str::camel($item['capability']);

                if (! method_exists($user, $method) || ! $user->{$method}()) {
                    continue;
                }
            }

            // And the workspace the item lives in, as the server computes it.
            if ($item['workspace'] && in_array($item['workspace'], config('plans.operational', []), true)
                && ! $entitlements->userCanUseWorkspace($user, $item['workspace'])) {
                continue;
            }

            if (! Route::has($item['href'])) {
                continue;
            }

            $route = Route::getRoutes()->getByName($item['href']);

            // Only parameterless GET destinations: anything needing an id is
            // reached from a list, not from the sidebar.
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
                continue;
            }

            $checked++;

            $status = $this->actingAs($user)->get(route($item['href']))->getStatusCode();

            if ($status === 403) {
                $refused[] = $item['href'];
            }
        }

        $this->assertGreaterThan(0, $checked, 'The matrix should actually check something.');

        $this->assertSame(
            [],
            array_values(array_unique($refused)),
            "Navigation offers these to {$plan} / {$role}, and the server refuses them:\n"
            .implode("\n", array_unique($refused))
        );
    }

    /** The registry is actually being read, so a silent parse failure cannot pass. */
    public function test_the_navigation_registry_is_parsed(): void
    {
        $items = $this->navigationItems();

        $this->assertGreaterThan(50, count($items), 'The registry should yield every nav entry.');

        $withWorkspace = array_filter($items, fn ($i) => $i['workspace'] !== null);
        $this->assertNotEmpty($withWorkspace, 'Items should be attributed to a workspace.');

        $gated = array_filter($items, fn ($i) => $i['moduleKey'] !== null);
        $this->assertNotEmpty($gated, 'Module-gated items should be recognised.');
    }
}
