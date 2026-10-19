<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Incident;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.124.0 -- AN INCIDENT WITH NO OPERATING UNIT IS STILL SOMEBODY'S.
 *
 * `incidents.company_id` is nullable and the create form lets it be left
 * empty, but every read path is company-scoped. So a report saved without
 * a unit was written, numbered, redirected to -- and then answered 404 for
 * the person who had just filed it, and appeared in no register for any
 * tenant. Found by filing one in a browser against MySQL 8 and then
 * looking for it as three different accounts: zero rows each.
 *
 * The controller's own comment claimed the opposite ("visible to every
 * tenant -- nothing to leak"). An incident report carries the injured
 * person's name; it is exactly the thing that must not be shared.
 *
 * It is now owned through its REPORTER, the shape `Task` and `ActivityLog`
 * already use.
 */
class IncidentWithoutOperatingUnitTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: Company, 2: User} */
    private function tenant(string $name): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => $name.' Unit', 'code' => strtoupper(substr($name, 0, 2)).rand(100, 999),
            'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(30),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());

        $user = User::create([
            'name' => $name.' HSE', 'email' => strtolower($name).'-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'hse',
            'tenant_id' => $tenant->id, 'company_id' => $company->id,
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        return [$tenant, $company, $user];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    private function fileIncident(User $reporter, ?int $companyId): Incident
    {
        return Incident::withoutGlobalScopes()->create([
            'incident_number' => 'INC-TEST-'.uniqid(),
            'company_id' => $companyId,
            'title' => 'Dropped load near bay 3',
            'incident_date' => now()->subDay(),
            'severity' => Incident::SEVERITIES[0],
            'category' => Incident::CATEGORIES[0],
            'status' => 'reported',
            'reported_by' => $reporter->id,
        ]);
    }

    public function test_the_reporters_tenant_can_read_an_incident_with_no_operating_unit(): void
    {
        [$tenant, , $reporter] = $this->tenant('Alpha');

        $incident = $this->fileIncident($reporter, null);

        app(CurrentTenant::class)->set($tenant->fresh());

        $this->actingAs($reporter)
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($incident) {
                $ids = array_column($page->toArray()['props']['incidents']['data'], 'id');

                $this->assertContains($incident->id, $ids, 'The reporter cannot find the incident they filed.');
            });

        $this->actingAs($reporter)->get(route('incidents.show', $incident))->assertOk();
    }

    public function test_another_tenant_can_neither_list_nor_open_it(): void
    {
        [, , $reporter] = $this->tenant('Alpha');
        $incident = $this->fileIncident($reporter, null);

        [$other, , $stranger] = $this->tenant('Beta');
        app(CurrentTenant::class)->set($other->fresh());

        $this->actingAs($stranger)
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(function ($page) use ($incident) {
                $ids = array_column($page->toArray()['props']['incidents']['data'], 'id');

                $this->assertNotContains($incident->id, $ids, 'Another tenant can read an incident report.');
            });

        $this->actingAs($stranger)->get(route('incidents.show', $incident))->assertNotFound();
    }

    /** A unit-scoped incident behaves exactly as it did before. */
    public function test_an_incident_with_a_unit_is_unchanged(): void
    {
        [$tenant, $company, $reporter] = $this->tenant('Alpha');
        $incident = $this->fileIncident($reporter, $company->id);

        app(CurrentTenant::class)->set($tenant->fresh());
        $this->actingAs($reporter)->get(route('incidents.show', $incident))->assertOk();

        [$other, , $stranger] = $this->tenant('Beta');
        app(CurrentTenant::class)->set($other->fresh());
        $this->actingAs($stranger)->get(route('incidents.show', $incident))->assertNotFound();
    }

    /** And the model answers the same question directly. */
    public function test_the_scope_is_what_answers_rather_than_a_controller_where_clause(): void
    {
        [$tenant, , $reporter] = $this->tenant('Alpha');
        $mine = $this->fileIncident($reporter, null);

        [, , $stranger] = $this->tenant('Beta');
        $theirs = $this->fileIncident($stranger, null);

        app(CurrentTenant::class)->set($tenant->fresh());

        $visible = Incident::query()->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id));
    }
}
