<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redesigns the PPE lifecycle (v1.5.1) from the confusing
     * active/replaced/returned into a clear business workflow:
     *
     *   issued -> in_use -> replacement_requested -> replacement_approved
     *   -> replacement_completed -> archived
     *
     * "Expired" is NOT part of this manual chain -- it's a computed
     * overlay (see EmployeePpe::getEffectiveStatusAttribute()) that only
     * applies while status is still issued/in_use and the expiry date has
     * passed. It's never stored as the lifecycle status itself, and
     * replacement is never triggered automatically by expiry -- it always
     * remains a manual, explicit business process (request -> approve ->
     * complete), matching the spec exactly.
     *
     * Existing data remapping (no data lost, nothing silently dropped):
     *   'active'   -> 'in_use'   (an actively-issued item is now "in use")
     *   'replaced' -> 'archived' (already superseded -- terminal state)
     *   'returned' -> 'archived' (no longer assigned -- terminal state)
     *
     * Uses the same VARCHAR-widening approach as
     * 2026_07_16_100004_expand_user_roles_table (portable across MySQL
     * versions without requiring doctrine/dbal for an ENUM rewrite).
     */
    public function up(): void
    {
        // v2.37.0 (Master Audit, P1): MySQL-only DDL. `MODIFY COLUMN` is not
        // portable and blocked ANY non-MySQL test database from provisioning
        // (the real root cause behind this project having no test harness).
        // Guarded rather than rewritten so the MySQL path stays byte-identical;
        // on SQLite the column is dynamically typed, so widening is a no-op.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE employee_ppe MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'issued'");
        }

        DB::table('employee_ppe')->where('status', 'active')->update(['status' => 'in_use']);
        DB::table('employee_ppe')->where('status', 'replaced')->update(['status' => 'archived']);
        DB::table('employee_ppe')->where('status', 'returned')->update(['status' => 'archived']);
    }

    public function down(): void
    {
        DB::table('employee_ppe')->where('status', 'in_use')->update(['status' => 'active']);
        DB::table('employee_ppe')->whereIn('status', [
            'replacement_requested', 'replacement_approved', 'replacement_completed', 'archived',
        ])->update(['status' => 'replaced']);
        // 'issued' has no clean pre-v1.5.1 equivalent; maps to 'active' as
        // the closest prior meaning (a record that exists and isn't
        // replaced/returned).
        DB::table('employee_ppe')->where('status', 'issued')->update(['status' => 'active']);

        // v2.37.0 (Master Audit, P1): MySQL-only DDL. `MODIFY COLUMN` is not
        // portable and blocked ANY non-MySQL test database from provisioning
        // (the real root cause behind this project having no test harness).
        // Guarded rather than rewritten so the MySQL path stays byte-identical;
        // on SQLite the column is dynamically typed, so widening is a no-op.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE employee_ppe MODIFY COLUMN status ENUM('active','replaced','returned') NOT NULL DEFAULT 'active'");
        }
    }
};
