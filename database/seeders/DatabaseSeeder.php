<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Milestone 2 (Tenancy Foundation): seeders run via CLI (`db:seed`),
     * never through an HTTP request, so `App\Http\Middleware\ResolveTenant`
     * never runs for them -- CurrentTenant would otherwise stay
     * unresolved and Company::TenantScope would fail closed (see that
     * class's own doc comment), causing every seeder that touches
     * Company-scoped data to silently see/create nothing.
     *
     * Resolving and binding the one pre-existing tenant HERE, once,
     * before any other seeder runs, means every seeder below keeps
     * working exactly as it did before tenancy existed -- none of them
     * need to know tenancy exists at all, except CompanySeeder itself
     * (the one place that actually creates Company rows, so it's the one
     * place a tenant_id must be assigned explicitly).
     */
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Tenant', 'status' => Tenant::STATUS_ACTIVE]
        );

        app(CurrentTenant::class)->set($tenant);

        $this->call([
            PackageSeeder::class, // not tenant-scoped -- platform catalog
            SubscriptionSeeder::class,
            ModuleSeeder::class, // not tenant-scoped -- platform catalog
            WorkspaceSeeder::class, // not tenant-scoped -- platform catalog
            TenantGrantSeeder::class, // grants the current tenant everything, preserving pre-grant-system behavior
            CompanySeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            KpiCategorySeeder::class,
            PpeTypeSeeder::class,
            UserSeeder::class,
            PlatformAdminSeeder::class, // tenant_id null by design -- see the seeder's own doc comment
            RolePermissionSeeder::class, // must run after every seeded User exists, to assign roles
            CompanySettingSeeder::class,
            EmployeeSeeder::class, // demo data; safe to skip in production via --class flag usage
        ]);
    }
}
