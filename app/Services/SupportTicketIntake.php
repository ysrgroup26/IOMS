<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\Tenant;
use App\Models\User;

/**
 * v2.80.0 -- HOW A CUSTOMER MESSAGE BECOMES A TICKET.
 *
 * One entry point, deliberately, because there will be more than one source:
 * an operator logging a message from the support mailbox today, and automated
 * mail ingestion later (BLOCKED -- see docs/kb/backlog/Support Inbox.md; how
 * mail reaches the application is an infrastructure decision, and inventing
 * a transport would produce code that looks finished and fails on contact).
 *
 * Putting identification and threading here rather than in the controller is
 * what makes that future adapter a thin caller instead of a second, subtly
 * different implementation of the same rules.
 *
 * IDENTIFICATION IS A LOOKUP, NOT A GUESS. The sender is matched against
 * real user accounts by email address. Nothing is inferred from a domain
 * name: two customers can share a mail provider, and attaching a ticket to
 * the wrong tenant would show one customer's commercial context beside
 * another customer's question. An unmatched sender stays unidentified and
 * goes in the queue for a human to associate -- which is a supported state,
 * not a failure.
 */
class SupportTicketIntake
{
    /**
     * Record an inbound customer message.
     *
     * Threads onto the sender's most recent OPEN conversation when there is
     * one, so a reply continues its ticket instead of starting a second one
     * about the same thing. A new subject from the same address after
     * everything was closed correctly starts a new ticket.
     */
    public function record(
        string $email,
        string $subject,
        string $body,
        ?string $name = null,
        string $channel = SupportTicket::CHANNEL_EMAIL,
        ?SupportTicket $thread = null,
    ): SupportTicket {
        $email = trim(mb_strtolower($email));

        $ticket = $thread ?? $this->openThreadFor($email);

        if (! $ticket) {
            $ticket = SupportTicket::create([
                'reference' => SupportTicket::generateReference(),
                'tenant_id' => $this->identifyTenant($email)?->id,
                'requester_email' => $email,
                'requester_name' => $name,
                'subject' => $subject !== '' ? $subject : '(tanpa subjek)',
                'status' => SupportTicket::STATUS_OPEN,
                'priority' => SupportTicket::PRIORITY_NORMAL,
                'channel' => $channel,
            ]);
        }

        // Goes through the model so the status rules apply identically no
        // matter which caller arrived here.
        $ticket->recordCustomerMessage($body, $name);

        return $ticket->fresh(['messages']);
    }

    /**
     * Which tenant this address belongs to, if any.
     *
     * Global scopes are lifted because this runs for a platform operator who
     * has no tenant of their own -- the scope would fail closed and identify
     * nobody. The result is still a single exact-email match, so lifting the
     * scope widens the lookup, not the answer.
     */
    public function identifyTenant(string $email): ?Tenant
    {
        $user = User::withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [trim(mb_strtolower($email))])
            ->whereNotNull('tenant_id')
            ->first();

        return $user?->tenant_id
            ? Tenant::withoutGlobalScopes()->find($user->tenant_id)
            : null;
    }

    /**
     * Re-run identification for a ticket whose sender was unrecognised when
     * it arrived -- the common case being a customer who wrote before their
     * account existed. Returns null when they still cannot be placed, so the
     * caller can say so rather than silently doing nothing.
     */
    public function reidentify(SupportTicket $ticket): ?Tenant
    {
        $tenant = $this->identifyTenant($ticket->requester_email);

        if ($tenant) {
            $ticket->forceFill(['tenant_id' => $tenant->id])->save();
        }

        return $tenant;
    }

    /** The sender's most recent conversation that is still live. */
    private function openThreadFor(string $email): ?SupportTicket
    {
        return SupportTicket::query()
            ->whereRaw('LOWER(requester_email) = ?', [$email])
            ->whereNotIn('status', [SupportTicket::STATUS_CLOSED])
            ->latest('id')
            ->first();
    }

    /**
     * Whether an outbound message has actually left IOMS. Used by the
     * controller after sending, so an unsent reply stays visibly unsent
     * instead of counting as an answer nobody received.
     */
    public function markSent(SupportTicketMessage $message): void
    {
        $message->forceFill(['sent_at' => now()])->save();
    }
}
