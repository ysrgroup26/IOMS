<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 3 (Project Management). Extends the
     * EXISTING `projects` table (a deliberately simple grouping container
     * per its own model doc comment) rather than creating a second
     * Project entity -- `project_code`/`client`/`location`/`manager_id`
     * are all additive and nullable, so every existing project keeps
     * working unmodified.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('project_code')->nullable()->after('id');
            $table->string('client')->nullable()->after('vessel_name');
            $table->string('location')->nullable()->after('client');
            $table->foreignId('manager_id')->nullable()->after('location')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['project_code', 'client', 'location']);
        });
    }
};
