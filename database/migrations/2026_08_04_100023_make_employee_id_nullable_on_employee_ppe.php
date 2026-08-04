<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foundation step for future inventory management (v1.6.7 PPE status
     * review), NOT the complete inventory feature itself.
     *
     * The current schema structurally cannot represent "this PPE item
     * exists in company inventory but hasn't been assigned to an
     * employee yet" -- employee_id has always been a required column, so
     * every row is inherently employee-linked from creation. That's the
     * real gap behind the "Issued" status discussion: the clarified
     * meaning ("already in inventory, not yet assigned") needs a record
     * that can exist with no employee at all, which the current
     * NOT NULL constraint makes impossible.
     *
     * This migration only removes that structural blocker -- it does NOT
     * change what any EXISTING row means. Every current record keeps its
     * real employee_id exactly as before (nullable doesn't touch existing
     * NOT NULL data), and the existing issued/in_use/replacement_*
     * lifecycle for already-assigned PPE is completely unchanged. A
     * genuinely separate "unassigned inventory" browsing/assignment UI is
     * a distinct future feature, deliberately not built here -- this is
     * the foundation it would need, not the feature itself.
     */
    public function up(): void
    {
        Schema::table('employee_ppe', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('employee_ppe', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_ppe', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('employee_ppe', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable(false)->change();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
