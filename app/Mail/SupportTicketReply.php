<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v2.80.0 -- A SUPPORT ANSWER, SENT AS IOMS SUPPORT.
 *
 * The one mailable in IOMS that is NOT from the noreply mailbox, and that is
 * the point: a support answer exists to be replied to. From and Reply-To are
 * both support@, so the customer's reply lands in the mailbox the ticket came
 * from rather than in a black hole.
 *
 * The ticket reference is in the subject so a reply can be threaded back to
 * the same ticket by the ingestion adapter when it exists -- a subject token
 * survives forwarding and quoting, which is why it is there rather than in a
 * custom header.
 *
 * Uses the shared email design system (emails.layout, v2.58.0), so a support
 * reply looks like it came from the same company as the invoice.
 */
class SupportTicketReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public string $body,
        public string $authorName,
    ) {}

    public function envelope(): Envelope
    {
        $support = config('ioms.emails.support');

        return new Envelope(
            from: new Address($support, 'IOMS Support'),
            replyTo: [new Address($support, 'IOMS Support')],
            subject: '['.$this->ticket->reference.'] '.$this->ticket->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support-reply',
            with: [
                'ticket' => $this->ticket,
                'body' => $this->body,
                'authorName' => $this->authorName,
                'organization' => $this->ticket->tenant?->name,
            ],
        );
    }
}
