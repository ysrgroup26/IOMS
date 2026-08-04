<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PPE Distribution AND PPE History are the same table -- "Distribution"
     * is simply the create action; "History" is this table's list view per
     * employee. Storing them separately would duplicate data, which the
     * spec explicitly asks to avoid.
     *
     * expiry_date is computed at issuance time from ppe_types.
     * replacement_interval_months (null interval -> null expiry_date,
     * e.g. Harness/Headlamp -- still recorded here for history, just with
     * no expiry). `status` is the lifecycle state set by HSE/Super Admin;
     * "is it due for replacement" is computed dynamically from expiry_date
     * rather than relying on a cron-updated status, to avoid stale data.
     */
    public function up(): void
    {
        Schema::create('employee_ppe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ppe_type_id')->constrained()->restrictOnDelete();
            $table->date('issued_date');
            $table->date('expiry_date')->nullable(); // null when ppe_types.replacement_interval_months is null
            $table->enum('status', ['active', 'replaced', 'returned'])->default('active')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'ppe_type_id']);
            $table->index(['ppe_type_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_ppe');
    }
};
