<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\PermitToWork;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v2.72.0 -- THE DIGITAL APPROVAL STAMP, AND THE THINGS IT MUST NEVER DO.
 *
 * A permit to work authorises dangerous work to begin. The seal that says
 * APPROVED is the part of it somebody glances at on a phone at a gate, so
 * the interesting assertions here are the NEGATIVE ones: the states in
 * which a seal must not exist.
 *
 * The stamp is driven entirely by the `authorization` prop. There is no
 * boolean a page could get wrong, so pinning the prop pins every renderer
 * that reads it -- the Show page, the Document view and the PDF alike.
 */
class PermitToWorkApprovalStampTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $requester;

    private User $hse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-stamp']);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'C', 'tenant_id' => $this->tenant->id]);

        $this->requester = User::create([
            'name' => 'Foreman', 'email' => 'foreman@stamp.test', 'password' => bcrypt('x'),
            'role' => 'manager', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->hse = User::create([
            'name' => 'Sri Handayani', 'email' => 'hse@stamp.test', 'password' => bcrypt('x'),
            'role' => 'hse', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
    }

    private function permit(string $status, ?int $approverId = null): PermitToWork
    {
        return PermitToWork::create([
            'ptw_number' => 'PTW-STAMP-'.uniqid(),
            'company_id' => $this->company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Approval stamp fixture.',
            'start_datetime' => Carbon::parse('2026-09-03 01:00:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-09-03 09:00:00', 'UTC'),
            'requested_by' => $this->requester->id,
            'hse_approver_id' => $approverId,
            'status' => $status,
        ]);
    }

    /**
     * BOTH HALVES ARE REQUIRED. A status alone is not authorisation --
     * `hse_approver_id` is what records who exercised it, and it is
     * written server-side only by a canManageHse() user.
     *
     * The `approved`-without-an-approver case is not hypothetical: real
     * rows in this shape exist from before the approver was recorded, and
     * stamping them would attribute an authorisation to nobody.
     */
    public function test_a_permit_is_not_authorised_without_both_an_approved_status_and_a_recorded_approver(): void
    {
        $this->assertFalse($this->permit('draft')->isAuthorised());
        $this->assertFalse($this->permit('submitted')->isAuthorised());
        $this->assertFalse($this->permit('rejected')->isAuthorised());
        $this->assertFalse(
            $this->permit('approved')->isAuthorised(),
            'An approved status with no recorded approver must not count as authorisation.'
        );
        $this->assertFalse(
            $this->permit('submitted', $this->hse->id)->isAuthorised(),
            'A recorded approver must not authorise a permit that is still awaiting review.'
        );
    }

    /**
     * Approval is a fact about the permit's HISTORY, not its current
     * step. A permit being worked, or since closed out, WAS authorised
     * and its record must keep saying so -- that is what a controlled
     * document is for.
     */
    public function test_approval_survives_the_permit_moving_on_to_active_and_closed(): void
    {
        foreach (['approved', 'active', 'closed'] as $status) {
            $this->assertTrue(
                $this->permit($status, $this->hse->id)->isAuthorised(),
                "A permit in the {$status} state must still read as authorised."
            );
        }
    }

    /**
     * THE ONE GENUINELY DANGEROUS CASE. A cancelled permit is void, and
     * it is the state most likely to be waved at somebody on site as
     * authorisation to proceed.
     */
    public function test_a_cancelled_permit_is_never_authorised_even_though_it_was_approved_once(): void
    {
        $this->assertFalse($this->permit('cancelled', $this->hse->id)->isAuthorised());
    }

    /**
     * The prop is the stamp's whole API: no record, no seal. This walks
     * the real HTTP route, so it covers what the page actually receives.
     */
    public function test_the_show_page_carries_no_authorization_record_until_hse_approves(): void
    {
        $permit = $this->permit('submitted');

        $this->actingAs($this->hse)
            ->get("/permits-to-work/{$permit->id}")
            ->assertInertia(fn ($page) => $page->where('authorization', null));
    }

    /**
     * The seal names the AUTHORITY a permit was signed under, not just a
     * person. `show()` constrains the hseApprover relation's columns, and
     * roleLabel() returns '' rather than failing for a column that was
     * not selected -- so this asserts the role actually arrives. Caught
     * in a browser, where the seal rendered a name and no role.
     */
    public function test_an_approved_permit_carries_the_approver_their_role_and_the_moment_from_the_audit_trail(): void
    {
        $permit = $this->permit('submitted');

        $this->actingAs($this->hse)
            ->post("/permits-to-work/{$permit->id}/transition", ['status' => 'approved'])
            ->assertRedirect();

        $this->actingAs($this->hse)
            ->get("/permits-to-work/{$permit->id}")
            ->assertInertia(fn ($page) => $page
                ->where('authorization.approver', 'Sri Handayani')
                ->where('authorization.role', 'HSE')
                ->whereNot('authorization.at', null)
            );

        // The moment comes from the ActivityLog the transition itself
        // wrote -- not from a second column that could drift from it.
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PermitToWork::class,
            'subject_id' => $permit->id,
            'action' => 'approved',
        ]);
    }

    /**
     * A permit approved before the audit trail carried this action stamps
     * correctly and simply shows no time, rather than a fabricated one.
     */
    public function test_a_missing_audit_entry_yields_no_time_rather_than_an_invented_one(): void
    {
        $permit = $this->permit('approved', $this->hse->id);

        ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permit->id)
            ->delete();

        $this->actingAs($this->hse)
            ->get("/permits-to-work/{$permit->id}")
            ->assertInertia(fn ($page) => $page
                ->where('authorization.approver', 'Sri Handayani')
                ->where('authorization.at', null)
            );
    }
}
