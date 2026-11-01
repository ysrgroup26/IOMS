<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.141.0 -- A13 (approved by the owner): an APPEND-ONLY record of what
 * changed, field by field, with the value before and after.
 *
 * ActivityLog stays the human-readable feed ("Permit moved from Draft to
 * Submitted."); this table is the evidence underneath it. Rows are written
 * by App\Models\Concerns\RecordsChanges and never updated or deleted by the
 * application (RecordChange refuses both; there is no route that tries).
 * Additive: one new table, nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            // created / updated / deleted / restored
            $table->string('event', 20);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            // { field: [before, after] } -- secrets redacted before writing.
            $table->json('diff')->nullable();
            // Full redacted attributes at a state transition, for models that ask for it (PTW).
            $table->json('snapshot')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_title')->nullable();
            // { channel: web|console|queue, route, ip, request_id }
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_changes');
    }
};
