<?php

namespace Tests\Feature;

use App\Models\MaterialRequest;
use App\Models\PurchaseRequisition;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.124.0 -- THE SANDBOX SEEDER ACTUALLY RUNS.
 *
 * `DemoTenantSeeder` writes `purchase_requisitions.source_material_request_id`
 * -- a column v2.69.0 DROPPED when a PR's link to its requests became a
 * pivot (a PR consolidates several requests, which one key cannot say).
 * Nothing in the suite ran this seeder, so nothing noticed: the command
 * its own doc comment documents,
 * `php artisan db:seed --class=DemoTenantSeeder`, died with "Unknown
 * column 'source_material_request_id'" on any fresh install, leaving the
 * Sandbox half-seeded.
 *
 * Found by running it. Pinned by running it.
 */
class DemoTenantSeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sandbox_seeder_completes_and_links_its_requisition(): void
    {
        Mail::fake();

        $this->seed(\Database\Seeders\PackageSeeder::class);
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $this->seed(\Database\Seeders\DemoTenantSeeder::class);

        $tenant = Tenant::where('slug', 'ioms-sandbox')->first();

        $this->assertNotNull($tenant, 'The Sandbox tenant was not created.');
        $this->assertTrue((bool) $tenant->is_demo);

        $requisition = PurchaseRequisition::withoutGlobalScopes()->first();
        $this->assertNotNull($requisition, 'The seeder stopped before its purchase requisition.');

        /*
         * The link the dropped column used to carry, where it lives now.
         * Read from the pivot TABLE rather than through the relation: the
         * seeder restores whatever tenant was current when it finishes, so
         * reading `materialRequests()` here would apply a company scope
         * resolved for a different tenant and report an empty relation for
         * a row that is plainly there.
         */
        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('material_request_purchase_requisition')
                ->where('purchase_requisition_id', $requisition->id)->count(),
            'The requisition is not linked to the material request it consolidates.',
        );

        $this->assertNotNull(MaterialRequest::withoutGlobalScopes()->first());
    }

    /** Running it twice must not break it -- it is idempotent by design. */
    public function test_the_sandbox_seeder_is_idempotent(): void
    {
        Mail::fake();

        $this->seed(\Database\Seeders\PackageSeeder::class);
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $this->seed(\Database\Seeders\DemoTenantSeeder::class);
        $this->seed(\Database\Seeders\DemoTenantSeeder::class);

        $this->assertSame(1, Tenant::where('slug', 'ioms-sandbox')->count());
        $this->assertSame(
            1,
            \Illuminate\Support\Facades\DB::table('material_request_purchase_requisition')->count(),
            'A second run duplicated the pivot row.',
        );
    }
}
