<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.53.0 -- Equipment MASTER and Equipment REGISTER become two things.
 *
 * Both tables already existed, but they were not actually connected and
 * the register could not describe a real unit:
 *
 *   - `safety_equipment.type` was a free STRING. The master
 *     (`hse_equipment_types`) existed beside it and nothing referenced it,
 *     so "Gas Detector" and "Gas detector" were different types and the
 *     master was decorative.
 *   - The register had no equipment identifier of its own. A fleet of gas
 *     detectors is GD-001, GD-002, GD-003 on the shop floor; the system
 *     could only hold a name.
 *   - It carried exactly one lifecycle date (`next_inspection_due`) for
 *     every type of equipment, so a fire extinguisher's expiry, a gas
 *     detector's calibration and a blower's service interval all had to
 *     be squeezed into "inspection" or left unrecorded.
 *
 * THE FIX IS NOT ONE MORE DATE COLUMN FOR EVERYONE. Which lifecycles
 * apply is a property of the TYPE, not of the unit: a gas detector is
 * calibrated, a fire extinguisher expires, a safety shower is serviced,
 * and forcing an expiry date onto equipment that does not expire produces
 * either blank columns or invented data. So the master now declares which
 * lifecycles it tracks, and the register carries the dates for the ones
 * that apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hse_equipment_types', function (Blueprint $table) {
            // WHICH LIFECYCLES THIS TYPE ACTUALLY HAS. Inspection defaults
            // true because every safety equipment type in scope is
            // inspected; the rest default false, so nothing gains a date
            // field it has no business having.
            if (! Schema::hasColumn('hse_equipment_types', 'tracks_inspection')) {
                $table->boolean('tracks_inspection')->default(true)->after('description');
            }
            if (! Schema::hasColumn('hse_equipment_types', 'tracks_calibration')) {
                $table->boolean('tracks_calibration')->default(false)->after('tracks_inspection');
            }
            if (! Schema::hasColumn('hse_equipment_types', 'tracks_service')) {
                $table->boolean('tracks_service')->default(false)->after('tracks_calibration');
            }
            if (! Schema::hasColumn('hse_equipment_types', 'tracks_expiry')) {
                $table->boolean('tracks_expiry')->default(false)->after('tracks_service');
            }
            // Default interval in months, used to propose the next due date
            // when a unit is inspected. Nullable: a type may track a
            // lifecycle without a fixed cadence.
            if (! Schema::hasColumn('hse_equipment_types', 'inspection_interval_months')) {
                $table->unsignedSmallInteger('inspection_interval_months')->nullable()->after('tracks_expiry');
            }
            // The identifier prefix a unit of this type gets: GD, FE, BL.
            if (! Schema::hasColumn('hse_equipment_types', 'code_prefix')) {
                $table->string('code_prefix', 12)->nullable()->after('code');
            }
        });

        Schema::table('safety_equipment', function (Blueprint $table) {
            // The link that makes the master load-bearing. Nullable so
            // existing rows survive; the backfill below connects the ones
            // whose free-text type already matches a master entry.
            if (! Schema::hasColumn('safety_equipment', 'equipment_type_id')) {
                $table->foreignId('equipment_type_id')->nullable()->after('company_id')
                    ->constrained('hse_equipment_types')->nullOnDelete();
            }
            // The unit's own identifier -- GD-001. Not unique globally:
            // it is unique within a company, and two tenants may both run
            // a GD-001, exactly like a document number.
            if (! Schema::hasColumn('safety_equipment', 'equipment_code')) {
                $table->string('equipment_code', 50)->nullable()->after('equipment_type_id');
            }
            if (! Schema::hasColumn('safety_equipment', 'brand')) {
                $table->string('brand', 120)->nullable()->after('name');
            }
            if (! Schema::hasColumn('safety_equipment', 'model')) {
                $table->string('model', 120)->nullable()->after('brand');
            }
            if (! Schema::hasColumn('safety_equipment', 'commissioned_at')) {
                $table->date('commissioned_at')->nullable()->after('serial_number');
            }
            // Each lifecycle gets its own date. Whether it is asked for at
            // all is decided by the TYPE's flags above.
            if (! Schema::hasColumn('safety_equipment', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('next_inspection_due');
            }
            if (! Schema::hasColumn('safety_equipment', 'next_service_due')) {
                $table->date('next_service_due')->nullable()->after('expiry_date');
            }
            if (! Schema::hasColumn('safety_equipment', 'next_calibration_due')) {
                $table->date('next_calibration_due')->nullable()->after('next_service_due');
            }
        });

        Schema::table('safety_equipment', function (Blueprint $table) {
            $table->index(['company_id', 'equipment_code'], 'safety_equipment_company_code_idx');
        });

        $this->backfillTypeLinks();
    }

    /**
     * Connects existing register rows to the master WITHOUT inventing
     * anything: a row is linked only when its free-text `type` matches an
     * existing master entry's name or code for the same company, compared
     * case-insensitively. Anything that does not match is left unlinked
     * for a human to classify -- guessing here would silently mis-file
     * real equipment.
     */
    private function backfillTypeLinks(): void
    {
        if (! Schema::hasTable('safety_equipment') || ! Schema::hasTable('hse_equipment_types')) {
            return;
        }

        foreach (DB::table('hse_equipment_types')->get() as $type) {
            DB::table('safety_equipment')
                ->where('company_id', $type->company_id)
                ->whereNull('equipment_type_id')
                ->where(function ($q) use ($type) {
                    $q->whereRaw('LOWER(type) = ?', [mb_strtolower($type->name)])
                        ->orWhereRaw('LOWER(type) = ?', [mb_strtolower($type->code)]);
                })
                ->update(['equipment_type_id' => $type->id]);
        }
    }

    public function down(): void
    {
        Schema::table('safety_equipment', function (Blueprint $table) {
            $table->dropIndex('safety_equipment_company_code_idx');

            if (Schema::hasColumn('safety_equipment', 'equipment_type_id')) {
                $table->dropConstrainedForeignId('equipment_type_id');
            }

            foreach (['equipment_code', 'brand', 'model', 'commissioned_at', 'expiry_date', 'next_service_due', 'next_calibration_due'] as $column) {
                if (Schema::hasColumn('safety_equipment', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('hse_equipment_types', function (Blueprint $table) {
            foreach (['tracks_inspection', 'tracks_calibration', 'tracks_service', 'tracks_expiry', 'inspection_interval_months', 'code_prefix'] as $column) {
                if (Schema::hasColumn('hse_equipment_types', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
