<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PPE Replacement Request MVP (v1.6.8). Fills the one real gap in the
     * existing PPE lifecycle (issued -> in_use -> replacement_requested ->
     * replacement_approved -> replacement_completed -> archived): there
     * was no action anywhere that actually CREATED a replacement_requested
     * record. This is that action, built as a lightweight MVP -- no
     * approval workflow engine, no notifications (both explicitly
     * deferred future features). Creating a request here also flips each
     * selected EmployeePpe's status to replacement_requested, so the
     * existing lifecycle and this new request record stay in sync.
     */
    public function up(): void
    {
        Schema::create('ppe_replacement_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->date('request_date');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draft', 'submitted'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_replacement_requests');
    }
};
