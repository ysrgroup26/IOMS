<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic audit trail. Uses a polymorphic subject so it can log actions
     * on any model (employee created, KPI recorded, user deleted, settings
     * changed, backup restored, etc.) without needing a new table per entity.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // created, updated, deleted, exported, restored, quick_attendance
            $table->string('subject_type')->nullable(); // e.g. App\Models\KpiRecord
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description');
            $table->json('meta')->nullable(); // arbitrary extra context (old/new values, counts, filters used)
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
