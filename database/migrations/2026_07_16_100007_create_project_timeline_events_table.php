<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Powers the Project Detail Timeline (e.g. "16 Jul - Project Created",
     * "17 Jul - Inspection", "18 Jul - Gas Test"). Uses a polymorphic
     * subject so that future modules (Inspection, Gas Test, Permit, Daily
     * Report, Waste, Incident, Nearmiss, etc. -- none built yet, V2 scope)
     * can each write a row here referencing their own model, without any
     * schema change to this table. Today, only "Project Created" events
     * are written (from ProjectController@store); everything else is a
     * ready architecture, not yet populated.
     */
    public function up(): void
    {
        Schema::create('project_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event_type'); // e.g. 'project_created', 'inspection', 'gas_test', 'permit', 'daily_report', 'waste_disposal'
            $table->string('title'); // display label, e.g. "Project Created", "Gas Test"
            $table->text('description')->nullable();
            $table->date('event_date');
            $table->string('subject_type')->nullable(); // future: App\Models\Inspection, App\Models\GasTest, etc.
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'event_date']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_timeline_events');
    }
};
