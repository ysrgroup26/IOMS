<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WasteRecord;
use App\Models\WasteStorageLocation;
use App\Models\WasteType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * v2.135.0 -- the waste record form records a quantity the system can
 * reason about.
 *
 * Pins: units come from one list (or the waste type's own), spelled one way;
 * a count is whole; nothing converts a liter into a kilogram; a record is
 * stored in a TPS of its own operating unit; dates are not in the future and
 * a TPS cannot receive waste before it was generated.
 */
class WasteRecordFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 'waste-form']);
        $yard = Company::withoutGlobalScopes()->create(['name' => 'Yard', 'tenant_id' => $tenant->id]);
        $dock = Company::withoutGlobalScopes()->create(['name' => 'Dock', 'tenant_id' => $tenant->id]);
        $hse = User::create(['name' => 'HSE', 'email' => 'hse@waste-form.test', 'password' => bcrypt('x'), 'role' => 'hse', 'tenant_id' => $tenant->id, 'is_active' => true]);
        $oil = WasteType::withoutGlobalScopes()->create(['company_id' => $yard->id, 'name' => 'Oli bekas', 'code' => 'oil', 'category' => 'b3', 'unit' => 'liter', 'is_active' => true]);
        $drums = WasteType::withoutGlobalScopes()->create(['company_id' => $yard->id, 'name' => 'Drum bekas', 'code' => 'drum', 'category' => 'b3', 'unit' => 'drum', 'is_active' => true]);
        $tps = WasteStorageLocation::withoutGlobalScopes()->create(['company_id' => $yard->id, 'name' => 'TPS Yard', 'code' => 'TPS-Y', 'capacity' => 1000, 'capacity_unit' => 'liter', 'status' => 'active']);
        $dockTps = WasteStorageLocation::withoutGlobalScopes()->create(['company_id' => $dock->id, 'name' => 'TPS Dock', 'code' => 'TPS-D', 'status' => 'active']);

        return compact('tenant', 'yard', 'dock', 'hse', 'oil', 'drums', 'tps', 'dockTps');
    }

    private function record(array $t, array $data)
    {
        return $this->actingAs($t['hse'])->post(route('waste-records.store'), [
            'company_id' => $t['yard']->id, 'waste_type_id' => $t['oil']->id, 'quantity' => '120', 'unit' => 'liter',
            'generated_date' => '2026-10-27', ...$data,
        ]);
    }

    public function test_units_are_one_spelling_and_a_count_is_whole(): void
    {
        $t = $this->tenant();

        $this->record($t, ['unit' => 'L'])->assertSessionHasNoErrors();
        $this->assertSame('liter', WasteRecord::withoutGlobalScopes()->latest('id')->value('unit'));
        $this->record($t, ['unit' => 'Kg'])->assertSessionHasNoErrors();
        $this->assertSame('kg', WasteRecord::withoutGlobalScopes()->latest('id')->value('unit'));

        // A unit the system cannot reason about is refused...
        $this->record($t, ['unit' => 'bucket'])->assertSessionHasErrors('unit');
        // ...unless it is the waste type's own (existing master data is never refused).
        $this->record($t, ['waste_type_id' => $t['drums']->id, 'unit' => 'drum', 'quantity' => '4'])->assertSessionHasNoErrors();

        $this->record($t, ['unit' => 'unit', 'quantity' => '2.5'])->assertSessionHasErrors('quantity');
        $this->record($t, ['unit' => 'unit', 'quantity' => '3'])->assertSessionHasNoErrors();
    }

    public function test_a_record_is_stored_in_a_tps_of_its_own_operating_unit(): void
    {
        $t = $this->tenant();

        $this->record($t, ['storage_location_id' => $t['dockTps']->id])->assertSessionHasErrors('storage_location_id');
        $this->record($t, ['storage_location_id' => $t['tps']->id, 'received_date' => '2026-10-28'])->assertSessionHasNoErrors();
        $record = WasteRecord::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(WasteRecord::STATUS_STORED, $record->status);
        $this->assertSame($t['tps']->id, $record->storage_location_id);
    }

    public function test_dates_are_not_in_the_future_and_storage_follows_generation(): void
    {
        $t = $this->tenant();

        $this->record($t, ['generated_date' => '2026-10-29'])->assertSessionHasErrors('generated_date');
        $this->record($t, ['storage_location_id' => $t['tps']->id, 'received_date' => '2026-10-20'])->assertSessionHasErrors('received_date');
        $this->assertSame(0, WasteRecord::withoutGlobalScopes()->count());
    }

    public function test_the_form_offers_the_units_and_the_companys_container_types(): void
    {
        $t = $this->tenant();

        $this->actingAs($t['hse'])->get(route('waste-records.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Hse/WasteManagement/Form')
                ->where('units', fn ($units) => collect($units)->pluck('value')->all() === ['kg', 'ton', 'liter', 'm3', 'unit'])
                ->has('containerTypes'));
    }
}
