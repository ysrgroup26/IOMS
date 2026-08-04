<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference/lookup table for roles. Not the source of truth for
     * authorization today (users.role enum is), but exists so that:
     *  - Settings > User Management can display role metadata (label, description)
     *  - Future versions can add granular roles without touching users table shape
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. 'admin', 'hrd'
            $table->string('label');
            $table->string('description')->nullable();
            $table->json('permissions')->nullable(); // future-ready granular perms
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
