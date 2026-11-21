<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\ManHourLog;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SafetyExecutiveService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.145.0 -- Safety Executive Dashboard (Management > HSE).
 *
 * The figures a director will quote: TRIR, LTIFR, days without lost time,
 * the near-miss ratio and the per-unit comparison. Each is checked against
 * a hand calculation, and the "no man-hours" case must give null, never 0.
 */
class SafetyExecutiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yardA;

    private Company $yardB;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create(['name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $this->yardA = Company::withoutGlobalScopes()->create(['name' => 'Yard A', 'code' => 'YA', 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $this->yardB = Company::withoutGlobalScopes()->create(['name' => 'Yard B', 'code' => 'YB', 'tenant_id' => $this->tenant->id, 'is_active' => true]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);
        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->user = User::create([
            'name' => 'Direktur', 'email' => 'dir-'.uniqid().'@g.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'company_id' => $this->yardA->id, 'is_active' => true,
        ]);
    }

    private function incident(Company $c, string $date, ?string $injury, string $category = 'injury'): Incident
    {
        return Incident::create([
            'incident_number' => 'INC-'.uniqid(), 'title' => 'Uji', 'company_id' => $c->id,
            'category' => $category, 'severity' => 'medium', 'injury_severity' => $injury,
            'status' => Incident::STATUS_REPORTED, 'incident_date' => $date,
            'reported_by' => $this->user->id, 'location' => 'Dek', 'description' => 'Uji',
        ]);
    }

    /** 500 hours per row (decimal(5,2) column), one employee per row, on the first day of this month. */
    private function hours(Company $c, int $rows): void
    {
        for ($i = 0; $i < $rows; $i++) {
            $e = Employee::create(['employee_id' => 'EMP-'.uniqid(), 'full_name' => 'Pekerja', 'company_id' => $c->id, 'status' => 'active']);
            ManHourLog::create([
                'company_id' => $c->id, 'employee_id' => $e->id, 'work_date' => now()->startOfMonth()->toDateString(),
                'regular_hours' => 500, 'overtime_hours' => 0, 'recorded_by' => $this->user->id,
            ]);
        }
    }

    private function overview(?array $ids = null): array
    {
        return app(SafetyExecutiveService::class)->overview($ids ?? [$this->yardA->id, $this->yardB->id], now()->startOfMonth());
    }

    public function test_rates_match_a_hand_calculation(): void
    {
        $this->hours($this->yardA, 20);                                     // 10.000 jam
        $today = now()->toDateString();
        $this->incident($this->yardA, $today, 'medical_treatment');          // recordable
        $this->incident($this->yardA, $today, 'lost_time');                  // recordable + lost time
        $this->incident($this->yardA, $today, 'first_aid');                  // neither

        $r = $this->overview()['rates'];

        $this->assertTrue($r['available']);
        $this->assertSame(10000.0, (float) $r['man_hours']);
        $this->assertSame(2, $r['recordable']);
        $this->assertSame(1, $r['lost_time']);
        $this->assertEquals(40.0, $r['trir']);                              // 2 × 200.000 ÷ 10.000
        $this->assertEquals(100.0, $r['ltifr']);                            // 1 × 1.000.000 ÷ 10.000
    }

    public function test_no_man_hours_means_no_rate_not_zero(): void
    {
        $this->incident($this->yardA, now()->toDateString(), 'lost_time');

        $r = $this->overview()['rates'];

        $this->assertFalse($r['available']);
        $this->assertNull($r['trir']);
        $this->assertNull($r['ltifr']);
        $this->assertNotNull($r['unavailable_reason']);
    }

    public function test_days_without_lost_time_counts_from_the_last_lti(): void
    {
        $this->assertFalse($this->overview()['lti']['recorded']);

        $this->incident($this->yardB, now()->subDays(40)->toDateString(), 'lost_time');
        $this->incident($this->yardA, now()->subDays(10)->toDateString(), 'fatality');
        $this->incident($this->yardA, now()->subDays(2)->toDateString(), 'first_aid');   // not lost time

        $lti = $this->overview()['lti'];
        $this->assertTrue($lti['recorded']);
        $this->assertSame(10, $lti['days']);
        $this->assertSame('Yard A', $lti['unit']);
    }

    public function test_near_miss_ratio_trend_and_pyramid(): void
    {
        $today = now()->toDateString();
        $this->incident($this->yardA, $today, 'first_aid');
        foreach (range(1, 5) as $i) {
            $this->incident($this->yardA, $today, null, 'near_miss');
        }

        $o = $this->overview();
        $this->assertSame(5, $o['near_miss']['count']);
        $this->assertEquals(5.0, $o['near_miss']['ratio']);
        $this->assertSame(1, end($o['trend']['incidents']));
        $this->assertSame(5, end($o['trend']['near_miss']));
        $this->assertCount(12, $o['trend']['labels']);
        $this->assertSame(5, collect($o['pyramid'])->firstWhere('key', 'near_miss')['value']);
        $this->assertSame(1, collect($o['pyramid'])->firstWhere('key', 'first_aid')['value']);
    }

    public function test_units_are_compared_side_by_side(): void
    {
        $this->hours($this->yardA, 4);                                      // 2.000 jam
        $lti = $this->incident($this->yardB, now()->toDateString(), 'lost_time');
        CorrectiveAction::create([
            'company_id' => $this->yardB->id, 'action' => 'Pasang pagar', 'status' => CorrectiveAction::STATUS_OPEN,
            'due_date' => now()->subDays(3)->toDateString(), 'source_type' => 'incident', 'source_id' => $lti->id,
        ]);

        $units = collect($this->overview()['units'])->keyBy('name');
        $this->assertSame(['Yard A', 'Yard B'], $units->keys()->all());
        $this->assertNull($units['Yard B']['trir']);                         // Yard B logged no hours
        $this->assertSame(1, $units['Yard B']['lost_time']);
        $this->assertSame(1, $units['Yard B']['capa_overdue']);
        $this->assertSame(0, $units['Yard A']['incidents']);

        $this->assertSame([], $this->overview([$this->yardA->id])['units']);   // one unit → no table
    }

    public function test_the_management_page_carries_the_executive_props(): void
    {
        $response = $this->actingAs($this->user)->get(route('management.hse', ['month' => 'bukan-bulan', 'company_id' => 999999]));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(now()->format('Y-m'), $props['executive']['period']['month']);   // bad month → current month
        $this->assertSame(now()->format('Y-m'), $props['executiveFilters']['month']);
        $this->assertCount(2, $props['executiveFilters']['companies']);
    }
}
