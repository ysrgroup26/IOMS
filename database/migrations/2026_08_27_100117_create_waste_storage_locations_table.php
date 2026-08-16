<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.4 (HSE Waste Management, Part 15). Waste Storage / TPS
     * (Tempat Penyimpanan Sementara) register -- deliberately NAMED and
     * TABLED separately from the pre-existing `storage_locations` table
     * (2026_08_22_100088, Warehouse's own bins/racks for general
     * inventory). These are conceptually different registers (regulated
     * temporary waste holding vs. general stock storage) even though
     * both are "a place things are kept" -- a genuine, deliberate
     * non-duplication, not an accidental naming collision. Mirrors the
     * HSE master-data shape (company_id/name/code) used throughout this
     * module.
     */
    public function up(): void
    {
        Schema::createIfMissing('waste_storage_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('location')->nullable();
            $table->string('container_type')->nullable(); // drum, ibc, other -- free text, tenant-configurable, never a hardcoded enum
            $table->decimal('capacity', 10, 2)->nullable();
            $table->string('capacity_unit')->nullable();
            $table->string('status')->default('active'); // active, inactive
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_storage_locations');
    }
};
