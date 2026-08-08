<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 3 (Report Center, Task #65). A schedule is "generate dataset
 * X, in format Y, every Z, and notify the owning user it's ready" --
 * deliberately NOT an email-delivery system (no Mail transport exists
 * anywhere in this app today, see docs/ADR/020-report-center.md); the
 * generated file lands in storage and the user is notified through the
 * already-real Notification Center, which they already check.
 *
 * tenant_id + company_id follow the same pattern as numbering_formats/
 * approval_flows (docs/ADR/018) -- tenant_id is required so a schedule
 * never leaks across tenants; company_id is nullable (tenant-wide report
 * when null, one company's data only when set).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dataset_key');
            $table->string('format'); // csv | excel | pdf
            $table->string('frequency'); // daily | weekly | monthly
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
