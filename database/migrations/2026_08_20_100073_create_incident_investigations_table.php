<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B14 (Incident Management Enhancement --
     * Investigation). One-to-one enhancement of the EXISTING `incidents`
     * table (2026_08_12_100038) -- not a duplicate incident-tracking
     * system. `company_id` is carried directly (copied from the parent
     * incident) for the same uniform-tenant-safety reason as
     * `corrective_actions`, since `incidents.company_id` is nullable
     * (that table's own older convention) and this new table shouldn't
     * inherit that ambiguity.
     */
    public function up(): void
    {
        Schema::createIfMissing('incident_investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method')->default('5_why');
            $table->text('root_cause')->nullable();
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->foreignId('investigator_id')->constrained('users')->restrictOnDelete();
            $table->date('investigated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_investigations');
    }
};
