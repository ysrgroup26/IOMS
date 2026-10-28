<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\HseEquipmentType;
use App\Models\SafetyEquipment;
use App\Models\SafetyEquipmentInspection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * v2.133.0 (ADR 074) -- the Safety Equipment Register as its own page.
 *
 * Pins: a unit's standing is derived from the dates its TYPE tracks and its
 * latest inspection, never invented; the overview counts are counts of the
 * register; IDs are generated per company and per prefix; reading is open
 * to every role while writing is HSE's; and one tenant can neither see nor
 * touch another's units.
 */
class SafetyEquipmentRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-25 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(string $slug, string $role = 'hse'): array
    {
        $tenant = Tenant::create(['name' => 'T-'.$slug, 'slug' => $slug]);
        $company = Company::withoutGlobalScopes()->create(['name' => 'C-'.$slug, 'tenant_id' => $tenant->id]);
        $user = User::create([
            'name' => ucfirst($role).' '.$slug, 'email' => "{$role}@{$slug}.test", 'password' => bcrypt('x'),
            'role' => $role, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
        $apar = HseEquipmentType::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'name' => 'Fire Extinguisher (APAR)', 'code' => 'apar', 'code_prefix' => 'APAR',
            'is_active' => true, 'tracks_inspection' => true, 'tracks_expiry' => true, 'inspection_interval_months' => 6,
        ]);
        $detector = HseEquipmentType::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'name' => 'Gas Detector', 'code' => 'gas_detector',
            'is_active' => true, 'tracks_inspection' => false, 'tracks_calibration' => true,
        ]);

        return compact('tenant', 'company', 'user', 'apar', 'detector');
    }

    private function unit(array $t, array $attributes): SafetyEquipment
    {
        $unit = SafetyEquipment::withoutGlobalScopes()->create([
            'company_id' => $t['company']->id, 'name' => 'Unit', 'type' => $t['apar']->code,
            'equipment_type_id' => $t['apar']->id, 'status' => 'active', ...$attributes,
        ]);

        // Outside a request there is no signed-in tenant for the company
        // scope to resolve, so the relations are set from the rows directly.
        return $unit->setRelation('equipmentType', HseEquipmentType::withoutGlobalScopes()->find($unit->equipment_type_id))
            ->setRelation('latestInspection', null);
    }

    public function test_standing_is_derived_from_the_dates_the_type_tracks(): void
    {
        $t = $this->tenant('derive');

        $ok = $this->unit($t, ['next_inspection_due' => '2027-03-01', 'expiry_date' => '2028-01-01']);
        $soon = $this->unit($t, ['next_inspection_due' => '2026-11-10', 'expiry_date' => '2028-01-01']);
        $late = $this->unit($t, ['next_inspection_due' => '2026-10-01', 'expiry_date' => '2028-01-01']);
        $expired = $this->unit($t, ['next_inspection_due' => '2027-03-01', 'expiry_date' => '2026-09-30']);
        $unscheduled = $this->unit($t, ['expiry_date' => '2028-01-01']);
        $off = $this->unit($t, ['status' => 'out_of_service', 'next_inspection_due' => '2020-01-01']);
        // A gas detector does not track inspection: an inspection date on
        // it is not a lifecycle that applies, so it cannot make it overdue.
        $detector = $this->unit($t, ['equipment_type_id' => $t['detector']->id, 'type' => 'gas_detector',
            'next_inspection_due' => '2020-01-01', 'next_calibration_due' => '2027-01-01']);

        $this->assertSame('compliant', $ok->complianceState());
        $this->assertSame('due_soon', $soon->complianceState());
        $this->assertSame('overdue', $late->complianceState());
        $this->assertSame('expired', $expired->complianceState());
        $this->assertSame('not_scheduled', $unscheduled->complianceState());
        $this->assertSame('out_of_service', $off->complianceState());
        $this->assertSame('compliant', $detector->complianceState());
        $this->assertSame(['calibration'], array_column($detector->lifecycles(), 'key'));
    }

    public function test_a_failed_latest_inspection_means_action_required_until_a_pass_supersedes_it(): void
    {
        $t = $this->tenant('action');
        $unit = $this->unit($t, ['next_inspection_due' => '2027-03-01', 'expiry_date' => '2028-01-01']);

        $this->actingAs($t['user'])->post(route('safety-equipment.inspections.store', $unit), [
            'inspection_date' => '2026-10-20', 'condition' => 'poor', 'result' => 'needs_action', 'findings' => 'Pressure gauge in red', 'risk_level' => 'high',
        ])->assertSessionHasNoErrors();
        $this->assertSame('action_required', $unit->fresh()->complianceState());

        $this->actingAs($t['user'])->post(route('safety-equipment.inspections.store', $unit), [
            'inspection_date' => '2026-10-24', 'condition' => 'good', 'result' => 'pass',
        ])->assertSessionHasNoErrors();
        $fresh = $unit->fresh();
        $this->assertSame('compliant', $fresh->complianceState());
        // The type's six-month interval proposed the next date.
        $this->assertSame('2027-04-24', $fresh->next_inspection_due->toDateString());
    }

    public function test_a_fail_needs_findings_and_a_back_dated_inspection_does_not_move_current_dates(): void
    {
        $t = $this->tenant('rules');
        $unit = $this->unit($t, ['last_inspection_date' => '2026-10-01', 'next_inspection_due' => '2027-04-01']);

        $this->actingAs($t['user'])->post(route('safety-equipment.inspections.store', $unit), [
            'inspection_date' => '2026-10-20', 'condition' => 'damaged', 'result' => 'fail',
        ])->assertSessionHasErrors('findings');

        $this->actingAs($t['user'])->post(route('safety-equipment.inspections.store', $unit), [
            'inspection_date' => '2026-05-01', 'condition' => 'good', 'result' => 'pass', 'next_inspection_due' => '2026-11-01',
        ])->assertSessionHasNoErrors();

        $fresh = $unit->fresh();
        $this->assertSame('2026-10-01', $fresh->last_inspection_date->toDateString());
        $this->assertSame('2027-04-01', $fresh->next_inspection_due->toDateString());
        $this->assertSame(1, SafetyEquipmentInspection::withoutGlobalScopes()->count());
    }

    public function test_ids_are_generated_per_company_and_prefix_and_never_renumbered(): void
    {
        $t = $this->tenant('codes');
        $payload = fn (array $extra = []) => [
            'company_id' => $t['company']->id, 'name' => 'APAR', 'equipment_type_id' => $t['apar']->id, 'status' => 'active', ...$extra,
        ];

        $this->actingAs($t['user'])->post(route('safety-equipment.store'), $payload())->assertSessionHasNoErrors();
        $this->actingAs($t['user'])->post(route('safety-equipment.store'), $payload(['equipment_code' => 'apar-00010']))->assertSessionHasNoErrors();
        $this->actingAs($t['user'])->post(route('safety-equipment.store'), $payload())->assertSessionHasNoErrors();
        $this->actingAs($t['user'])->post(route('safety-equipment.store'), $payload(['equipment_type_id' => $t['detector']->id]))->assertSessionHasNoErrors();
        // A duplicate typed by hand is refused, inside one company -- also when
        // only the padding differs (v2.135.0: APAR-1 is APAR-01's number).
        $this->actingAs($t['user'])->post(route('safety-equipment.store'), $payload(['equipment_code' => 'APAR-01']))->assertSessionHasErrors('equipment_code');
        $this->actingAs($t['user'])->post(route('safety-equipment.store'), $payload(['equipment_code' => 'APAR-1']))->assertSessionHasErrors('equipment_code');
        $this->actingAs($t['user'])->post(route('safety-equipment.store'), $payload(['equipment_code' => 'apar-010']))->assertSessionHasErrors('equipment_code');

        // Two-digit numbers (v2.135.0); numbering continues from the highest
        // number in either form, so a typed APAR-00010 is followed by APAR-11.
        $codes = SafetyEquipment::withoutGlobalScopes()->orderBy('id')->pluck('equipment_code')->all();
        $this->assertSame(['APAR-01', 'APAR-00010', 'APAR-11', 'EQ-01'], $codes);

        // A legacy unit with no ID gets one on its next save; one with an ID keeps it.
        $legacy = $this->unit($t, ['equipment_code' => null, 'name' => 'Old']);
        $this->actingAs($t['user'])->put(route('safety-equipment.update', $legacy), $payload(['name' => 'Old']))->assertSessionHasNoErrors();
        $this->assertSame('APAR-12', $legacy->fresh()->equipment_code);
        $this->actingAs($t['user'])->put(route('safety-equipment.update', $legacy), $payload(['name' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertSame('APAR-12', $legacy->fresh()->equipment_code);
    }

    public function test_a_short_code_finds_a_unit_still_labelled_with_the_long_one(): void
    {
        $t = $this->tenant('short-search');
        $old = $this->unit($t, ['equipment_code' => 'APAR-00007', 'name' => 'Lama']);
        $this->unit($t, ['equipment_code' => 'APAR-17', 'name' => 'Lain']);

        $this->actingAs($t['user'])->get(route('safety-equipment.index', ['q' => 'apar-07']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('equipment.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$old->id]));
    }

    public function test_shortening_existing_codes_is_audited_first_and_refused_on_a_clash(): void
    {
        $t = $this->tenant('shorten');
        $a = $this->unit($t, ['equipment_code' => 'APAR-00001', 'name' => 'A']);
        $b = $this->unit($t, ['equipment_code' => 'APAR-00012', 'name' => 'B']);
        $c = $this->unit($t, ['equipment_code' => 'FE/2024/7', 'name' => 'Hand-typed']);

        // Read-only by default.
        $this->artisan('ioms:equipment-codes')->assertSuccessful();
        $this->assertSame('APAR-00001', $a->fresh()->equipment_code);

        $this->artisan('ioms:equipment-codes', ['--apply' => true])->assertSuccessful();
        $this->assertSame('APAR-01', $a->fresh()->equipment_code);
        $this->assertSame('APAR-12', $b->fresh()->equipment_code);
        $this->assertSame('FE/2024/7', $c->fresh()->equipment_code);
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('subject_id', $a->id)->where('description', 'like', '%APAR-00001 to APAR-01%')->exists());

        // A number used twice blocks --apply entirely; nothing changes.
        $d = $this->unit($t, ['equipment_code' => 'APAR-00005', 'name' => 'D']);
        $this->unit($t, ['equipment_code' => 'APAR-5', 'name' => 'E']);
        $this->artisan('ioms:equipment-codes', ['--apply' => true])->assertFailed();
        $this->assertSame('APAR-00005', $d->fresh()->equipment_code);
    }

    public function test_the_overview_counts_are_counts_of_the_register_and_filters_narrow_it(): void
    {
        $t = $this->tenant('overview');
        $this->unit($t, ['name' => 'Fine', 'location' => 'Workshop', 'next_inspection_due' => '2027-03-01', 'expiry_date' => '2028-01-01']);
        $this->unit($t, ['name' => 'Late', 'location' => 'Yard', 'next_inspection_due' => '2026-10-01', 'expiry_date' => '2028-01-01']);
        $this->unit($t, ['name' => 'Soon', 'next_inspection_due' => '2026-11-01', 'expiry_date' => '2028-01-01']);
        $this->unit($t, ['name' => 'Retired', 'status' => 'out_of_service']);

        $this->actingAs($t['user'])->get(route('safety-equipment.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Hse/Equipment/Index')
                ->where('stats.total', 4)
                ->where('stats.operational', 3)
                ->where('stats.out_of_service', 1)
                ->where('stats.overdue', 1)
                ->where('stats.due_soon', 1)
                ->where('stats.compliant', 1)
                ->where('stats.without_location', 1)
                // Worst standing first.
                ->where('equipment.data.0.name', 'Retired')
                ->where('equipment.data.1.name', 'Late')
                ->where('can.manage', true));

        $this->actingAs($t['user'])->get(route('safety-equipment.index', ['state' => 'overdue']))
            ->assertInertia(fn (Assert $page) => $page->where('equipment.total', 1)->where('equipment.data.0.name', 'Late'));
        $this->actingAs($t['user'])->get(route('safety-equipment.index', ['q' => 'yard']))
            ->assertInertia(fn (Assert $page) => $page->where('equipment.total', 1)->where('equipment.data.0.name', 'Late'));
        $this->actingAs($t['user'])->get(route('safety-equipment.index', ['type' => (string) $t['detector']->id]))
            ->assertInertia(fn (Assert $page) => $page->where('equipment.total', 0)->where('stats.total', 4));
    }

    public function test_an_empty_register_reports_nothing_rather_than_a_healthy_fleet(): void
    {
        $t = $this->tenant('empty');

        $this->actingAs($t['user'])->get(route('safety-equipment.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats.total', 0)->where('stats.compliant', 0)->where('equipment.total', 0));
    }

    public function test_every_role_reads_the_register_but_only_hse_writes(): void
    {
        $t = $this->tenant('perm');
        $unit = $this->unit($t, ['name' => 'Shower', 'next_inspection_due' => '2027-01-01']);
        $hrd = User::create([
            'name' => 'HRD', 'email' => 'hrd@perm.test', 'password' => bcrypt('x'),
            'role' => 'hrd', 'tenant_id' => $t['tenant']->id, 'is_active' => true,
        ]);

        $this->actingAs($hrd)->get(route('safety-equipment.index'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
        $this->actingAs($hrd)->get(route('safety-equipment.show', $unit))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Hse/Equipment/Show')->where('can.manage', false)->missing('equipmentTypes'));

        $this->actingAs($hrd)->post(route('safety-equipment.store'), [
            'company_id' => $t['company']->id, 'name' => 'X', 'equipment_type_id' => $t['apar']->id, 'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($hrd)->post(route('safety-equipment.inspections.store', $unit), [
            'inspection_date' => '2026-10-20', 'condition' => 'good', 'result' => 'pass',
        ])->assertForbidden();
        $this->actingAs($hrd)->delete(route('safety-equipment.destroy', $unit))->assertForbidden();
        $this->assertNotNull($unit->fresh());
    }

    public function test_one_tenant_cannot_see_or_touch_anothers_units_or_types(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $unitA = $this->unit($a, ['name' => 'Alpha unit']);

        $this->actingAs($b['user'])->get(route('safety-equipment.show', $unitA))->assertNotFound();
        $this->actingAs($b['user'])->put(route('safety-equipment.update', $unitA), [
            'company_id' => $b['company']->id, 'name' => 'Hijacked', 'equipment_type_id' => $b['apar']->id, 'status' => 'active',
        ])->assertNotFound();
        $this->actingAs($b['user'])->post(route('safety-equipment.inspections.store', $unitA), [
            'inspection_date' => '2026-10-20', 'condition' => 'good', 'result' => 'pass',
        ])->assertNotFound();
        // Tenant B may not file a unit under tenant A's type.
        $this->actingAs($b['user'])->post(route('safety-equipment.store'), [
            'company_id' => $b['company']->id, 'name' => 'Borrowed', 'equipment_type_id' => $a['apar']->id, 'status' => 'active',
        ])->assertSessionHasErrors('equipment_type_id');

        $this->actingAs($b['user'])->get(route('safety-equipment.index'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.total', 0));
        $this->assertSame('Alpha unit', $unitA->fresh()->name);
    }

    public function test_the_old_register_tab_redirects_and_types_save_their_lifecycles(): void
    {
        $t = $this->tenant('types');

        $this->actingAs($t['user'])->get(route('hse.master', ['tab' => 'register']))->assertRedirect(route('safety-equipment.index'));
        // A bare /hse/master was "Safety Equipment & Compliance" until v2.133.0;
        // without a tab it rendered Supplies, which read as the old register.
        $this->actingAs($t['user'])->get(route('hse.master'))->assertRedirect(route('safety-equipment.index'));
        $this->actingAs($t['user'])->get('/hse/master?tab=')->assertRedirect(route('safety-equipment.index'));
        $this->actingAs($t['user'])->get(route('hse.master', ['tab' => 'supplies']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Hse/Master'));
        $this->actingAs($t['user'])->get(route('hse.master', ['tab' => 'equipment-master']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Hse/Master')->missing('safetyEquipment')->has('equipmentCounts'));

        $this->actingAs($t['user'])->post(route('hse-equipment-types.store'), [
            'company_id' => $t['company']->id, 'name' => 'Lifebuoy', 'code' => 'lifebuoy', 'code_prefix' => 'lsa',
            'is_active' => true, 'tracks_inspection' => true, 'tracks_service' => true, 'inspection_interval_months' => 3,
        ])->assertSessionHasNoErrors();

        $type = HseEquipmentType::withoutGlobalScopes()->where('code', 'lifebuoy')->first();
        $this->assertSame('LSA', $type->code_prefix);
        $this->assertSame(['inspection', 'service'], $type->trackedLifecycles());
        $this->assertSame(3, $type->inspection_interval_months);
    }

    public function test_the_hse_overview_sends_inspections_due_to_the_register(): void
    {
        $t = $this->tenant('overview-link');

        $this->actingAs($t['user'])->get(route('hse.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('operationalKpis', fn ($kpis) => collect($kpis)
                ->firstWhere('key', 'inspections')['href'] === route('safety-equipment.index', ['state' => 'attention'])));
    }
}
