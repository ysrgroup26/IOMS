<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Milestone 4, Acceleration Part 3. Mirrors DailyReportPhoto/SafetyObservationPhoto's dedicated-child-row pattern. */
    public function up(): void
    {
        Schema::createIfMissing('inspection_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_request_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path');
            $table->string('caption')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_evidence');
    }
};
