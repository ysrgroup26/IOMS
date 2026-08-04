<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departments now belong to a Company. This is an ADDITIVE migration:
     * no existing rows are deleted. All pre-existing departments are
     * backfilled to "GAJ" (per product decision), matching how the
     * original single-company data was implicitly scoped.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        $gajId = DB::table('companies')->where('name', 'GAJ')->value('id');
        if ($gajId) {
            DB::table('departments')->whereNull('company_id')->update(['company_id' => $gajId]);
        }

        // Now that all existing rows are backfilled, enforce NOT NULL going forward.
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });

        // A department name only needs to be unique within its company now
        // (e.g. both GAJ and Maintenance can have "HSE" and "Engineering").
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
            $table->unique('name');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
