<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Approval;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\MaterialRequest;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ApprovalEngine;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.100.0 -- TWO IDENTITY QUESTIONS THAT WERE ANSWERED BY ONE COLUMN.
 *
 * WHAT SOMEBODY IS CALLED. `users.name` is the registered identity: it
 * arrives as the registration's contact name and belongs beside the email
 * on an invoice. What a colleague should see is a different question, and
 * with one column an administrator could only answer the second by
 * destroying the first. `display_name` is additive and nullable, so
 * nothing changes until somebody sets one.
 *
 * WHO DID THE THING. Every actor on screen -- a timeline entry, an audit
 * row, an approval stamp, a signature on a printed permit -- was resolved
 * by following a foreign key to that account's CURRENT name. So renaming
 * one person silently rewrote every document they had ever raised or
 * approved, and removing them emptied the stamp. For a product whose
 * permits are the compliance record, an audit trail that changes when
 * somebody marries is not an audit trail.
 *
 * The names are now captured when the action happens. The keys stay, and
 * stay authoritative for "which account"; the snapshots answer "what did
 * this say at the time", which is the only question a historical document
 * can honestly answer.
 */
class IdentitySeparationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan Nusantara',
            'slug' => 'galangan-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Galangan Nusantara',
            'code' => 'GN',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());
    }

    private function user(string $role = User::ROLE_SUPER_ADMIN, array $overrides = []): User
    {
        return User::create([
            'name' => 'Budi Santoso',
            'email' => 'user-'.uniqid().'@galangan.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /* ==================================================================
     * 1. A PERSON HAS A REGISTERED NAME AND A DISPLAY NAME
     * ================================================================== */

    public function test_an_account_with_no_display_name_shows_its_registered_name(): void
    {
        $user = $this->user();

        $this->assertNull($user->display_name);
        $this->assertSame('Budi Santoso', $user->displayName());
    }

    public function test_a_display_name_is_preferred_when_set(): void
    {
        $user = $this->user(overrides: ['display_name' => 'Budi (Produksi)']);

        $this->assertSame('Budi (Produksi)', $user->displayName());
    }

    /** A blank or whitespace value is "unset", never the person's name. */
    public function test_a_blank_display_name_is_treated_as_unset(): void
    {
        foreach (['', '   '] as $blank) {
            $user = $this->user(overrides: ['display_name' => $blank]);

            $this->assertSame('Budi Santoso', $user->displayName());
        }
    }

    /** One setting reaches every surface, through the shared prop. */
    public function test_the_shared_auth_prop_carries_the_display_name(): void
    {
        $user = $this->user(overrides: ['display_name' => 'Budi (Produksi)']);

        $this->actingAs($user)
            ->get(route('hse.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.user.name', 'Budi (Produksi)'));
    }

    /* ==================================================================
     * AN ADMINISTRATOR SETS IT, AND SETTING IT DESTROYS NOTHING
     * ================================================================== */

    public function test_an_administrator_can_set_a_display_name_without_changing_the_registered_name(): void
    {
        $admin = $this->user();
        $member = $this->user('hse');

        $this->actingAs($admin)
            ->put(route('settings.users.update', $member->id), [
                'name' => 'Budi Santoso',
                'display_name' => 'Budi (Produksi)',
                'email' => $member->email,
                'role' => 'hse',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $member->refresh();

        // THE SEPARATION: the display identity changed, the registered
        // identity did not.
        $this->assertSame('Budi (Produksi)', $member->display_name);
        $this->assertSame('Budi Santoso', $member->name);
        $this->assertSame('Budi (Produksi)', $member->displayName());
    }

    public function test_clearing_a_display_name_restores_the_registered_name(): void
    {
        $admin = $this->user();
        $member = $this->user('hse', ['display_name' => 'Budi (Produksi)']);

        $this->actingAs($admin)
            ->put(route('settings.users.update', $member->id), [
                'name' => 'Budi Santoso',
                'display_name' => '',
                'email' => $member->email,
                'role' => 'hse',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Budi Santoso', $member->fresh()->displayName());
    }

    public function test_the_users_list_offers_both_identities_and_the_resolved_one(): void
    {
        $admin = $this->user();
        $this->user('hse', ['display_name' => 'Budi (Produksi)']);

        $this->actingAs($admin)
            ->get(route('settings.index', ['tab' => 'users']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $users = collect($page->toArray()['props']['users']);
                $row = $users->firstWhere('display_name', 'Budi (Produksi)');

                $this->assertNotNull($row, 'The display name must reach the users table.');
                $this->assertSame('Budi Santoso', $row['name'], 'The registered name must still be available.');
                $this->assertSame('Budi (Produksi)', $row['display'], 'The resolved identity is sent, so no column re-implements the fallback.');
            });
    }

    /**
     * ORGANIZATION IDENTITY IS NOT USER IDENTITY. The company's name comes
     * from the company settings; a person's comes from their account. One
     * must never stand in for the other.
     */
    public function test_organization_identity_and_user_identity_are_separate(): void
    {
        $user = $this->user(overrides: ['display_name' => 'Budi (Produksi)']);
        CompanySetting::set('company_name', 'PT Galangan Nusantara');

        $this->actingAs($user)
            ->get(route('hse.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.name', 'Budi (Produksi)')
                ->where('company.name', 'PT Galangan Nusantara'));
    }

    /* ==================================================================
     * 2. AN ACTOR IS A FACT ABOUT THE PAST
     * ================================================================== */

    public function test_an_activity_entry_records_the_actor_name_as_it_was(): void
    {
        $user = $this->user(overrides: ['display_name' => 'Budi (Produksi)']);
        $this->actingAs($user);

        $log = ActivityLog::record('updated', 'Something happened.');

        $this->assertSame('Budi (Produksi)', $log->actor_name);
    }

    /** THE DEFECT: renaming somebody rewrote their whole history. */
    public function test_renaming_a_user_does_not_rewrite_history(): void
    {
        $user = $this->user(overrides: ['display_name' => 'Budi (Produksi)']);
        $this->actingAs($user);

        $log = ActivityLog::record('updated', 'Something happened.');

        $user->update(['display_name' => 'Someone Else Entirely', 'name' => 'Renamed Account']);

        $this->assertSame(
            'Budi (Produksi)',
            $log->fresh()->actor_name,
            'A recorded action must keep the name it was recorded under.'
        );
    }

    /** And removing the account does not empty the entry. */
    public function test_deleting_a_user_leaves_the_recorded_actor_intact(): void
    {
        $user = $this->user(overrides: ['display_name' => 'Budi (Produksi)']);
        $this->actingAs($user);

        $log = ActivityLog::record('updated', 'Something happened.');

        $user->delete();

        $this->assertSame('Budi (Produksi)', $log->fresh()->actor_name);
    }

    /** A system action with no authenticated actor records none. */
    public function test_a_system_action_records_no_actor_rather_than_a_fabricated_one(): void
    {
        $log = ActivityLog::record('updated', 'The nightly job ran.');

        $this->assertNull($log->actor_name);
    }

    /* ==================================================================
     * THE SIGNATURES ON A DOCUMENT
     * ================================================================== */

    private function materialRequest(User $requester): MaterialRequest
    {
        return MaterialRequest::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'request_number' => 'MR-'.uniqid(),
            'requested_by' => $requester->id,
            // `submitted`, because the workflow only allows an approval
            // decision from there -- see HasWorkflow's transition map.
            'status' => 'submitted',
            'request_date' => now()->toDateString(),
        ]);
    }

    public function test_raising_a_request_captures_the_requesters_name(): void
    {
        $requester = $this->user('hse', ['display_name' => 'Budi (Produksi)']);
        $this->actingAs($requester);

        $request = $this->materialRequest($requester);
        $request->submitForApproval($requester);

        $this->assertSame('Budi (Produksi)', $request->approvals()->first()->requester_name);
    }

    public function test_a_decision_captures_the_approvers_name(): void
    {
        $requester = $this->user('hse');
        $approver = $this->user(User::ROLE_SUPER_ADMIN, ['display_name' => 'Siti (Manajer)']);
        $this->actingAs($requester);

        $request = $this->materialRequest($requester);
        $request->submitForApproval($requester);

        $approval = $request->approvals()->first();
        app(ApprovalEngine::class)->decide($approval, $approver, Approval::STATUS_APPROVED);

        $this->assertSame('Siti (Manajer)', $approval->fresh()->approver_name);
    }

    /**
     * THE DEFECT, on the artefact that matters most: a printed document's
     * signature must not change when somebody is renamed.
     */
    public function test_a_documents_signature_does_not_change_when_the_signer_is_renamed(): void
    {
        $requester = $this->user('hse', ['display_name' => 'Budi (Produksi)']);
        $approver = $this->user(User::ROLE_SUPER_ADMIN, ['display_name' => 'Siti (Manajer)']);
        $this->actingAs($requester);

        $request = $this->materialRequest($requester);
        $request->submitForApproval($requester);
        app(ApprovalEngine::class)->decide(
            $request->approvals()->first(),
            $approver,
            Approval::STATUS_APPROVED
        );

        $this->assertSame('Budi (Produksi)', $request->requesterDisplayName());
        $this->assertSame('Siti (Manajer)', $request->approverDisplayName());

        $requester->update(['display_name' => 'Totally Different', 'name' => 'Totally Different']);
        $approver->update(['display_name' => 'Also Different', 'name' => 'Also Different']);

        $request = $request->fresh();

        $this->assertSame('Budi (Produksi)', $request->requesterDisplayName(), 'The signature block must keep the name that signed it.');
        $this->assertSame('Siti (Manajer)', $request->approverDisplayName(), 'The approval block must keep the name that approved it.');
    }

    /**
     * A record raised before the actor was captured still renders what it
     * renders today -- the fix omits nothing and invents nothing.
     */
    public function test_a_record_with_no_snapshot_falls_back_to_the_live_relation(): void
    {
        $requester = $this->user('hse', ['display_name' => 'Budi (Produksi)']);
        $this->actingAs($requester);

        $request = $this->materialRequest($requester);
        $request->submitForApproval($requester);

        // A pre-v2.100.0 row: the key is there, the snapshot is not.
        $request->approvals()->update(['requester_name' => null]);

        $this->assertSame('Budi (Produksi)', $request->fresh()->requesterDisplayName());
    }

    /* ==================================================================
     * 3. THE COPY RULES, WHERE IDENTITY IS WRITTEN INTO TEXT
     * ================================================================== */

    /**
     * `role` is a stored identifier. An audit entry is read by people, so
     * it must carry the label, not "super_admin".
     */
    public function test_an_audit_entry_names_a_role_in_words_not_as_an_identifier(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)
            ->post(route('settings.users.store'), [
                'name' => 'Dewi Lestari',
                'display_name' => 'Dewi (HSE)',
                'email' => 'dewi-'.uniqid().'@galangan.test',
                'password' => 'secret-pass-1',
                'role' => 'hse',
            ])
            ->assertSessionHasNoErrors();

        $description = ActivityLog::where('action', 'created')->latest()->value('description');

        $this->assertStringContainsString('Dewi (HSE)', $description, 'The display identity is what a reader recognises.');
        $this->assertStringContainsString('HSE', $description);
        $this->assertStringNotContainsString('super_admin', $description);
        $this->assertStringNotContainsString('_', $description, 'A stored identifier must not reach text a person reads.');
    }
}
