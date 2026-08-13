<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.1 (HSE Domain Hardening II, Part 7/8). `SafetyEquipment`
     * (Workstream B10) already existed as the operational HSE equipment
     * register -- audited first and reused, NOT duplicated. Two gaps
     * closed:
     *
     * 1. `SafetyEquipment::TYPES` was a hardcoded PHP array (fire_
     *    extinguisher/safety_shower/eyewash_station/emergency_alarm/
     *    spill_kit/other) -- the explicit new requirement is "Super Admin
     *    should be able to add future HSE equipment types" / "do not
     *    hardcode as immutable code values". `hse_equipment_types` mirrors
     *    `hazard_categories`' own established shape exactly
     *    (company_id/name/code/description/is_active/sort_order) --
     *    seeded below with the SAME codes the hardcoded array already
     *    used, plus the newly-requested ones (APAR, HT, Gas Detector,
     *    Blower, TOA), so `safety_equipment.type` (left as a plain string
     *    column, unchanged -- no FK, no data migration needed) keeps
     *    validating against exactly the same existing values for every
     *    already-created row, now sourced from a configurable table
     *    instead of a constant.
     *
     * 2. `safety_equipment` only ever stored ONE
     *    last_inspection_date/next_inspection_due pair -- no history.
     *    `safety_equipment_inspections` is a real child table (mirrors
     *    `gas_test_records`' own "individually meaningful, time-series"
     *    reasoning exactly) so a piece of equipment's full inspection
     *    history is queryable, not overwritten on each re-inspection.
     */
    public function up(): void
    {
        Schema::createIfMissing('hse_equipment_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::createIfMissing('safety_equipment_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safety_equipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('inspection_date');
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition')->default('good'); // good, fair, poor, damaged
            $table->string('result')->default('pass'); // pass, fail, needs_action
            $table->text('findings')->nullable();
            $table->date('next_inspection_due')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['safety_equipment_id', 'inspection_date']);
        });

        // Seed every company that already has SafetyEquipment rows with
        // the exact set of type codes those rows already use (so no
        // existing row's `type` value would suddenly fail validation),
        // plus the newly-requested operational equipment categories.
        $companyIds = DB::table('companies')->pluck('id');
        $defaults = [
            ['code' => 'fire_extinguisher', 'name' => 'Fire Extinguisher (APAR)'],
            ['code' => 'safety_shower', 'name' => 'Safety Shower'],
            ['code' => 'eyewash_station', 'name' => 'Eyewash Station'],
            ['code' => 'emergency_alarm', 'name' => 'Emergency Alarm'],
            ['code' => 'spill_kit', 'name' => 'Spill Kit'],
            ['code' => 'handheld_radio', 'name' => 'Handheld Radio (HT)'],
            ['code' => 'gas_detector', 'name' => 'Gas Detector'],
            ['code' => 'blower', 'name' => 'Blower / Ventilator'],
            ['code' => 'public_address', 'name' => 'Public Address (TOA)'],
            ['code' => 'other', 'name' => 'Other'],
        ];
        $now = now();
        foreach ($companyIds as $companyId) {
            $rows = collect($defaults)->map(fn ($d, $i) => [
                'company_id' => $companyId,
                'name' => $d['name'],
                'code' => $d['code'],
                'is_active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            DB::table('hse_equipment_types')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_equipment_inspections');
        Schema::dropIfExists('hse_equipment_types');
    }
};
