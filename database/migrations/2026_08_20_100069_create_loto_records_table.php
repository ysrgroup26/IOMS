<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream B8 (LOTO -- Lockout/Tagout). Optionally
     * linked to a `permits_to_work` row (a LOTO is usually done under a
     * PTW, but can also be a standalone isolation record). `isolation_points`
     * is a JSON list of {equipment, type, tag_number, location} -- same
     * "edited as one whole record, not independently queried" reasoning
     * as HIRADC/JSA's own JSON columns.
     */
    public function up(): void
    {
        Schema::createIfMissing('loto_records', function (Blueprint $table) {
            $table->id();
            $table->string('loto_number')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('permit_to_work_id')->nullable()->constrained('permits_to_work')->nullOnDelete();
            $table->string('equipment_name');
            $table->json('isolation_points')->nullable();
            $table->foreignId('applied_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('applied_at');
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('removed_at')->nullable();
            $table->string('status')->default('isolated');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loto_records');
    }
};
