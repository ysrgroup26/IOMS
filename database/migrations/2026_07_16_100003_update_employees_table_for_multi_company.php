<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employees now belong to a Company (backfilled to GAJ for existing
     * rows, per product decision -- no employee data is lost).
     *
     * The `nik` column is dropped per explicit product decision: Employee
     * ID already serves as the unique employee number, and NIK data is
     * confirmed acceptable to remove. This is the one intentionally
     * destructive change in this migration set; everything else here is
     * purely additive.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        $gajId = DB::table('companies')->where('name', 'GAJ')->value('id');
        if ($gajId) {
            DB::table('employees')->whereNull('company_id')->update(['company_id' => $gajId]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['nik']);
            $table->dropColumn('nik');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // NOTE: nik data cannot be restored once dropped -- this down()
            // only restores the column shape, not the original values.
            $table->string('nik')->unique()->nullable()->after('employee_id');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
