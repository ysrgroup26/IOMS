<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expands the two-role system (admin, hrd) into four roles
     * (super_admin, hse, hrd, manager). Existing users are remapped, not deleted:
     *   - 'admin' -> 'super_admin'  (full access, unchanged capability)
     *   - 'hrd'   -> 'hrd'          (read-only, unchanged)
     *
     * MySQL doesn't support altering an ENUM's value set directly via
     * Doctrine DBAL in a portable way, so we widen the column to a plain
     * VARCHAR (still indexed) and rely on application-level validation
     * (see StoreUser/UpdateUser request rules) instead of a DB-level enum.
     * This avoids a risky enum-rewrite migration and keeps the change
     * purely additive from the data's perspective.
     */
    public function up(): void
    {
        // v2.37.0 (Master Audit, P1): MySQL-only DDL. `MODIFY COLUMN` is not
        // portable and blocked ANY non-MySQL test database from provisioning
        // (the real root cause behind this project having no test harness).
        // Guarded rather than rewritten so the MySQL path stays byte-identical;
        // on SQLite the column is dynamically typed, so widening is a no-op.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'hrd'");
        }

        DB::table('users')->where('role', 'admin')->update(['role' => 'super_admin']);
        // 'hrd' rows require no change.
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'super_admin')->update(['role' => 'admin']);
        DB::table('users')->whereIn('role', ['hse', 'manager'])->update(['role' => 'hrd']);

        // v2.37.0 (Master Audit, P1): MySQL-only DDL. `MODIFY COLUMN` is not
        // portable and blocked ANY non-MySQL test database from provisioning
        // (the real root cause behind this project having no test harness).
        // Guarded rather than rewritten so the MySQL path stays byte-identical;
        // on SQLite the column is dynamically typed, so widening is a no-op.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','hrd') NOT NULL DEFAULT 'hrd'");
        }
    }
};
