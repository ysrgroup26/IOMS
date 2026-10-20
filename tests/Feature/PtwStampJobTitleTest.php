<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\PtwWorkflowSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PtwWorkflowService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PpeTypeSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.128.0 -- THE JOB TITLE ON A SEAL BELONGS TO THE COMPANY.
 *
 * A VERIFIED seal printed `HSE` and an APPROVED seal printed `MANAGER`.
 * Those are IOMS system roles: they decide who may press the button, they
 * are not anybody's job, and no customer words a job that way.
 *
 * These tests pin the whole chain and, as importantly, its END: when no
 * configured title and no position exist, the seal prints NO title rather
 * than falling back to a role. The point is not that "HSE Officer" appears
 * -- a hardcoded string would satisfy that -- but that whatever appears
 * came from the company's own configuration and was frozen at the moment of
 * signing.
 *
 * @see docs/ADR/068-a-job-title-is-configured-and-a-role-is-not-one.md
 */
class PtwStampJobTitleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $requester;

    private User $verifier;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(PpeTypeSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'title-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit', 'code' => 'TU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());
        Department::create(['company_id' => $this->company->id, 'name' => 'HSE']);

        $this->requester = $this->user('req', User::ROLE_MANAGER);
        $this->requester->update(['ptw_access' => true]);
        $this->verifier = $this->user('ver', User::ROLE_HSE);
        $this->approver = $this->user('app', User::ROLE_MANAGER);

        $this->route(verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER]);
    }

    private function user(string $slug, string $role): User
    {
        return User::create([
            'name' => ucfirst($slug), 'email' => $slug.'-'.uniqid().'@title.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);
    }

    private function route(array $verify = [], array $approve = [], ?string $verifyTitle = null, ?string $approveTitle = null): void
    {
        PtwWorkflowSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'verify_roles' => $verify, 'verify_user_ids' => [], 'verify_title' => $verifyTitle,
                'approve_roles' => $approve, 'approve_user_ids' => [], 'approve_title' => $approveTitle,
            ],
        );
    }

    /** Give an account a real position in the company's own structure. */
    private function givePosition(User $user, string $title): void
    {
        $position = Position::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => $title, 'is_active' => true,
        ]);

        $employee = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-'.uniqid(), 'full_name' => $user->name,
            'company_id' => $this->company->id, 'position_id' => $position->id, 'status' => 'active',
        ]);

        // Not fillable, deliberately (see User::$fillable).
        $user->forceFill(['employee_id' => $employee->id])->save();
    }

    private function permit(string $status = PermitToWork::STATUS_SUBMITTED): PermitToWork
    {
        return PermitToWork::withoutGlobalScopes()->create([
            'ptw_number' => 'PTW-T-'.uniqid(),
            'company_id' => $this->company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Welding on the main deck.',
            'start_datetime' => Carbon::parse('2026-11-01 01:00:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-01 09:00:00', 'UTC'),
            'requested_by' => $this->requester->id,
            'status' => $status,
        ]);
    }

    private function transition(User $actor, PermitToWork $permit, string $status)
    {
        return $this->actingAs($actor)
            ->post('/permits-to-work/'.$permit->id.'/transition', ['status' => $status]);
    }

    /** The props the stamps and the document views are drawn from. */
    private function props(PermitToWork $permit): array
    {
        $response = $this->actingAs($this->approver)->get('/permits-to-work/'.$permit->id);
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    /*
    |--------------------------------------------------------------------------
    | The system role is not a job title, and is never printed as one
    |--------------------------------------------------------------------------
    */

    public function test_no_configured_title_and_no_position_prints_no_title_at_all(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $props = $this->props($permit->fresh());

        $this->assertNull($props['verification']['role'],
            'With nothing configured the seal must print no title -- never the IOMS role.');
    }

    public function test_the_role_label_never_leaks_into_either_seal(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit->fresh(), PermitToWork::STATUS_APPROVED)->assertRedirect();

        $props = $this->props($permit->fresh());

        foreach (['verification', 'authorization'] as $key) {
            $this->assertNotSame('HSE', $props[$key]['role'] ?? null);
            $this->assertNotSame('Manager', $props[$key]['role'] ?? null);
            $this->assertNotSame('Super Admin', $props[$key]['role'] ?? null);
        }
    }

    /**
     * The seal is an SVG the server draws; a role must not reach it as a
     * "kind" label either, which is how `HSE` was printed on a permit whose
     * signer had no position.
     */
    public function test_the_verified_seal_carries_no_system_role_as_its_function_label(): void
    {
        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $svg = $this->props($permit->fresh())['stamps']['verification'];

        $this->assertIsString($svg);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $svg);

        $decoded = base64_decode(substr($svg, strlen('data:image/svg+xml;base64,')), true);

        $this->assertIsString($decoded);
        $this->assertStringContainsString('VERIFIED', $this->drawnText($decoded),
            'Sanity: the seal really does draw its own word, so the absence below means something.');

        // Tracked text is drawn one glyph per <text>, so a role would appear
        // as the letters of its label in the drawn string -- which is
        // exactly how `HSE` reached a seal whose signer had no position.
        $this->assertStringNotContainsString('HSE', str_replace('VERIFIED', '', $this->drawnText($decoded)),
            'No IOMS role may be drawn into the seal.');
    }

    /** Every character the stamp actually draws, in order. */
    private function drawnText(string $svg): string
    {
        preg_match_all('/<text[^>]*>(.*?)<\/text>/s', $svg, $matches);

        return html_entity_decode(implode('', $matches[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /*
    |--------------------------------------------------------------------------
    | What IS printed comes from the company's configuration
    |--------------------------------------------------------------------------
    */

    public function test_each_stage_prints_the_job_title_configured_for_it(): void
    {
        $this->route(
            verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER],
            verifyTitle: 'HSE Officer', approveTitle: 'Manager Shipyard',
        );

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit->fresh(), PermitToWork::STATUS_APPROVED)->assertRedirect();

        $props = $this->props($permit->fresh());

        $this->assertSame('HSE Officer', $props['verification']['role']);
        $this->assertSame('Manager Shipyard', $props['authorization']['role']);
    }

    /**
     * A position is a configured job title too, and it is the more specific
     * one: it says what THIS person is, not what whoever signs this stage is
     * usually called.
     */
    public function test_the_signers_own_position_outranks_the_stage_title(): void
    {
        $this->route(verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER], verifyTitle: 'HSE Officer');
        $this->givePosition($this->verifier, 'SPV HSE');

        $permit = $this->permit();
        $this->transition($this->verifier->fresh(), $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->assertSame('SPV HSE', $this->props($permit->fresh())['verification']['role']);
    }

    /*
    |--------------------------------------------------------------------------
    | And it is frozen at the moment of signing
    |--------------------------------------------------------------------------
    */

    public function test_the_title_is_snapshotted_so_later_configuration_cannot_rewrite_it(): void
    {
        $this->route(verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER], verifyTitle: 'HSE Officer');

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        // The company reorganises and re-words the stage afterwards.
        $this->route(verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER], verifyTitle: 'Safety Coordinator');

        $this->assertSame('HSE Officer', $this->props($permit->fresh())['verification']['role'],
            'A permit already signed must keep the title it was signed under.');
    }

    public function test_the_snapshot_is_written_on_the_signing_activity_row(): void
    {
        $this->route(verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER], approveTitle: 'Manager Shipyard');

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit->fresh(), PermitToWork::STATUS_APPROVED)->assertRedirect();

        $row = ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permit->id)
            ->where('action', PermitToWork::STATUS_APPROVED)
            ->latest()->first();

        $this->assertSame('Manager Shipyard', $row->meta['stage_title'] ?? null);
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration, and the boundary around it
    |--------------------------------------------------------------------------
    */

    public function test_an_administrator_can_set_both_titles_from_settings(): void
    {
        $admin = $this->user('admin', User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->post('/settings/ptw-workflow', [
            'company_id' => $this->company->id,
            'verify_roles' => [User::ROLE_HSE],
            'approve_roles' => [User::ROLE_MANAGER],
            'verify_title' => '  HSE Officer  ',
            'approve_title' => 'Manager Shipyard',
        ])->assertRedirect();

        $row = PtwWorkflowSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->sole();

        $this->assertSame('HSE Officer', $row->verify_title, 'The stored title is trimmed.');
        $this->assertSame('Manager Shipyard', $row->approve_title);
    }

    public function test_an_empty_title_is_stored_as_nothing_rather_than_an_empty_string(): void
    {
        $admin = $this->user('admin2', User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->post('/settings/ptw-workflow', [
            'company_id' => $this->company->id,
            'verify_roles' => [User::ROLE_HSE],
            'approve_roles' => [User::ROLE_MANAGER],
            'verify_title' => '   ',
        ])->assertRedirect();

        $row = PtwWorkflowSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->sole();

        $this->assertNull($row->verify_title);
        $this->assertNull(app(PtwWorkflowService::class)->stageTitle($this->company->id, PtwWorkflowService::STAGE_VERIFY));
    }

    /** A title is a label. It cannot widen or narrow a single permission. */
    public function test_configuring_a_title_grants_nobody_anything(): void
    {
        $this->route(verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER], verifyTitle: 'HSE Officer');

        $permit = $this->permit();

        // The requester holds neither gate, title or no title.
        $this->transition($this->requester, $permit, PermitToWork::STATUS_VERIFIED)->assertForbidden();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->fresh()->status);
    }
}
