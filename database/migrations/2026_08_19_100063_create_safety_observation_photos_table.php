<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B1. Evidence photos -- mirrors
     * `daily_report_photos` exactly (dedicated child-row-per-photo, not a
     * single `photo_path` scalar column on the parent), the established
     * pattern for any module needing multiple photos per record.
     */
    public function up(): void
    {
        Schema::createIfMissing('safety_observation_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safety_observation_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path');
            $table->string('caption')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_observation_photos');
    }
};
