<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Milestone 4, Workstream C3. Invited-vendor pivot with a real response-status lifecycle, not just a plain many-to-many. */
    public function up(): void
    {
        Schema::createIfMissing('rfq_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('invited');
            $table->dateTime('invited_at')->nullable();
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_vendors');
    }
};
