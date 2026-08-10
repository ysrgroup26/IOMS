<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Milestone 4, Acceleration Part 1B. Rack/bin-level location within a warehouse -- optional granularity, Stock/StockMovement's own storage_location_id is nullable. */
    public function up(): void
    {
        Schema::createIfMissing('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('area')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
    }
};
