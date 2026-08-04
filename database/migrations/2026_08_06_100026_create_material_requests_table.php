<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Material Request MVP (v1.6.8). Deliberately department-agnostic --
     * `department_id` is just which department is asking, not a module
     * scoped to HSE. No approval_status beyond draft/submitted: this is
     * explicitly NOT a workflow engine (that's a future version, per
     * spec). `project_id` is nullable since not every material request
     * is tied to a specific project (e.g. general office supplies).
     */
    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->date('request_date');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draft', 'submitted'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
