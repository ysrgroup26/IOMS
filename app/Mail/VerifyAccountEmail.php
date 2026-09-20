<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v2.74.0 -- confirm the email address on a new IOMS ACCOUNT.
 *
 * Distinct from `VerifyRegistrationEmail`, which belongs to the legacy
 * pay-first flow and verifies a `TenantRegistration` (an order). This one
 * verifies a person's identity, before and independently of any
 * organization or subscription existing -- which is the whole point of
 * the v2.74.0 split.
 *
 * Kept as a separate Mailable rather than a shared one with a flag: the
 * two emails say genuinely different things. This one must NOT mention a
 * plan, a price or an activation, because at this moment none of those
 * exist and implying otherwise is how a signup confirmation reads as an
 * invoice.
 */
class VerifyAccountEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $url,
        /** Minutes until the signed link stops working, for the security note. */
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        // Sent FROM noreply -- nobody should reply to a verification link
        // -- but a reply still reaches a human rather than bouncing.
        return new Envelope(
            from: new Address(config('ioms.emails.noreply'), 'IOMS'),
            replyTo: [new Address(config('ioms.emails.hello'), 'IOMS')],
            subject: 'Confirm your email address for IOMS',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-account',
            with: [
                'user' => $this->user,
                'url' => $this->url,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }
}
