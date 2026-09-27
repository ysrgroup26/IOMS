<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.80.0 -- THE SUPPORT QUEUE. A WORK LIST, NOT A MAILBOX.
     *
     * Customer support happened in somebody's email client. Nothing recorded
     * that a customer had asked something, whether anyone had answered, who
     * owned it, or how long it had been waiting -- so the questions an
     * operator cannot answer from an inbox ("what is unanswered right now?")
     * had no answer at all.
     *
     * A ticket is therefore a STATE MACHINE with a conversation attached,
     * not a copy of an email thread:
     *
     *   open             somebody is waiting for us
     *   in_progress      we picked it up
     *   waiting_customer we answered; the ball is theirs
     *   resolved         answered and believed done
     *   closed           finished, no longer worked
     *
     * The two transitions that carry the whole design are automatic, because
     * the failure mode of a manual status is a ticket parked in the wrong
     * one: a CUSTOMER message pulls a ticket back to open, and a SUPPORT
     * message pushes it to waiting_customer.
     *
     * DELIBERATELY PLATFORM-OWNED, NOT TENANT-SCOPED. `tenant_id` is
     * NULLABLE and carries no scope, which is the opposite of every
     * operational table in IOMS -- and it has to be:
     *
     *  - a message from an unrecognised address has no tenant yet, and a
     *    tenant-scoped table would hide precisely the rows that need human
     *    attention;
     *  - the queue belongs to the IOMS operator, who has no tenant at all.
     *
     * Access is therefore enforced by ROLE at the route (`role:platform_admin`)
     * rather than by a scope, and a test asserts a tenant administrator is
     * refused. No tenant-side surface reads these tables.
     *
     * SEPARATE FROM NOTIFICATIONS on purpose. A notification is something
     * IOMS tells its operator; a ticket is something a customer is waiting
     * for. Merging them would bury the second in the first (ADR 042).
     */
    public function up(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 24)->unique();

                // WHO IS ASKING. The email address is the identity that
                // always exists; the tenant link is an interpretation of it
                // and may be absent or added later by an operator.
                $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
                $table->string('requester_email');
                $table->string('requester_name')->nullable();

                $table->string('subject');
                $table->string('status', 24)->default('open');
                $table->string('priority', 12)->default('normal');

                // The operator who owns it. Nullable: an unassigned ticket is
                // a real and important state, not a data defect.
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

                // How it arrived. `email` is a message an operator logged from
                // the support mailbox; `manual` is one raised on a customer's
                // behalf (a phone call, a meeting). Automated ingestion will
                // add its own value rather than pretend to be one of these.
                $table->string('channel', 20)->default('email');

                // The clock the queue is ordered by. Stored because they are
                // facts about messages that happened, not derivable from the
                // ticket row -- but ticket AGE is always derived from them.
                $table->timestamp('last_customer_reply_at')->nullable();
                $table->timestamp('last_support_reply_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();

                $table->timestamps();

                $table->index(['status', 'last_customer_reply_at']);
                $table->index('requester_email');
                $table->index('tenant_id');
            });
        }

        if (! Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();

                // `inbound` = from the customer. `outbound` = from IOMS.
                // The direction is what moves the ticket's status, so it is a
                // column rather than something inferred from author_id being
                // null (which would make a logged customer message and a
                // system-generated one indistinguishable).
                $table->string('direction', 10);

                // The IOMS user who wrote an outbound message. Null for an
                // inbound one, and for anything the system sends itself.
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('author_name')->nullable();
                $table->string('author_email')->nullable();

                $table->text('body');

                // When it actually left IOMS. Null on an outbound row means
                // the email was not sent -- a visible failure, not a silent
                // one.
                $table->timestamp('sent_at')->nullable();

                $table->timestamps();

                $table->index(['support_ticket_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
