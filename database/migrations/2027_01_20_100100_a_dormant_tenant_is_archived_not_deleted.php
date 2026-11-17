<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 088 -- a dormant organization is archived (hidden, intact, restored on the
 * next sign-in), never deleted for inactivity. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
