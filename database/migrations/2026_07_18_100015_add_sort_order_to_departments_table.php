<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable display order for Departments (v1.3.1). Defaults to 0
     * for all existing rows so nothing changes until Super Admin/HSE
     * explicitly sets an order via Settings -- lists fall back to
     * alphabetical (by name) as the tiebreaker, so behavior is unchanged
     * for anyone who never touches this field.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
