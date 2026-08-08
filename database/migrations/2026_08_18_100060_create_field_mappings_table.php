<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 3 (Import/Export Mapping, Task #67). One row per
 * (tenant, module, direction, field) -- "define the Excel column ->
 * IOMS field mapping once, reused automatically" from the brief. Two
 * directions share one table because the shape is identical either way:
 * a field_key (from config/mapping_fields.php's catalog for that
 * module), the column label the company's spreadsheet actually uses
 * (import: the header text to READ; export: the header text to WRITE),
 * and whether the field participates at all (export can omit a field
 * entirely; import always looks for its target field's default header
 * name as a fallback when no mapping row exists -- see
 * App\Services\FieldMappingService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module_key');
            $table->string('direction'); // import | export
            $table->string('field_key');
            $table->string('column_label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            // No DB unique constraint: MySQL treats NULL company_id as
            // distinct per row, so it wouldn't actually enforce "one row
            // per (tenant, module, direction, field)" for the
            // tenant-wide (company_id null) rows this feature exclusively
            // writes today -- same caveat NumberingSequence's own
            // migration hit and solved with a functional index for.
            // Uniqueness here is instead app-enforced via updateOrCreate
            // in FieldMappingService, which is simpler and sufficient
            // since company_id is always null from this feature's UI.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_mappings');
    }
};
