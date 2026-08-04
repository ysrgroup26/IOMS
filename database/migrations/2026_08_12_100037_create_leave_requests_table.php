<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Second real consumer of the Universal Approval Engine + Workflow
     * Engine, alongside Material Request -- HR (v1.10.0). Deliberately
     * simpler than Material Request: no line items, no processing step
     * (approved leave doesn't need a "warehouse" hand-off), so the
     * lifecycle is draft -> submitted -> approved/rejected -> cancelled.
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('leave_number')->unique();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('leave_type'); // annual, sick, unpaid, other
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('days');
            $table->text('reason')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
