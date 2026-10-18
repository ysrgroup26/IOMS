<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ppe_types', function (Blueprint $table) {
            $table->foreignId('source_default_id')->nullable()->after('company_id')
                ->constrained('ppe_types')->nullOnDelete();
            $table->dropUnique('ppe_types_name_unique');
            $table->unique(['company_id', 'name'], 'ppe_types_company_name_unique');
            $table->unique(['company_id', 'source_default_id'], 'ppe_types_company_source_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('ppe_types')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('PPE template overrides or company-specific names exist; rolling back would violate the original global name uniqueness.');
        }

        Schema::table('ppe_types', function (Blueprint $table) {
            $table->dropUnique('ppe_types_company_source_unique');
            $table->dropUnique('ppe_types_company_name_unique');
            $table->dropForeign(['source_default_id']);
            $table->dropColumn('source_default_id');
            $table->unique('name', 'ppe_types_name_unique');
        });
    }
};
