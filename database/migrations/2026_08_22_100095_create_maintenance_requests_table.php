<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Milestone 4, Acceleration Part 2 (Maintenance CMMS Foundation). Real, operational -- NOT full SAP PM (per the spec's own explicit instruction). */
    public function up(): void
    {
        Schema::createIfMissing('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->string('problem');
            $table->text('description')->nullable();
            $table->string('priority')->default('medium');
            $table->date('request_date');
            $table->string('attachment_path')->nullable();
            $table->string('status')->default('reported');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
    }
};
