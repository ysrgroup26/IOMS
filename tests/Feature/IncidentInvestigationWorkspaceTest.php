<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Incident;
use App\Models\IncidentInvestigation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.73.0 -- THE INVESTIGATION WORKSPACE: separation, lifecycle, isolation.
 *
 * `/investigations` is a brand-new route prefix on a brand-new record, so
 * the tenant boundary on it is pinned here rather than assumed from the
 * fact that the rest of HSE is scoped. See docs/ADR/036.
 */
class IncidentInvestigationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWith(string $slug): array
    {
        $tenant = Tenant::create(['name' => 'T-'.$slug, 'slug' => $slug]);
        $company = Company::withoutGlobalScopes()->create(['name' => 'C-'.$slug, 'tenant_id' => $tenant->id]);
        $hse = User::create([
            'name' => 'HSE '.$slug, 'email' => "hse@{$slug}.test", 'password' => bcrypt('x'),
            'role' => 'hse', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $incident = Incident::create([
            'incident_number' => 'INC-'.$slug,
            'title' => 'Event in '.$slug,
            'incident_date' => '2026-09-20',
            'severity' => 'major',
            'category' => 'injury',
            'status' => Incident::STATUS_REPORTED,
            'company_id' => $company->id,
            'reported_by' => $hse->id,
        ]);

        return compact('tenant', 'company', 'hse', 'incident');
    }

    /**
     * THE SEPARATION, AS A CONTRACT. Opening an investigation creates a
     * record with its own number and state -- it does not write findings
     * onto the report.
     */
    public function test_opening_an_investigation_creates_its_own_record_and_advances_the_incident(): void
    {
        ['hse' => $hse, 'incident' => $incident] = $this->tenantWith('alpha');

        $this->actingAs($hse)
            ->post("/incidents/{$incident->id}/investigations", ['investigator_id' => $hse->id])
            ->assertRedirect();

        $investigation = IncidentInvestigation::withoutGlobalScopes()->first();

        $this->assertNotNull($investigation);
        $this->assertStringStartsWith('INV-', $investigation->investigation_number);
        $this->assertSame(IncidentInvestigation::STATUS_DRAFT, $investigation->status);
        $this->assertSame($hse->id, $investigation->investigator_id);

        // The report itself is untouched apart from its own lifecycle.
        $incident->refresh();
        $this->assertSame(Incident::STATUS_INVESTIGATING, $incident->status);
        $this->assertNull($incident->getAttribute('root_cause'), 'The initial report must never carry a root cause.');
    }

    /** One event, one investigation -- `incident_id` is unique by design. */
    public function test_a_second_investigation_cannot_be_opened_for_the_same_incident(): void
    {
        ['hse' => $hse, 'incident' => $incident] = $this->tenantWith('beta');

        $this->actingAs($hse)->post("/incidents/{$incident->id}/investigations", ['investigator_id' => $hse->id]);

        $this->actingAs($hse)
            ->post("/incidents/{$incident->id}/investigations", ['investigator_id' => $hse->id])
            ->assertSessionHasErrors('incident_id');

        $this->assertSame(1, IncidentInvestigation::withoutGlobalScopes()->count());
    }

    /**
     * THE TENANT BOUNDARY on the new prefix. 404 rather than 403, matching
     * the pattern used throughout HSE -- a 403 confirms the record exists.
     */
    public function test_an_investigation_cannot_be_read_or_written_across_tenants(): void
    {
        ['hse' => $hseA, 'incident' => $incidentA] = $this->tenantWith('one');
        ['hse' => $hseB] = $this->tenantWith('two');

        $this->actingAs($hseA)->post("/incidents/{$incidentA->id}/investigations", ['investigator_id' => $hseA->id]);
        $investigation = IncidentInvestigation::withoutGlobalScopes()->first();

        $this->actingAs($hseB)->get("/investigations/{$investigation->id}")->assertNotFound();
        $this->actingAs($hseB)->put("/investigations/{$investigation->id}", ['findings' => 'injected'])->assertNotFound();
        $this->actingAs($hseB)->post("/investigations/{$investigation->id}/transition", ['status' => 'closed'])->assertNotFound();
        $this->actingAs($hseB)->post("/investigations/{$investigation->id}/interviews", ['person_name' => 'X'])->assertNotFound();

        $investigation->refresh();
        $this->assertNull($investigation->findings);
        $this->assertSame(IncidentInvestigation::STATUS_DRAFT, $investigation->status);
    }

    /** The index must never show another tenant's investigations. */
    public function test_the_index_is_scoped_to_the_signed_in_tenant(): void
    {
        ['hse' => $hseA, 'incident' => $incidentA] = $this->tenantWith('three');
        ['hse' => $hseB] = $this->tenantWith('four');

        $this->actingAs($hseA)->post("/incidents/{$incidentA->id}/investigations", ['investigator_id' => $hseA->id]);

        $this->actingAs($hseB)
            ->get('/investigations')
            ->assertInertia(fn ($page) => $page->where('investigations.data', []));
    }

    /**
     * The state machine is the authority. An illegal jump must be refused
     * even though the request is otherwise well-formed and authorised --
     * browser-verified too, where `under_review -> draft` was rejected.
     */
    public function test_an_illegal_transition_is_refused(): void
    {
        ['hse' => $hse, 'incident' => $incident] = $this->tenantWith('five');

        $this->actingAs($hse)->post("/incidents/{$incident->id}/investigations", ['investigator_id' => $hse->id]);
        $investigation = IncidentInvestigation::withoutGlobalScopes()->first();

        // draft -> closed is not a transition the model allows.
        $this->actingAs($hse)
            ->post("/investigations/{$investigation->id}/transition", ['status' => 'closed'])
            ->assertSessionHasErrors();

        $this->assertSame(IncidentInvestigation::STATUS_DRAFT, $investigation->refresh()->status);
    }

    /**
     * Closing the investigation closes the report with it -- the incident
     * has no further state of its own once the work it triggered is done,
     * and leaving it at `investigating` forever is how an incident list
     * stops being believed.
     */
    public function test_closing_the_investigation_closes_the_incident(): void
    {
        ['hse' => $hse, 'incident' => $incident] = $this->tenantWith('six');

        $this->actingAs($hse)->post("/incidents/{$incident->id}/investigations", ['investigator_id' => $hse->id]);
        $investigation = IncidentInvestigation::withoutGlobalScopes()->first();

        foreach (['in_progress', 'under_review', 'completed', 'closed'] as $status) {
            $this->actingAs($hse)
                ->post("/investigations/{$investigation->id}/transition", ['status' => $status])
                ->assertRedirect();
        }

        $investigation->refresh();
        $this->assertSame(IncidentInvestigation::STATUS_CLOSED, $investigation->status);
        // Both stamps are written server-side, never from a form.
        $this->assertSame($hse->id, $investigation->reviewed_by);
        $this->assertSame($hse->id, $investigation->closed_by);

        $this->assertSame(Incident::STATUS_CLOSED, $incident->refresh()->status);
    }
}
