<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.11.4 (HSE Waste Management, Part 12). Configurable Waste Types --
     * mirrors HazardCategory/HseEquipmentType's own established master-data
     * shape exactly (company_id/name/code/is_active), extended with the
     * fields a waste type genuinely needs. Categories (B3/Non-B3) are a
     * fixed enum (the one genuinely fixed classification in Indonesian HSE
     * practice), but the type CATALOG itself -- name/code/waste_code/
     * characteristics -- is fully tenant-configurable, never hardcoded
     * into the UI, per explicit instruction.
     *
     * `storage_limit_days` is a NULLABLE, per-type OPERATIONAL monitoring
     * threshold, not a legal value -- explicit instruction: "Do NOT
     * hardcode Indonesian environmental regulations... must be
     * configurable... clearly labeled as an operational configuration and
     * not presented as legal advice." Null means "no monitoring
     * threshold configured for this type," never a fabricated default.
     */
    public function up(): void
    {
        Schema::createIfMissing('waste_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('category'); // b3, non_b3
            $table->string('waste_code')->nullable(); // regulatory waste code, e.g. Indonesian B3 code -- free text, never validated against a hardcoded list
            $table->text('characteristics')->nullable();
            $table->string('unit')->default('kg');
            // Operational monitoring threshold ONLY -- see class doc
            // comment. Nullable: no threshold configured is the safe
            // default, never an invented number.
            $table->unsignedInteger('storage_limit_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_types');
    }
};
