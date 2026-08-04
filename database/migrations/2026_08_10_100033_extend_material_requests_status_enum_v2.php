<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Complete Material Request Workflow (v1.6.9.1). Extends the
     * previous enum (draft/submitted/approved/rejected/completed, added
     * in v1.6.9 for the Approval Engine) with `processing` and
     * `cancelled` -- the two states this session's full lifecycle needs
     * that didn't exist yet. "Pending Approval" is deliberately NOT a
     * separate stored value here -- see
     * docs/ADR/006-material-request-workflow.md for why it's a label
     * applied to the existing `submitted` status while a pending
     * Approval exists, not a distinct database state.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE material_requests MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE material_requests MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'completed') NOT NULL DEFAULT 'draft'");
    }
};
