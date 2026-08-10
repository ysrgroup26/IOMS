<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Acceleration Part 5 (Visitor Management). `host_employee_id`
     * points at the existing `employees` table -- no duplicate "who works
     * here" concept. `hse_induction_completed` is the real integration
     * point with HSE (a visitor should be inducted before/at check-in on
     * an industrial site) -- a plain boolean + timestamp is the honest,
     * un-fabricated scope here; a full induction CONTENT/quiz system is
     * not part of this foundation.
     */
    public function up(): void
    {
        Schema::createIfMissing('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('visitor_company')->nullable();
            $table->string('purpose')->nullable();
            $table->foreignId('host_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('visit_date');
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('hse_induction_completed')->default(false);
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
