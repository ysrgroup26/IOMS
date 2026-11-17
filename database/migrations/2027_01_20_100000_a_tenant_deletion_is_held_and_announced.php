<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 087 -- a customer's organization is deleted only after a 90-day hold,
 * announced to its administrators and reminded before the date. Additive only:
 * nullable columns that old code ignores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'deletion_scheduled_for')) {
                $table->timestamp('deletion_scheduled_for')->nullable();
                $table->timestamp('deletion_requested_at')->nullable();
                $table->unsignedBigInteger('deletion_requested_by')->nullable();
                $table->string('deletion_reason', 255)->nullable();
                $table->json('deletion_notices')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['deletion_scheduled_for', 'deletion_requested_at', 'deletion_requested_by', 'deletion_reason', 'deletion_notices']);
        });
    }
};
