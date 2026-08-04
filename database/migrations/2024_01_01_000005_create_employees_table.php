<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->unique(); // internal employee ID, e.g. EMP-0001
            $table->string('nik')->unique(); // national ID number
            $table->string('full_name');
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['active', 'inactive', 'resigned'])->default('active')->index();
            $table->string('photo_path')->nullable();
            $table->date('join_date')->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();
            $table->softDeletes(); // keep KPI history intact even if employee record is archived

            $table->index(['department_id', 'status']);
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
