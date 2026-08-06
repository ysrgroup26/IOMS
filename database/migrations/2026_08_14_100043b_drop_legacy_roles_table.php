<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 2 (RBAC): the original `roles` table (from
     * 2024_01_01_000002_create_roles_table) was always reference/lookup
     * data only -- its own docblock says so explicitly, and
     * `users.role` (a plain string column) was the real authorization
     * source of truth the whole time. Nothing in app/ or resources/js/
     * reads from it outside its own seeder (verified by grep before
     * writing this migration) -- its `permissions` JSON column was never
     * actually enforced anywhere. It's replaced here by
     * spatie/laravel-permission's own `roles` table (created in the very
     * next migration), which IS actually enforced. Dropped, not kept
     * alongside, to avoid two tables both named "roles" with completely
     * different meanings -- exactly the kind of naming collision that
     * caused real confusion earlier in this project's navigation work.
     */
    public function up(): void
    {
        Schema::dropIfExists('roles');
    }

    public function down(): void
    {
        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });
    }
};
