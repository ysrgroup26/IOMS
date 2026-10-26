<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WasteMovement;
use App\Models\WasteRecord;
use App\Models\WasteStorageLocation;
use App\Models\WasteType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * v2.134.0 (ADR 077) -- the Waste Management workspace.
 *
 * Pins: every figure is a count of real rows and each one filters the list;
 * storage DURATION (per waste type) and storage CAPACITY (per TPS) are
 * separate and capacity is compared only where units agree; missing data is
 * reported as missing data; the new storage action is HSE's, tenant-scoped,
 * and moves a generated record into storage through the existing guard.
 */
class WasteWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(string $slug): array
    {
        $tenant = Tenant::create(['name' => 'T-'.$slug, 'slug' => $slug]);
        $company = Company::withoutGlobalScopes()->create(['name' => 'C-'.$slug, 'tenant_id' => $tenant->id]);
        $hse = User::create(['name' => 'HSE '.$slug, 'email' => "hse@{$slug}.test", 'password' => bcrypt('x'), 'role' => 'hse', 'tenant_id' => $tenant->id, 'is_active' => true]);
        $hrd = User::create(['name' => 'HRD '.$slug, 'email' => "hrd@{$slug}.test", 'password' => bcrypt('x'), 'role' => 'hrd', 'tenant_id' => $tenant->id, 'is_active' => true]);

        $oil = WasteType::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Oli bekas', 'code' => 'used_oil', 'category' => 'b3', 'waste_code' => 'B105d', 'unit' => 'liter', 'storage_limit_days' => 90, 'is_active' => true]);
        $rags = WasteType::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Majun terkontaminasi', 'code' => 'rags', 'category' => 'b3', 'waste_code' => null, 'unit' => 'kg', 'storage_limit_days' => 30, 'is_active' => true]);
        $scrap = WasteType::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Scrap besi', 'code' => 'scrap', 'category' => 'non_b3', 'unit' => 'kg', 'storage_limit_days' => null, 'is_active' => true]);
        $tps = WasteStorageLocation::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'TPS LB3 Dok 1', 'code' => 'TPS-01', 'capacity' => 1000, 'capacity_unit' => 'liter', 'status' => 'active']);
        $yard = WasteStorageLocation::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Scrap yard', 'code' => 'YARD', 'capacity' => 500, 'capacity_unit' => 'kg', 'status' => 'active']);
        $closedTps = WasteStorageLocation::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Old TPS', 'code' => 'OLD', 'status' => 'inactive']);
        $vendor = Vendor::withoutGlobalScopes()->create(['company_id' => $company->id, 'vendor_code' => 'V-'.$slug, 'name' => 'PT Angkut B3', 'is_active' => true, 'is_waste_vendor' => true]);

        return compact('tenant', 'company', 'hse', 'hrd', 'oil', 'rags', 'scrap', 'tps', 'yard', 'closedTps', 'vendor');
    }

    private function record(array $t, array $attrs): WasteRecord
    {
        static $n = 0;
        $n++;

        return WasteRecord::withoutGlobalScopes()->create([
            'record_number' => 'WR-'.$n, 'company_id' => $t['company']->id, 'waste_type_id' => $t['oil']->id,
            'quantity' => 100, 'unit' => 'liter', 'generated_date' => '2026-10-01', 'status' => 'stored', ...$attrs,
        ]);
    }

    private function seedYard(array $t): array
    {
        return [
            // B3, in TPS-01, 100 days stored against a 90-day limit -> exceeded.
            'overdue' => $this->record($t, ['storage_location_id' => $t['tps']->id, 'received_date' => '2026-07-18', 'generated_date' => '2026-07-18', 'quantity' => 600]),
            // B3, in TPS-01, 85 days -> within the last 7 days of 90.
            'approaching' => $this->record($t, ['storage_location_id' => $t['tps']->id, 'received_date' => '2026-08-02', 'generated_date' => '2026-08-02', 'quantity' => 300]),
            // B3 rags (no waste code), generated only, no TPS yet.
            'generated' => $this->record($t, ['waste_type_id' => $t['rags']->id, 'status' => 'generated', 'unit' => 'kg', 'quantity' => 20, 'generated_date' => '2026-10-20']),
            // Non-B3 scrap in the yard, no duration limit.
            'scrap' => $this->record($t, ['waste_type_id' => $t['scrap']->id, 'storage_location_id' => $t['yard']->id, 'unit' => 'kg', 'quantity' => 450, 'received_date' => '2026-10-10']),
            // Picked up, B3, no manifest, no documents.
            'transit' => $this->record($t, ['status' => 'in_transit', 'storage_location_id' => $t['tps']->id, 'generated_date' => '2026-09-01']),
            // Disposed, B3.
            'disposed' => $this->record($t, ['status' => 'disposed', 'generated_date' => '2026-08-15']),
        ];
    }

    public function test_the_summary_and_lifecycle_are_counts_of_real_rows(): void
    {
        $t = $this->tenant('sum');
        $r = $this->seedYard($t);
        WasteMovement::withoutGlobalScopes()->create(['waste_record_id' => $r['transit']->id, 'company_id' => $t['company']->id, 'vendor_id' => $t['vendor']->id, 'pickup_date' => '2026-10-20', 'status' => 'picked_up']);

        $this->actingAs($t['hse'])->get(route('waste.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hse/WasteManagement/Dashboard')
                ->where('summary.total', 6)
                ->where('summary.b3_stored', 3)       // overdue, approaching, generated (still on site)
                ->where('summary.non_b3_stored', 1)
                ->where('summary.pickup_transit', 1)
                ->where('summary.done', 1)
                ->where('summary.overdue', 1)
                ->where('summary.approaching', 1)
                ->where('lifecycle', fn ($l) => collect($l)->pluck('count', 'status')->all() === [
                    'generated' => 1, 'stored' => 3, 'scheduled_pickup' => 0, 'in_transit' => 1, 'disposed' => 1, 'closed' => 0,
                ])
                ->where('records.data.0.record_number', $r['overdue']->record_number) // worst first
                ->where('can.manage', true));
    }

    public function test_every_summary_view_and_filter_narrows_the_list(): void
    {
        $t = $this->tenant('filters');
        $r = $this->seedYard($t);
        $get = fn (array $q) => $this->actingAs($t['hse'])->get(route('waste.dashboard', $q));
        $numbers = fn (array $q) => $get($q)->viewData('page')['props']['records']['data'];
        $ids = fn (array $q) => collect($numbers($q))->pluck('record_number')->sort()->values()->all();
        $n = fn (string $k) => $r[$k]->record_number;

        $this->assertSame([$n('overdue')], $ids(['view' => 'overdue']));
        $this->assertSame([$n('approaching')], $ids(['view' => 'approaching']));
        $this->assertSame([$n('scrap')], $ids(['view' => 'non_b3_stored']));
        $this->assertSame([$n('disposed')], $ids(['view' => 'done']));
        $this->assertSame([$n('generated')], $ids(['status' => 'generated']));
        $this->assertSame([$n('scrap')], $ids(['category' => 'non_b3']));
        $this->assertSame([$n('generated')], $ids(['waste_type_id' => (string) $t['rags']->id]));
        $this->assertSame(collect([$n('generated'), $n('disposed')])->sort()->values()->all(), $ids(['storage_location_id' => 'none']));
        $this->assertSame([$n('scrap')], $ids(['storage_location_id' => (string) $t['yard']->id]));
        $this->assertSame([$n('generated')], $ids(['from' => '2026-10-15', 'to' => '2026-10-31']));
        $this->assertSame([$n('generated')], $ids(['q' => 'majun']));
        $this->assertSame(6, count($numbers(['pickup' => 'none'])));
        // Quick-action views list what each action applies to.
        $this->assertSame(collect([$n('overdue'), $n('approaching'), $n('generated'), $n('scrap')])->sort()->values()->all(), $ids(['view' => 'onsite']));
        // Junk is ignored, not an error.
        $get(['view' => 'nonsense', 'status' => 'x', 'from' => 'yesterday'])->assertOk();
    }

    public function test_duration_and_capacity_are_separate_and_capacity_needs_matching_units(): void
    {
        $t = $this->tenant('cap');
        $this->seedYard($t);
        // A kg record placed in the liter TPS makes its units mixed.
        $this->actingAs($t['hse'])->get(route('waste.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('storage', function ($storage) {
                    $s = collect($storage)->keyBy('name');

                    // TPS-01 holds 600 + 300 liter on site (the in-transit record has left): 90% of 1000.
                    return $s['TPS LB3 Dok 1']['utilization'] === 90
                        && $s['TPS LB3 Dok 1']['capacity_state'] === 'filling'
                        && $s['TPS LB3 Dok 1']['oldest']['storage_state'] === 'overdue'
                        && $s['Scrap yard']['capacity_state'] === 'filling' // 450 of 500 kg
                        && $s['No storage location']['records'] === 1;
                })
                ->where('capacityWarningPercent', 80));

        $this->record($t, ['storage_location_id' => $t['tps']->id, 'unit' => 'kg', 'quantity' => 5]);
        $this->actingAs($t['hse'])->get(route('waste.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('storage', fn ($s) => collect($s)->firstWhere('name', 'TPS LB3 Dok 1')['capacity_state'] === 'mixed_units'
                && collect($s)->firstWhere('name', 'TPS LB3 Dok 1')['utilization'] === null));
    }

    public function test_missing_data_is_reported_as_missing_data(): void
    {
        $t = $this->tenant('gaps');
        $r = $this->seedYard($t);
        $page = $this->actingAs($t['hse'])->get(route('waste.dashboard', ['view' => 'incomplete']))->viewData('page')['props'];
        $gaps = collect($page['records']['data'])->pluck('gaps', 'record_number');

        $this->assertContains('no_waste_code', $gaps[$r['generated']->record_number]);
        $this->assertEqualsCanonicalizing(['no_vendor', 'no_manifest', 'no_handover_document'], $gaps[$r['transit']->record_number]);
        $this->assertContains('no_handover_document', $gaps[$r['disposed']->record_number]);
        $this->assertArrayNotHasKey($r['scrap']->record_number, $gaps->all());
        $this->assertSame($gaps->count(), $page['summary']['incomplete']);
    }

    public function test_hse_assigns_storage_and_a_generated_record_becomes_stored(): void
    {
        $t = $this->tenant('assign');
        $rec = $this->record($t, ['waste_type_id' => $t['rags']->id, 'status' => 'generated', 'unit' => 'kg']);

        $this->actingAs($t['hse'])->post(route('waste-records.storage', $rec), ['storage_location_id' => $t['tps']->id, 'received_date' => '2026-10-25'])
            ->assertSessionHasNoErrors();
        $fresh = $rec->fresh();
        $this->assertSame('stored', $fresh->status);
        $this->assertSame($t['tps']->id, $fresh->storage_location_id);
        $this->assertSame('2026-10-25', $fresh->received_date->toDateString());

        // Moving to another TPS keeps the original received date (storage age is not reset).
        $this->actingAs($t['hse'])->post(route('waste-records.storage', $rec), ['storage_location_id' => $t['yard']->id])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-25', $rec->fresh()->received_date->toDateString());
        $this->assertSame($t['yard']->id, $rec->fresh()->storage_location_id);

        // An inactive TPS is refused.
        $this->actingAs($t['hse'])->post(route('waste-records.storage', $rec), ['storage_location_id' => $t['closedTps']->id])->assertSessionHasErrors('storage_location_id');
    }

    public function test_storage_is_hse_only_on_site_only_and_tenant_scoped(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $rec = $this->record($a, ['status' => 'generated']);
        $gone = $this->record($a, ['status' => 'disposed']);

        $this->actingAs($a['hrd'])->post(route('waste-records.storage', $rec), ['storage_location_id' => $a['tps']->id])->assertForbidden();
        $this->actingAs($a['hse'])->post(route('waste-records.storage', $gone), ['storage_location_id' => $a['tps']->id])->assertStatus(422);
        $this->actingAs($b['hse'])->post(route('waste-records.storage', $rec), ['storage_location_id' => $b['tps']->id])->assertNotFound();
        // Tenant A's record cannot be put in tenant B's TPS by tenant A either.
        $this->actingAs($a['hse'])->post(route('waste-records.storage', $rec), ['storage_location_id' => $b['tps']->id])->assertSessionHasErrors('storage_location_id');
        $this->assertSame('generated', $rec->fresh()->status);
    }

    public function test_reading_is_open_to_every_role_and_isolated_per_tenant(): void
    {
        $a = $this->tenant('read-a');
        $b = $this->tenant('read-b');
        $this->seedYard($a);

        $this->actingAs($a['hrd'])->get(route('waste.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 6)->where('can.manage', false));
        $this->actingAs($b['hse'])->get(route('waste.dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('summary.total', 0)->where('records.total', 0)->where('storage', fn ($s) => collect($s)->sum('records') === 0));
    }

    public function test_the_record_page_offers_its_tps_choices_and_the_quick_action_deep_link(): void
    {
        $t = $this->tenant('show');
        $rec = $this->record($t, ['status' => 'generated']);

        $this->actingAs($t['hse'])->get(route('waste-records.show', ['wasteRecord' => $rec->id, 'action' => 'storage']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Hse/WasteManagement/Show')
                ->where('storageLocations', fn ($l) => collect($l)->pluck('name')->sort()->values()->all() === ['Scrap yard', 'TPS LB3 Dok 1']));
    }
}
