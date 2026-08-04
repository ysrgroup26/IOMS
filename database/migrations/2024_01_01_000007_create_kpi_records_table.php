<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Core transactional table. Every KPI "occurrence" is one row here.
     * Department is denormalized onto the record (in addition to being
     * derivable via employee->department) so that historical reports stay
     * accurate even if an employee is later transferred to another department --
     * this matches how the Excel sheet the app replaces behaves.
     */
    public function up(): void
    {
        Schema::create('kpi_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('kpi_category_id')->constrained()->restrictOnDelete();
            $table->date('record_date'); // actual date of the activity/incident
            $table->unsignedTinyInteger('month'); // 1-12, derived from record_date, indexed for fast report grouping
            $table->unsignedSmallInteger('year'); // derived from record_date
            $table->unsignedInteger('quantity')->default(1); // always +1 per KPI rule, kept as a column for edge-case corrections
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['year', 'month']);
            $table->index(['employee_id', 'kpi_category_id', 'year', 'month']);
            $table->index(['department_id', 'kpi_category_id', 'year', 'month'], 'kpi_dept_cat_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_records');
    }
};
