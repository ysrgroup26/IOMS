<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.141.0 -- P0 of the Operational Experience & Workflow Enhancement
 * initiative: the two defects the audit found that change no product
 * decision. See docs/kb/backlog/Operational Experience and Workflow
 * Enhancement.md.
 */
class OperationalFoundationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $hse;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();
        $this->tenant = Tenant::create(['name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $this->yard = Company::withoutGlobalScopes()->create(['name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);
        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->hse = User::create([
            'name' => 'HSE Lead', 'email' => 'hse-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE, 'department_key' => 'hse',
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    private function permit(string $status): void
    {
        PermitToWork::create([
            'ptw_number' => 'PTW-'.uniqid(), 'company_id' => $this->yard->id, 'permit_type' => 'hot_work',
            'work_description' => 'Uji.', 'start_datetime' => now(), 'end_datetime' => now()->addHours(4),
            'requested_by' => $this->hse->id, 'status' => $status,
        ]);
    }

    public function test_my_ptw_counts_a_verified_permit_as_still_awaiting_approval(): void
    {
        $this->permit(PermitToWork::STATUS_SUBMITTED);
        $this->permit(PermitToWork::STATUS_VERIFIED);
        $this->permit(PermitToWork::STATUS_ACTIVE);

        $tiles = $this->actingAs($this->hse)->get(route('my-work'))->assertOk()->viewData('page')['props']['tiles'];
        $myPtw = collect($tiles)->firstWhere('route', 'permits-to-work.mine');

        $this->assertSame('2 menunggu persetujuan, 1 aktif', $myPtw['description']);
    }

    public function test_the_release_history_is_not_sent_with_every_page(): void
    {
        $props = $this->actingAs($this->hse)->get(route('my-work'))->assertOk()->viewData('page')['props'];

        $this->assertArrayNotHasKey('history', $props['version']);
        $this->assertLessThan(20_000, strlen(json_encode($props)), 'A page response should not carry the changelog again.');
    }

    public function test_the_about_dialog_can_still_read_it_for_every_signed_in_class(): void
    {
        $this->actingAs($this->hse)->getJson(route('account.version-history'))
            ->assertOk()
            ->assertJsonPath('0.version', config('ioms.version'));

        $myWork = User::create([
            'name' => 'Pekerja', 'email' => 'mw-'.uniqid().'@g.test', 'password' => 'secret-pass-1',
            'role' => User::ROLE_HRD, 'user_type' => User::TYPE_MY_WORK,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
        $this->actingAs($myWork)->getJson(route('account.version-history'))->assertOk();
    }

    public function test_a_guest_cannot_read_it(): void
    {
        $this->getJson(route('account.version-history'))->assertUnauthorized();
    }
}
