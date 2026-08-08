<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (Notification Center). Deliberately a plain app-owned
     * table, not Laravel's built-in `notifications` (database) channel --
     * this app doesn't use Notifiable/ShouldQueue mailables/broadcast
     * channels anywhere yet, and a plain table keeps `NotificationService`
     * simple to reason about and query (unread count, category filters)
     * without pulling in the full notification-channel abstraction for a
     * single in-app channel. `notifiable_type`/`notifiable_id` (nullable
     * morph) links back to the record the notification is about, e.g. an
     * Approval or the approvable itself, so the frontend can deep-link.
     */
    public function up(): void
    {
        Schema::createIfMissing('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category'); // approval, reminder, warning, success, information
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->nullableMorphs('notifiable');
            $table->json('meta')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
