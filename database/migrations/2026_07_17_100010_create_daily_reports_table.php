<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily HSE Report: "one report = one day" per spec, enforced via a
     * unique (project_id, report_date) index. Deliberately does NOT store
     * manpower or PPE -- those already live in project_manpower and
     * employee_ppe respectively; duplicating them here would violate the
     * "one piece of information, one module" principle.
     */
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->date('report_date');
            $table->enum('report_type', ['normal', 'overtime'])->default('normal');
            $table->text('findings')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
