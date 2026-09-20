<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PermitToWork;
use App\Models\PpeType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v2.73.0 -- REQUIRED PPE vs CONFIRMED PPE.
 *
 * The split is the whole feature. `required_ppe_ids` is what the work
 * calls for, decided when the permit is raised. `confirmed_ppe_ids` is
 * what somebody verified was actually present, written at authorisation
 * by the person authorising. The gap between them is the only thing a
 * permit is for, and it survives only if a requester cannot write the
 * second list.
 */
class PermitToWorkPpeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $requester;

    private User $hse;

    private PpeType $helmet;

    private PpeType $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-ppe']);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'C', 'tenant_id' => $this->tenant->id]);

        // `ppe_types` is installation-wide reference data with no
        // company_id -- see PpeType's own doc comment on why that is
        // deliberate and not an oversight.
        $this->helmet = PpeType::create(['name' => 'Safety Helmet', 'is_active' => true]);
        $this->harness = PpeType::create(['name' => 'Harness', 'is_active' => true]);

        $this->requester = User::create([
            'name' => 'Foreman', 'email' => 'foreman@ppe.test', 'password' => bcrypt('x'),
            'role' => 'manager', 'tenant_id' => $this->tenant->id, 'is_active' => true, 'ptw_access' => true,
        ]);

        $this->hse = User::create([
            'name' => 'HSE Lead', 'email' => 'hse@ppe.test', 'password' => bcrypt('x'),
            'role' => 'hse', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'PPE fixture.',
            'start_datetime' => '2026-09-20 08:00',
            'end_datetime' => '2026-09-20 17:00',
            'required_ppe_ids' => [$this->helmet->id, $this->harness->id],
        ], $overrides);
    }

    public function test_a_requester_states_what_ppe_the_work_requires(): void
    {
        $this->actingAs($this->requester)
            ->post('/permits-to-work', $this->payload())
            ->assertRedirect();

        $permit = PermitToWork::withoutGlobalScopes()->latest('id')->first();

        $this->assertEqualsCanonicalizing(
            [$this->helmet->id, $this->harness->id],
            collect($permit->required_ppe_ids)->map(fn ($id) => (int) $id)->all()
        );

        // Nothing is confirmed before anybody has authorised it, and the
        // gap is the full required list.
        $this->assertNull($permit->confirmed_ppe_ids);
        $this->assertEqualsCanonicalizing(
            [$this->helmet->id, $this->harness->id],
            $permit->unconfirmedPpeIds()
        );
    }

    /**
     * THE BOUNDARY. A requester posting `confirmed_ppe_ids` on the create
     * request would be asserting their own compliance, which is precisely
     * what the two-list design exists to prevent. The field is not in the
     * FormRequest's rules, so it is dropped rather than honoured.
     */
    public function test_a_requester_cannot_confirm_their_own_ppe(): void
    {
        $this->actingAs($this->requester)
            ->post('/permits-to-work', $this->payload([
                'confirmed_ppe_ids' => [$this->helmet->id, $this->harness->id],
            ]))
            ->assertRedirect();

        $permit = PermitToWork::withoutGlobalScopes()->latest('id')->first();

        $this->assertNull(
            $permit->confirmed_ppe_ids,
            'A requester must never be able to record that PPE was verified present.'
        );
    }

    /** PPE that is not on the tenant-visible master cannot be smuggled in by id. */
    public function test_an_unknown_or_inactive_ppe_type_is_rejected(): void
    {
        $retired = PpeType::create(['name' => 'Retired Item', 'is_active' => false]);

        $this->actingAs($this->requester)
            ->post('/permits-to-work', $this->payload(['required_ppe_ids' => [$retired->id]]))
            ->assertSessionHasErrors('required_ppe_ids.0');

        $this->actingAs($this->requester)
            ->post('/permits-to-work', $this->payload(['required_ppe_ids' => [999999]]))
            ->assertSessionHasErrors('required_ppe_ids.0');
    }

    /**
     * Confirmation is written server-side at authorisation, by the
     * approver -- the same discipline `hse_approver_id` runs on.
     */
    public function test_the_approver_records_what_was_actually_present(): void
    {
        $permit = $this->makeSubmittedPermit();

        $this->actingAs($this->hse)
            ->post("/permits-to-work/{$permit->id}/transition", [
                'status' => 'approved',
                // Checked on site: the helmet was there, the harness was not.
                'confirmed_ppe_ids' => [$this->helmet->id],
            ])
            ->assertRedirect();

        $permit->refresh();

        $this->assertSame('approved', $permit->status);
        $this->assertEqualsCanonicalizing([$this->helmet->id], collect($permit->confirmed_ppe_ids)->map(fn ($i) => (int) $i)->all());
        $this->assertSame([$this->harness->id], $permit->unconfirmedPpeIds());
    }

    /**
     * Approving without narrowing the list IS the statement that the
     * permit's stated PPE is in place -- otherwise every permit would
     * read as non-compliant unless the approver re-ticked every box,
     * which is how a control becomes noise.
     */
    public function test_approving_without_narrowing_confirms_the_required_list(): void
    {
        $permit = $this->makeSubmittedPermit();

        $this->actingAs($this->hse)
            ->post("/permits-to-work/{$permit->id}/transition", ['status' => 'approved'])
            ->assertRedirect();

        $permit->refresh();

        $this->assertEqualsCanonicalizing(
            [$this->helmet->id, $this->harness->id],
            collect($permit->confirmed_ppe_ids)->map(fn ($i) => (int) $i)->all()
        );
        $this->assertSame([], $permit->unconfirmedPpeIds());
    }

    private function makeSubmittedPermit(): PermitToWork
    {
        return PermitToWork::create([
            'ptw_number' => 'PTW-PPE-'.uniqid(),
            'company_id' => $this->company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'PPE fixture.',
            'start_datetime' => Carbon::parse('2026-09-20 01:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-09-20 09:00', 'UTC'),
            'requested_by' => $this->requester->id,
            'status' => PermitToWork::STATUS_SUBMITTED,
            'required_ppe_ids' => [$this->helmet->id, $this->harness->id],
        ]);
    }
}
