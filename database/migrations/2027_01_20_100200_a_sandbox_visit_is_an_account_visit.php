<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 089 -- the Sandbox is entered from an IOMS account, and the visit is
 * recorded on that account so the team can follow up. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'sandbox_visits')) {
                $table->unsignedInteger('sandbox_visits')->default(0);
                $table->timestamp('sandbox_last_entered_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sandbox_visits', 'sandbox_last_entered_at']);
        });
    }
};
