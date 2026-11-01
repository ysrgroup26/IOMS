<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.141.0 -- THE NOTIFICATION EVENT LAYER (Operational Experience
 * initiative, section 3). Additive only.
 *
 *   notifications                 + event_key, priority, group_key, dedupe_key
 *   notification_deliveries       one row per channel attempt: status, attempts, last error
 *   user_notification_preferences per person and category: in-app and push on or off
 *
 * Existing rows keep working: event_key is null for the notifications sent
 * before this release, and priority defaults to `normal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('event_key', 64)->nullable()->after('category');
            $table->string('priority', 16)->default('normal')->after('event_key');
            $table->string('group_key', 160)->nullable()->after('priority');
            $table->string('dedupe_key', 190)->nullable()->after('group_key');
            $table->index(['user_id', 'dedupe_key']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('devices')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['notification_id', 'channel']);
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->boolean('in_app')->default(true);
            $table->boolean('push')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('notification_deliveries');
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'dedupe_key']);
            $table->dropColumn(['event_key', 'priority', 'group_key', 'dedupe_key']);
        });
    }
};
