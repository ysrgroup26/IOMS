<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.129.0 -- WHERE TO REACH A PERSON WHO IS NOT LOOKING AT IOMS.
 *
 * A notification row (`notifications`) is the RECORD: it is what the
 * Notification Center lists, what survives a lost connection, and what a
 * permit's history can be read against. This table is something else
 * entirely -- a DELIVERY ADDRESS for a browser that has agreed to be woken
 * up, which is what makes a permit waiting for approval reach a supervisor
 * whose phone is in their pocket.
 *
 * ONE ROW PER BROWSER, NOT PER PERSON. The Push API issues a subscription
 * per browser profile per installation: a supervisor with a phone and a
 * desk machine has two, and replacing one with the other would silently
 * stop telling them on the device they are actually holding. `endpoint` is
 * unique because it IS the address -- the same browser re-subscribing
 * presents the same endpoint, and that must update the row rather than
 * accumulate copies (reconnect and re-subscribe are routine, so a
 * non-unique endpoint column becomes thousands of duplicate pushes).
 *
 * WHY `p256dh` AND `auth` ARE STORED THOUGH NOTHING READS THEM YET. They
 * are the device's own encryption keys, handed over once at subscription
 * time, and they are what an ENCRYPTED push payload would be sealed with.
 * This release sends payloadless pushes deliberately (see WebPushService:
 * a tickle carries no operational data past the lock screen, and the
 * service worker fetches the real notification over the authenticated
 * session). Storing the keys now costs two columns; NOT storing them would
 * mean asking every device to re-subscribe to add payloads later, which is
 * a permission dance the person has already been through.
 *
 * DELETION IS ORDINARY. A push service answers 404 or 410 for an endpoint
 * that no longer exists -- an uninstalled app, revoked permission, a
 * cleared profile -- and that row is deleted on the spot. Nothing about a
 * person's history depends on it; the notification rows stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();

            // Cascades: a deleted account has no devices. The notification
            // rows are the record and are handled by their own table.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The push service's own URL for this browser. Long: FCM and
            // Mozilla autopush both issue endpoints well past 255 bytes,
            // and a silently truncated endpoint is an address that answers
            // nothing forever. TEXT with a hash index, because MySQL
            // cannot index a TEXT column's full length.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            // The device's encryption keys. Nullable because a browser may
            // hand over a subscription without them, and a tickle does not
            // need them. See the note above on why they are kept.
            $table->string('p256dh', 255)->nullable();
            $table->string('auth', 255)->nullable();

            // What asked. Purely so an administrator can tell a phone from
            // a desk machine when revoking one; never used for routing.
            $table->string('user_agent', 255)->nullable();

            // When a push was last accepted by the push service. A device
            // that has not been reached in months is a candidate for
            // pruning, which is a decision for later and not taken here.
            $table->timestamp('last_pushed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
