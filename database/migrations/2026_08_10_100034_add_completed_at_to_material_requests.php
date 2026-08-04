<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Complete Material Request Workflow (v1.6.9.1). The Detail Page
     * spec explicitly asks for "Completion Date" -- `updated_at` isn't
     * a reliable stand-in for this (any edit updates it, not just the
     * transition to Completed), so this is a real, dedicated column set
     * once, specifically when the request actually completes.
     */
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
