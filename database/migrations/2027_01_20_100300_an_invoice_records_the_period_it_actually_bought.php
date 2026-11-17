<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 091 -- a renewal paid after the subscription lapsed buys a period that
 * starts on the payment date, not the billed one. The invoice records the
 * period actually granted and the end of the period before it. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'service_start')) {
                $table->date('service_start')->nullable();
                $table->date('service_end')->nullable();
                $table->date('previous_period_end')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['service_start', 'service_end', 'previous_period_end']);
        });
    }
};
