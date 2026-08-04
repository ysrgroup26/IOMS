<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company-Scoped Master Data (v1.6.10). Positions already belonged
     * to a Department transitively (department_id -> company_id), but
     * per the explicit spec this adds a direct company_id column too --
     * avoids a join for the common "positions in this company" query the
     * new Settings filter and Employee Import both need, and means a
     * position can be scoped to a company even before/without a
     * specific department being chosen for it, matching department_id
     * already being nullable.
     *
     * Backfill is more accurate than the flat "assign everything to GAJ"
     * approach used when departments first got company_id: each
     * position's own department (if it has one) already has a real
     * company_id, so that's used first. Only positions with no
     * department at all fall back to GAJ, matching the same "implicit
     * single-company data" reasoning as the earlier departments
     * migration.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->text('description')->nullable()->after('name');
        });

        DB::statement('
            UPDATE positions
            INNER JOIN departments ON departments.id = positions.department_id
            SET positions.company_id = departments.company_id
            WHERE positions.company_id IS NULL
        ');

        $gajId = DB::table('companies')->where('name', 'GAJ')->value('id');
        if ($gajId) {
            DB::table('positions')->whereNull('company_id')->update(['company_id' => $gajId]);
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
