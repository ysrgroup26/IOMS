<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\HseChecklistTemplate;
use App\Models\HseEquipmentType;
use App\Models\SafetyEquipment;
use App\Models\SafetyEquipmentInspection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * v2.135.0 -- an equipment inspection is judged from its checklist.
 *
 * Pins: a template belongs to an equipment TYPE and carries the kind of each
 * check; the server -- never the browser -- decides the result (critical
 * Fail is Fail, other Fail is Needs Action, an expired date or an
 * out-of-range reading fails by itself, Damaged is never Pass); a finding
 * needs a description, a risk level and, where the item asks, a photo; each
 * follow-up becomes a CorrectiveAction verified in the CAPA register; the
 * items are filed with the answers; and a type with no checklist keeps the
 * earlier form.
 */
class EquipmentInspectionChecklistTest extends TestCase
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

    private function tenant(string $slug): array
    {
        $tenant = Tenant::create(['name' => 'T-'.$slug, 'slug' => $slug]);
        $company = Company::withoutGlobalScopes()->create(['name' => 'C-'.$slug, 'tenant_id' => $tenant->id]);
        $hse = User::create(['name' => 'HSE '.$slug, 'email' => "hse@{$slug}.test", 'password' => bcrypt('x'), 'role' => 'hse', 'tenant_id' => $tenant->id, 'is_active' => true]);
        $hrd = User::create(['name' => 'HRD '.$slug, 'email' => "hrd@{$slug}.test", 'password' => bcrypt('x'), 'role' => 'hrd', 'tenant_id' => $tenant->id, 'is_active' => true]);
        $apar = HseEquipmentType::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'name' => 'APAR', 'code' => 'apar', 'code_prefix' => 'APAR',
            'is_active' => true, 'tracks_inspection' => true, 'inspection_interval_months' => 6,
        ]);
        $shower = HseEquipmentType::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'name' => 'Safety Shower', 'code' => 'safety_shower', 'is_active' => true, 'tracks_inspection' => true,
        ]);
        $unit = SafetyEquipment::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'name' => 'APAR CO2 5 kg', 'equipment_code' => 'APAR-01', 'type' => 'apar',
            'equipment_type_id' => $apar->id, 'status' => 'active',
        ]);

        return compact('tenant', 'company', 'hse', 'hrd', 'apar', 'shower', 'unit');
    }

    /** The APAR reference list's shape, kept small: one critical, one plain, one reading, one date. */
    private function aparTemplate(array $t): HseChecklistTemplate
    {
        return HseChecklistTemplate::withoutGlobalScopes()->create([
            'company_id' => $t['company']->id, 'category' => 'equipment', 'equipment_type_id' => $t['apar']->id,
            'name' => 'APAR Bulanan', 'is_active' => true, 'items' => [
                ['label' => 'Tabung bebas karat dan penyok', 'check' => 'pass_fail', 'critical' => true, 'photo_on_fail' => true],
                ['label' => 'Label terbaca', 'check' => 'pass_fail'],
                ['label' => 'Tekanan', 'check' => 'reading', 'unit' => 'bar', 'min' => 12, 'max' => 18],
                ['label' => 'Tanggal kedaluwarsa', 'check' => 'date', 'critical' => true],
            ],
        ]);
    }

    private function answers(array $overrides = []): array
    {
        $base = [
            ['result' => 'pass'],
            ['result' => 'pass'],
            ['value' => '15'],
            ['value' => '2027-06-30'],
        ];
        foreach ($overrides as $i => $a) {
            $base[$i] = $a;
        }

        return $base;
    }

    public function test_the_judge_applies_the_rules_and_not_the_inspectors_choice(): void
    {
        $items = [
            ['label' => 'A', 'check' => 'pass_fail', 'critical' => true],
            ['label' => 'B', 'check' => 'pass_fail'],
            ['label' => 'C', 'check' => 'reading', 'min' => 12, 'max' => 18],
            ['label' => 'D', 'check' => 'date', 'critical' => true],
        ];
        $judge = fn (array $answers, string $condition = 'good') => SafetyEquipmentInspection::judge($items, $answers, '2026-10-28', $condition);

        $this->assertSame('pass', $judge($this->answers())['result']);
        // A non-critical Fail is Needs Action; a critical one is Fail.
        $this->assertSame('needs_action', $judge($this->answers([1 => ['result' => 'fail']]))['result']);
        $this->assertSame('fail', $judge($this->answers([0 => ['result' => 'fail']]))['result']);
        // Out of range fails by itself, even when "pass" was posted.
        $r = $judge($this->answers([2 => ['value' => '9', 'result' => 'pass']]));
        $this->assertSame('fail', $r['rows'][2]['result']);
        $this->assertSame('needs_action', $r['result']);
        // A date before the inspection is Expired: critical, so Fail.
        $this->assertSame('fail', $judge($this->answers([3 => ['value' => '2026-10-01']]))['result']);
        // Damaged is never Pass.
        $this->assertSame('needs_action', $judge($this->answers(), 'damaged')['result']);
        // A critical item cannot be N/A; an unanswered one is an error.
        $this->assertArrayHasKey('checklist.0.result', $judge($this->answers([0 => ['result' => 'na']]))['errors']);
        $this->assertArrayHasKey('checklist.1.result', $judge($this->answers([1 => []]))['errors']);
        $this->assertArrayHasKey('checklist.2.value', $judge($this->answers([2 => ['value' => '']]))['errors']);
    }

    public function test_a_template_belongs_to_an_equipment_type_and_items_carry_their_check(): void
    {
        $t = $this->tenant('tpl');
        $other = $this->tenant('tpl-other');

        $this->actingAs($t['hse'])->post(route('hse-checklist-templates.store'), [
            'company_id' => $t['company']->id, 'equipment_type_id' => $t['apar']->id, 'name' => 'APAR Bulanan',
            'items' => [
                ['label' => 'Tabung bebas karat', 'check' => 'pass_fail', 'critical' => true, 'photo_on_fail' => true],
                ['label' => 'Tekanan', 'check' => 'reading', 'unit' => 'bar', 'min' => '12', 'max' => '18'],
                ['label' => 'Label terbaca'],
            ],
        ])->assertSessionHasNoErrors();

        $tpl = HseChecklistTemplate::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('equipment', $tpl->category);
        $this->assertSame($t['apar']->id, $tpl->equipment_type_id);
        $this->assertTrue($tpl->items[0]['critical']);
        $this->assertSame('bar', $tpl->items[1]['unit']);
        $this->assertSame('pass_fail', $tpl->items[2]['check']);

        // Area templates still work as before; another tenant's type is refused.
        $this->actingAs($t['hse'])->post(route('hse-checklist-templates.store'), [
            'company_id' => $t['company']->id, 'category' => 'ppe', 'name' => 'PPE', 'items' => [['label' => 'Helm']],
        ])->assertSessionHasNoErrors();
        $this->actingAs($t['hse'])->post(route('hse-checklist-templates.store'), [
            'company_id' => $t['company']->id, 'equipment_type_id' => $other['apar']->id, 'name' => 'X', 'items' => [['label' => 'Y']],
        ])->assertSessionHasErrors('equipment_type_id');
        $this->actingAs($t['hse'])->post(route('hse-checklist-templates.store'), [
            'company_id' => $t['company']->id, 'name' => 'Neither', 'items' => [['label' => 'Y']],
        ])->assertSessionHasErrors('category');
        // Templates are configuration: Super Admin and HSE only.
        $this->actingAs($t['hrd'])->post(route('hse-checklist-templates.store'), [
            'company_id' => $t['company']->id, 'equipment_type_id' => $t['apar']->id, 'name' => 'Z', 'items' => [['label' => 'Y']],
        ])->assertForbidden();
    }

    public function test_a_failed_critical_item_makes_the_unit_action_required_with_evidence_and_follow_up(): void
    {
        Storage::fake('public');
        $t = $this->tenant('fail');
        $tpl = $this->aparTemplate($t);

        $this->actingAs($t['hse'])->post(route('safety-equipment.inspections.store', $t['unit']), [
            'inspection_date' => '2026-10-28', 'condition' => 'poor', 'result' => 'pass', // the posted result is ignored
            'checklist' => $this->answers([0 => ['result' => 'fail', 'remark' => 'Karat di bagian bawah']]),
            'findings' => 'Tabung berkarat di bagian bawah.', 'risk_level' => 'high',
            'photos' => [UploadedFile::fake()->image('karat.jpg')],
            'actions' => [['action' => 'Ganti APAR', 'assigned_to' => $t['hse']->id, 'due_date' => '2026-11-04']],
        ])->assertSessionHasNoErrors();

        $inspection = SafetyEquipmentInspection::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('fail', $inspection->result);
        $this->assertSame('high', $inspection->risk_level);
        $this->assertSame($tpl->id, $inspection->checklist_template_id);
        $this->assertSame('fail', $inspection->checklist[0]['result']);
        $this->assertSame('Karat di bagian bawah', $inspection->checklist[0]['remark']);
        $this->assertSame('Tabung bebas karat dan penyok', $inspection->checklist[0]['label']);
        Storage::disk('public')->assertExists($inspection->photos[0]['path']);

        $action = CorrectiveAction::withoutGlobalScopes()->first();
        $this->assertSame(SafetyEquipmentInspection::class, $action->source_type);
        $this->assertSame($inspection->id, $action->source_id);
        $this->assertSame('high', $action->priority);
        $this->assertSame(CorrectiveAction::STATUS_OPEN, $action->status);

        // The CAPA register names the unit the follow-up came from (and must
        // load it eagerly: lazy loading is refused outside production, and
        // Laravel only enforces it once a page holds more than one row).
        $second = $inspection->replicate();
        $second->save();
        $action->replicate()->fill(['source_id' => $second->id])->save();
        $this->actingAs($t['hse'])->get(route('corrective-actions.index'))->assertOk()
            ->assertSee($t['unit']->equipment_code.' inspection 2026-10-28', false);

        $unit = SafetyEquipment::withoutGlobalScopes()->with('latestInspection')->find($t['unit']->id);
        $this->assertSame('action_required', $unit->complianceState(now()->startOfDay()));

        // A later template edit does not rewrite what was filed.
        $tpl->update(['items' => [['label' => 'Changed', 'check' => 'pass_fail']]]);
        $this->assertSame('Tabung bebas karat dan penyok', $inspection->fresh()->checklist[0]['label']);
    }

    public function test_a_finding_needs_a_description_a_risk_level_and_the_photo_its_item_asks_for(): void
    {
        $t = $this->tenant('rules');
        $this->aparTemplate($t);
        $post = fn (array $extra) => $this->actingAs($t['hse'])->post(route('safety-equipment.inspections.store', $t['unit']), [
            'inspection_date' => '2026-10-28', 'condition' => 'good', ...$extra,
        ]);

        $post(['checklist' => $this->answers([0 => ['result' => 'fail']])])
            ->assertSessionHasErrors(['findings', 'risk_level', 'photos']);
        $post(['checklist' => $this->answers([1 => []])])->assertSessionHasErrors('checklist.1.result');
        $post(['checklist' => $this->answers([0 => ['result' => 'na']])])->assertSessionHasErrors('checklist.0.result');
        $this->assertSame(0, SafetyEquipmentInspection::withoutGlobalScopes()->count());

        // An expired date fails a clean-looking unit, and needs no photo (its item does not ask).
        $post(['checklist' => $this->answers([3 => ['value' => '2026-09-30']]), 'findings' => 'Kedaluwarsa', 'risk_level' => 'critical'])
            ->assertSessionHasNoErrors();
        $this->assertSame('fail', SafetyEquipmentInspection::withoutGlobalScopes()->latest('id')->value('result'));

        // A clean pass needs no risk level, and none is stored.
        $post(['checklist' => $this->answers(), 'risk_level' => 'high'])->assertSessionHasNoErrors();
        $last = SafetyEquipmentInspection::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('pass', $last->result);
        $this->assertNull($last->risk_level);
    }

    public function test_a_type_with_no_checklist_keeps_the_earlier_form(): void
    {
        $t = $this->tenant('legacy');
        $shower = SafetyEquipment::withoutGlobalScopes()->create([
            'company_id' => $t['company']->id, 'name' => 'Shower', 'type' => 'safety_shower', 'equipment_type_id' => $t['shower']->id, 'status' => 'active',
        ]);

        $this->actingAs($t['hse'])->post(route('safety-equipment.inspections.store', $shower), [
            'inspection_date' => '2026-10-28', 'condition' => 'good', 'result' => 'needs_action', 'findings' => 'Nozzle tersumbat', 'risk_level' => 'medium',
        ])->assertSessionHasNoErrors();
        $this->assertSame('needs_action', SafetyEquipmentInspection::withoutGlobalScopes()->latest('id')->value('result'));
        $this->assertNull(SafetyEquipmentInspection::withoutGlobalScopes()->latest('id')->value('checklist'));
    }

    public function test_the_record_page_offers_the_checklist_and_shows_what_was_filed(): void
    {
        $t = $this->tenant('show');
        $this->aparTemplate($t);
        Storage::fake('public');
        $this->actingAs($t['hse'])->post(route('safety-equipment.inspections.store', $t['unit']), [
            'inspection_date' => '2026-10-28', 'condition' => 'good', 'checklist' => $this->answers([1 => ['result' => 'fail']]),
            'findings' => 'Label pudar', 'risk_level' => 'low', 'actions' => [['action' => 'Ganti label']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($t['hse'])->get(route('safety-equipment.show', $t['unit']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checklistTemplate.name', 'APAR Bulanan')
                ->has('checklistTemplate.items', 4)
                ->where('inspections.0.result', 'needs_action')
                ->where('inspections.0.risk_level', 'low')
                ->where('inspections.0.checklist.1.result', 'fail')
                ->where('inspections.0.actions.0.action', 'Ganti label')
                ->has('assignees'));

        // The CAPA register names the inspection and links back to the unit.
        $this->actingAs($t['hse'])->get(route('corrective-actions.index'))->assertOk()
            ->assertSee('APAR-01 inspection 2026-10-28', false);
    }

    public function test_only_hse_records_and_another_tenant_cannot_reach_the_unit(): void
    {
        $t = $this->tenant('perm');
        $other = $this->tenant('perm-other');
        $this->aparTemplate($t);
        $hrd = $t['hrd'];
        $payload = ['inspection_date' => '2026-10-28', 'condition' => 'good', 'checklist' => $this->answers()];

        $this->actingAs($hrd)->post(route('safety-equipment.inspections.store', $t['unit']), $payload)->assertForbidden();
        $this->actingAs($other['hse'])->post(route('safety-equipment.inspections.store', $t['unit']), $payload)->assertNotFound();
        // A follow-up cannot be assigned to another tenant's user.
        $this->actingAs($t['hse'])->post(route('safety-equipment.inspections.store', $t['unit']), [
            ...$payload, 'checklist' => $this->answers([1 => ['result' => 'fail']]), 'findings' => 'x', 'risk_level' => 'low',
            'actions' => [['action' => 'y', 'assigned_to' => $other['hse']->id]],
        ])->assertSessionHasErrors('actions.0.assigned_to');
    }
}
