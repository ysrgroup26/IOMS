<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Universal Approval Engine (v1.6.9). MaterialRequest's status
     * column was only ever draft/submitted (the MVP scope from v1.6.8,
     * before any approval concept existed) -- extending it here to the
     * full Draft -> Submitted -> Approved -> Rejected -> Completed
     * vocabulary this engine introduces. Uses raw SQL to modify the enum
     * in place (Laravel's schema builder has no first-class "add enum
     * value" operation) -- existing draft/submitted rows are completely
     * unaffected, this only adds new allowed values.
     */
    public function up(): void
    {
        // v2.37.0 (Master Audit, P1): MySQL-only DDL. `MODIFY COLUMN` is not
        // portable and blocked ANY non-MySQL test database from provisioning
        // (the real root cause behind this project having no test harness).
        // Guarded rather than rewritten so the MySQL path stays byte-identical;
        // on SQLite the column is dynamically typed, so widening is a no-op.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE material_requests MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        // v2.37.0 (Master Audit, P1): MySQL-only DDL. `MODIFY COLUMN` is not
        // portable and blocked ANY non-MySQL test database from provisioning
        // (the real root cause behind this project having no test harness).
        // Guarded rather than rewritten so the MySQL path stays byte-identical;
        // on SQLite the column is dynamically typed, so widening is a no-op.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE material_requests MODIFY COLUMN status ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft'");
        }
    }
};
