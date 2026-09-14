<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\MaterialRequest;
use App\Models\PurchaseRequisition;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * v2.69.0 -- Material Request lifecycle, aging, and demand consolidation.
 *
 * THE PROBLEM THESE PROTECT. A Material Request could sit at "Approved" or
 * "Processing" for months, and three completely different situations were
 * indistinguishable inside them: nobody has picked it up, Procurement is
 * deliberately holding it to buy with related demand, and it is genuinely
 * being fulfilled. Because the healthy case and the forgotten case looked
 * identical, neither could be managed.
 *
 * The rules worth pinning:
 *
 *   1. `consolidating` is reachable only from `approved`, carries a
 *      mandatory reason, and is NOT an approval or a requester action.
 *   2. Aging is derived, and stops the moment a request is no longer owed.
 *   3. A purchase may source MANY requests -- the relationship the old
 *      single `source_material_request_id` column could not express.
 *   4. Attaching demand to a purchase MOVES it, so the requester can see
 *      somebody has it; cancelling that purchase HANDS IT BACK rather
 *      than stranding it.
 *   5. Every id crossing these boundaries is tenant-scoped and
 *      state-scoped.
 */
class MaterialRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $procurement;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->admin] = $this->makeTenant('Alpha', 'ALFA');

        $this->procurement = $this->makeUser(User::ROLE_WAREHOUSE);
        $this->requester = $this->makeUser(User::ROLE_HSE);

        $this->actingAsTenant($this->admin);
    }

    /* ==============================================================
     * 1. CONSOLIDATION IS A DELIBERATE, ACCOUNTABLE DECISION
     * ============================================================== */

    public function test_approved_demand_can_be_held_for_consolidation_with_a_reason(): void
    {
        $mr = $this->makeRequest(MaterialRequest::STATUS_APPROVED);
        $this->actingAsTenant($this->procurement);

        $this->post(route('material-requests.consolidate', $mr), [
            'reason' => 'Menunggu permintaan APD lain agar dibeli sekaligus.',
        ])->assertRedirect();

        $fresh = $mr->fresh();
        $this->assertSame(MaterialRequest::STATUS_CONSOLIDATING, $fresh->status);
        $this->assertSame('Menunggu permintaan APD lain agar dibeli sekaligus.', $fresh->consolidation_reason);
        $this->assertSame($this->procurement->id, $fresh->consolidated_by);
        $this->assertNotNull($fresh->consolidated_at);
    }

    /**
     * Without a reason this is just another status, and the whole point is
     * that it can be told apart from a forgotten request three weeks later.
     */
    public function test_holding_demand_without_a_reason_is_rejected(): void
    {
        $mr = $this->makeRequest(MaterialRequest::STATUS_APPROVED);
        $this->actingAsTenant($this->procurement);

        $this->post(route('material-requests.consolidate', $mr), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(MaterialRequest::STATUS_APPROVED, $mr->fresh()->status);
    }

    public function test_a_requester_cannot_park_their_own_request(): void
    {
        $mr = $this->makeRequest(MaterialRequest::STATUS_APPROVED);
        $this->actingAsTenant($this->requester);

        $this->post(route('material-requests.consolidate', $mr), ['reason' => 'Nanti dulu.'])
            ->assertForbidden();
    }

    public function test_demand_already_being_fulfilled_cannot_be_consolidated(): void
    {
        $mr = $this->makeRequest(MaterialRequest::STATUS_PROCESSING);

        $this->assertFalse(
            $mr->canTransitionTo(MaterialRequest::STATUS_CONSOLIDATING),
            'Consolidating something already being processed would mean un-processing it.'
        );
    }

    public function test_a_hold_can_be_released_back_into_the_approved_queue(): void
    {
        $mr = $this->makeRequest(MaterialRequest::STATUS_CONSOLIDATING, [
            'consolidation_reason' => 'Batching.',
            'consolidated_by' => $this->procurement->id,
            'consolidated_at' => now(),
        ]);

        $this->actingAsTenant($this->procurement);
        $this->post(route('material-requests.release-consolidation', $mr))->assertRedirect();

        $fresh = $mr->fresh();
        $this->assertSame(MaterialRequest::STATUS_APPROVED, $fresh->status);
        $this->assertNull($fresh->consolidation_reason, 'A released hold must not leave its old reason behind.');
    }

    /* ==============================================================
     * 2. AGING IS DERIVED
     * ============================================================== */

    public function test_age_is_counted_only_while_the_request_is_still_owed(): void
    {
        $outstanding = $this->makeRequest(MaterialRequest::STATUS_APPROVED, ['request_date' => now()->subDays(40)]);
        $finished = $this->makeRequest(MaterialRequest::STATUS_COMPLETED, ['request_date' => now()->subDays(40)]);
        $draft = $this->makeRequest(MaterialRequest::STATUS_DRAFT, ['request_date' => now()->subDays(40)]);

        $this->assertSame(40, $outstanding->open_age_days);
        $this->assertSame('overdue', $outstanding->aging_level);

        $this->assertNull($finished->open_age_days, 'A completed request has an age that means nothing.');
        $this->assertNull($draft->open_age_days, 'A draft has not been asked for yet.');
    }

    public function test_aging_levels_follow_the_documented_thresholds(): void
    {
        $fresh = $this->makeRequest(MaterialRequest::STATUS_SUBMITTED, ['request_date' => now()->subDays(3)]);
        $ageing = $this->makeRequest(MaterialRequest::STATUS_SUBMITTED, ['request_date' => now()->subDays(MaterialRequest::AGING_ATTENTION_DAYS)]);
        $overdue = $this->makeRequest(MaterialRequest::STATUS_SUBMITTED, ['request_date' => now()->subDays(MaterialRequest::AGING_OVERDUE_DAYS)]);

        $this->assertSame('normal', $fresh->aging_level);
        $this->assertSame('attention', $ageing->aging_level);
        $this->assertSame('overdue', $overdue->aging_level);
    }

    public function test_the_outstanding_scope_is_every_non_terminal_state(): void
    {
        foreach ([
            MaterialRequest::STATUS_DRAFT,
            MaterialRequest::STATUS_SUBMITTED,
            MaterialRequest::STATUS_APPROVED,
            MaterialRequest::STATUS_CONSOLIDATING,
            MaterialRequest::STATUS_PROCESSING,
            MaterialRequest::STATUS_COMPLETED,
            MaterialRequest::STATUS_CANCELLED,
            MaterialRequest::STATUS_REJECTED,
        ] as $status) {
            $this->makeRequest($status);
        }

        $outstanding = MaterialRequest::query()->outstanding()->pluck('status')->sort()->values()->all();

        $this->assertSame(
            ['approved', 'consolidating', 'processing', 'submitted'],
            $outstanding,
            'Consolidated demand is still owed to its requester and must keep ageing -- it is a deliberate '
            .'wait, not a finished one.'
        );
    }

    /* ==============================================================
     * 3. CANCELLING RECORDS WHY
     * ============================================================== */

    public function test_cancelling_requires_and_stores_a_reason(): void
    {
        $mr = $this->makeRequest(MaterialRequest::STATUS_APPROVED);

        $this->post(route('material-requests.cancel', $mr), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->post(route('material-requests.cancel', $mr), ['reason' => 'Dibeli lewat kontrak payung.'])
            ->assertRedirect();

        $fresh = $mr->fresh();
        $this->assertSame(MaterialRequest::STATUS_CANCELLED, $fresh->status);
        $this->assertSame('Dibeli lewat kontrak payung.', $fresh->cancellation_reason);
    }

    /* ==============================================================
     * 4. ONE PURCHASE, MANY REQUESTS
     * ============================================================== */

    public function test_one_purchase_requisition_can_source_several_material_requests(): void
    {
        $first = $this->makeRequest(MaterialRequest::STATUS_APPROVED);
        $second = $this->makeRequest(MaterialRequest::STATUS_CONSOLIDATING, [
            'consolidation_reason' => 'Waiting for more gloves demand.',
        ]);

        $this->actingAsTenant($this->procurement);

        $this->post(route('purchase-requisitions.store'), $this->requisitionPayload([$first->id, $second->id]))
            ->assertRedirect();

        $pr = PurchaseRequisition::latest('id')->first();

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $pr->materialRequests()->pluck('material_requests.id')->all(),
            'Consolidation is several requests becoming one purchase -- the relationship the dropped '
            .'source_material_request_id column could not express.'
        );
    }

    public function test_attaching_demand_to_a_purchase_moves_it_to_processing(): void
    {
        $first = $this->makeRequest(MaterialRequest::STATUS_APPROVED);
        $second = $this->makeRequest(MaterialRequest::STATUS_CONSOLIDATING, ['consolidation_reason' => 'Batching.']);

        $this->actingAsTenant($this->procurement);
        $this->post(route('purchase-requisitions.store'), $this->requisitionPayload([$first->id, $second->id]));

        $this->assertSame(MaterialRequest::STATUS_PROCESSING, $first->fresh()->status);
        $this->assertSame(
            MaterialRequest::STATUS_PROCESSING,
            $second->fresh()->status,
            'Raising the purchase is exactly the moment a held request stops waiting.'
        );
    }

    public function test_cancelling_a_purchase_hands_its_demand_back_instead_of_stranding_it(): void
    {
        $mr = $this->makeRequest(MaterialRequest::STATUS_APPROVED);

        $this->actingAsTenant($this->procurement);
        $this->post(route('purchase-requisitions.store'), $this->requisitionPayload([$mr->id]));
        $pr = PurchaseRequisition::latest('id')->first();

        $this->assertSame(MaterialRequest::STATUS_PROCESSING, $mr->fresh()->status);

        // Cancellation is an override, so act as the admin.
        $this->actingAsTenant($this->admin);
        $this->post(route('purchase-requisitions.cancel', $pr))->assertRedirect();

        $this->assertSame(
            MaterialRequest::STATUS_APPROVED,
            $mr->fresh()->status,
            'A cancelled purchase must not leave the demand it carried stuck in processing with nobody '
            .'working on it -- that is the exact failure this release exists to remove.'
        );
    }

    /* ==============================================================
     * 5. BOUNDARIES
     * ============================================================== */

    public function test_a_draft_request_cannot_be_attached_to_a_purchase(): void
    {
        $draft = $this->makeRequest(MaterialRequest::STATUS_DRAFT);

        $this->actingAsTenant($this->procurement);

        $this->post(route('purchase-requisitions.store'), $this->requisitionPayload([$draft->id]))
            ->assertSessionHasErrors('material_request_ids.0');
    }

    public function test_another_tenants_demand_cannot_be_attached_to_a_purchase(): void
    {
        [$otherCompany, $otherAdmin] = $this->makeTenant('Beta', 'BETA');

        $this->actingAsTenant($otherAdmin);
        $foreign = MaterialRequest::create([
            'request_number' => 'MR-2026-09999',
            'request_date' => now()->toDateString(),
            'company_id' => $otherCompany->id,
            'requested_by' => $otherAdmin->id,
            'status' => MaterialRequest::STATUS_APPROVED,
        ]);

        $this->actingAsTenant($this->procurement);

        $this->post(route('purchase-requisitions.store'), $this->requisitionPayload([$foreign->id]))
            ->assertSessionHasErrors('material_request_ids.0');

        $this->assertSame(
            0,
            DB::table('material_request_purchase_requisition')->count(),
            'No link may be written for demand the buyer cannot see.'
        );
    }

    /* ==============================================================
     * Fixtures
     * ============================================================== */

    /** @return array{0: Company, 1: User} */
    private function makeTenant(string $name, string $code): array
    {
        $tenant = Tenant::create([
            'name' => $name, 'slug' => strtolower($code).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'code' => $code, 'is_active' => true,
        ]);

        $admin = User::create([
            'name' => "$name Admin",
            'email' => strtolower($code).'-'.uniqid().'@example.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        Department::create(['company_id' => $company->id, 'name' => "$name Ops", 'is_active' => true]);

        return [$company, $admin];
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => $role.' user',
            'email' => uniqid().'-'.$role.'@example.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->admin->tenant_id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    private function makeRequest(string $status, array $attributes = []): MaterialRequest
    {
        return MaterialRequest::create([
            'request_number' => 'MR-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'request_date' => now()->toDateString(),
            'company_id' => $this->company->id,
            'requested_by' => $this->requester->id,
            'status' => $status,
            ...$attributes,
        ]);
    }

    private function requisitionPayload(array $materialRequestIds): array
    {
        return [
            'company_id' => $this->company->id,
            'material_request_ids' => $materialRequestIds,
            'request_date' => now()->toDateString(),
            'priority' => 'medium',
            'items' => [
                ['description' => 'Safety gloves', 'quantity' => 20, 'unit' => 'pair', 'estimated_unit_price' => 15000],
            ],
        ];
    }

    private function actingAsTenant(User $user): void
    {
        $this->be($user);
        app(CurrentTenant::class)->set($user->tenant);
    }
}
