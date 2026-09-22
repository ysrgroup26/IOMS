<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.78.0 -- WHICH LIFECYCLE EMAIL WAS LAST SENT, FOR WHICH PERIOD.
     *
     * Holds "<state>:<period end date>", e.g. "grace:2026-09-18". The grace
     * and lapsed emails are each sent ONCE per period: the nightly command
     * sends one only when the current key differs from this column, then
     * stores it. Paying extends the period end, which changes the key, so
     * the next period's notices re-arm on their own -- no reset step that
     * could be forgotten.
     *
     * A string rather than a timestamp because the question is "was THIS
     * notice sent for THIS period", which a date alone cannot answer.
     * Nullable and additive; nothing reads it but the lifecycle command.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('subscriptions', 'lifecycle_notified')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('lifecycle_notified', 40)->nullable()->after('renewal_reminded_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'lifecycle_notified')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('lifecycle_notified');
            });
        }
    }
};
