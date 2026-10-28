<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.135.0 -- AN EQUIPMENT INSPECTION IS A CHECKLIST, NOT A VERDICT.
 *
 * A unit's inspection stored one condition, one result and free text, so an
 * APAR with a corroded cylinder and a valid expiry date could only be
 * written up as a sentence. Additive only:
 *
 *  - hse_checklist_templates.equipment_type_id: a template may belong to an
 *    equipment TYPE (APAR, P3K, gas detector) instead of an area-inspection
 *    category, so each type carries its own criteria;
 *  - safety_equipment_inspections.checklist: the answers, with the items as
 *    they read on the day (a later template edit cannot rewrite a filed
 *    inspection), plus the template it came from, the risk level of what was
 *    found, and the photo evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hse_checklist_templates', 'equipment_type_id')) {
            Schema::table('hse_checklist_templates', function (Blueprint $table) {
                $table->foreignId('equipment_type_id')->nullable()->after('category')
                    ->constrained('hse_equipment_types')->nullOnDelete();
            });
        }

        Schema::table('safety_equipment_inspections', function (Blueprint $table) {
            if (! Schema::hasColumn('safety_equipment_inspections', 'checklist_template_id')) {
                $table->foreignId('checklist_template_id')->nullable()->after('inspector_id')
                    ->constrained('hse_checklist_templates')->nullOnDelete();
            }
            if (! Schema::hasColumn('safety_equipment_inspections', 'checklist')) {
                $table->json('checklist')->nullable()->after('result');
            }
            if (! Schema::hasColumn('safety_equipment_inspections', 'risk_level')) {
                $table->string('risk_level', 20)->nullable()->after('findings');
            }
            if (! Schema::hasColumn('safety_equipment_inspections', 'photos')) {
                $table->json('photos')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('safety_equipment_inspections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checklist_template_id');
            $table->dropColumn(['checklist', 'risk_level', 'photos']);
        });
        Schema::table('hse_checklist_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipment_type_id');
        });
    }
};
