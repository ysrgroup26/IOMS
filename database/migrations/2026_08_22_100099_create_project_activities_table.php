<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 3. A tracked project activity/task
     * (name, assigned employee, progress %, status) -- deliberately a
     * DIFFERENT entity from the existing `daily_report_activities` (a
     * free-text log line inside one day's Daily Report, no owner/progress
     * of its own). This is the real "who's doing what, how far along" the
     * spec asks for; DailyReportActivity remains exactly what it already
     * was.
     */
    public function up(): void
    {
        Schema::createIfMissing('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status')->default('not_started');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
