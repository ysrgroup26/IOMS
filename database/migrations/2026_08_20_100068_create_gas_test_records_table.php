<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B7 (Gas Test). Child of `permits_to_work` --
     * unlike HIRADC/JSA's items (edited/viewed as one whole document),
     * multiple gas readings ARE genuinely time-series/individually
     * meaningful (a permit is periodically re-tested through its
     * duration), so this is a real child table, not JSON. `company_id` is
     * still carried directly (not just derived through the permit) for
     * the same uniform-tenant-safety reason as `corrective_actions`.
     */
    public function up(): void
    {
        Schema::createIfMissing('gas_test_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permit_to_work_id')->constrained('permits_to_work')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->dateTime('tested_at');
            $table->foreignId('tested_by')->constrained('users')->restrictOnDelete();
            $table->decimal('o2_level', 5, 2)->nullable();
            $table->decimal('lel_level', 5, 2)->nullable();
            $table->decimal('h2s_level', 6, 2)->nullable();
            $table->decimal('co_level', 6, 2)->nullable();
            $table->string('result')->default('pass');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gas_test_records');
    }
};
