<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B3 (Toolbox Meeting / TBM). Unlike
     * Incident/Safety Observation, a TBM record IS the record of a
     * meeting that already happened (same "log it after the fact" shape
     * as DailyReport) -- no HasWorkflow lifecycle, just conducted vs.
     * cancelled.
     */
    public function up(): void
    {
        Schema::createIfMissing('tbm_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('tbm_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic');
            $table->date('meeting_date');
            $table->string('location')->nullable();
            $table->foreignId('conducted_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->string('status')->default('conducted');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'meeting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbm_meetings');
    }
};
