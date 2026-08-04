<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces "HSE Officer" (a dropdown tied to Employee Master, scoped
     * to the HSE department) with a simple free-text "Department" field,
     * per v1.5.1: "This report represents a department rather than an
     * individual... every company can type their own department names
     * without maintaining a master list."
     *
     * `hse_officer_id` is NOT dropped -- it was already nullable, so
     * existing historical reports keep their HSE Officer attribution
     * intact for reporting/audit purposes. It's simply no longer
     * required or shown in the create/edit UI; new reports use
     * `department_name` instead.
     */
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->string('department_name')->nullable()->after('hse_officer_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', function (Blueprint $table) {
            $table->dropColumn('department_name');
        });
    }
};
