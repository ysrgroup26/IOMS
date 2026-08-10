<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 2. `maintenance_request_id` nullable
     * -- a Work Order normally converts FROM an approved
     * MaintenanceRequest, but Preventive Maintenance (scheduled, not
     * reactive) can raise a Work Order directly with no request behind
     * it. `technician_id` points at `employees` (the person doing the
     * physical work), not `users` -- same Employee-vs-User distinction as
     * Asset's own `responsible_employee_id`.
     */
    public function up(): void
    {
        Schema::createIfMissing('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('wo_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('maintenance_type')->default('corrective');
            $table->foreignId('technician_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('planned_date');
            $table->date('actual_date')->nullable();
            $table->text('work_description')->nullable();
            $table->text('completion_notes')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
