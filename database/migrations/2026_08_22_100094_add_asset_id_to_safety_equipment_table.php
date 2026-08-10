<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 1C -- HSE integration. A
     * `SafetyEquipment` row (fire extinguisher, safety shower, etc. --
     * Workstream B10) can now optionally reference the SAME asset in the
     * new `assets` register, rather than HSE tracking a parallel
     * identity for equipment that's also a company asset. Nullable and
     * additive -- every existing SafetyEquipment row keeps working
     * unmodified with no asset link at all.
     */
    public function up(): void
    {
        Schema::table('safety_equipment', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('safety_equipment', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
