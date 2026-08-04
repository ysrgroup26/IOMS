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
        DB::statement("ALTER TABLE material_requests MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE material_requests MODIFY COLUMN status ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft'");
    }
};
