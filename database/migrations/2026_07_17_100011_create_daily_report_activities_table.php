<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activities are kept as simple free-text lines (e.g. "Blasting
     * supervision") rather than a separate configurable master-data table,
     * per the spec's explicit "Keep this module SIMPLE" instruction for
     * Daily Report. If a future version needs a controlled activity-type
     * vocabulary, this table can gain an optional activity_type_id FK
     * without breaking existing rows (see ROADMAP.md).
     */
    public function up(): void
    {
        Schema::create('daily_report_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_activities');
    }
};
