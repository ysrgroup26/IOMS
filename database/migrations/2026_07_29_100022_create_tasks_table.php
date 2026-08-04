<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Universal Task Engine Foundation (v1.6.4). This is deliberately
     * scoped to the `tasks` table only -- comments, attachments, history,
     * timeline, and notifications are explicitly out of scope for this
     * version and are planned for future releases (see ROADMAP.md).
     *
     * `related_module` + `related_record_id` are a lightweight polymorphic
     * link (string module name + generic ID, not a formal Eloquent
     * polymorphic relation) so any future module can attach tasks to its
     * own records without this table needing to know about them.
     *
     * `company_id` and `workspace_id` are both nullable: company_id links
     * to the existing `companies` table (GAJ/Maintenance) when a task is
     * company-specific; `workspace_id` has no backing table yet (no
     * Workspace concept exists in this app -- see ROADMAP.md), so it's
     * stored as a plain nullable unsigned integer now, ready for a real
     * foreign key once/if a workspaces table is introduced, rather than
     * blocking the whole Task Engine on a feature that doesn't exist.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('task_number')->unique();
            $table->string('title');
            $table->text('description')->nullable();

            $table->string('priority')->default('medium'); // low, medium, high, critical
            $table->string('status')->default('draft'); // draft, open, in_progress, on_hold, waiting, completed, cancelled

            $table->string('task_type')->nullable(); // free-form categorization, e.g. "general", "follow_up"
            $table->string('task_source')->default('manual'); // manual | system (future modules creating tasks automatically)

            // Lightweight polymorphic link -- see class docblock above.
            $table->string('related_module')->nullable();
            $table->unsignedBigInteger('related_record_id')->nullable();

            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('workspace_id')->nullable(); // no workspaces table exists yet -- see docblock

            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('due_date')->nullable();
            $table->date('start_date')->nullable();
            $table->timestamp('completed_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'due_date']);
            $table->index(['assigned_user_id', 'status']);
            $table->index(['related_module', 'related_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
