<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v2.57.0 -- the password reset email, brought onto the IOMS identity.
 *
 * FOUND WHILE PREPARING PRODUCTION SMTP, and it matters precisely because
 * of that. Until now `MAIL_MAILER` was `log`, so this message was written
 * to a file and nobody ever saw it. The moment real SMTP credentials are
 * entered it becomes the email IOMS sends most often — and it was
 * Laravel's stock `ResetPassword` notification: generic scaffolding
 * wording, no IOMS letterhead, no Reply-To, and a From address that came
 * from `MAIL_FROM_ADDRESS` rather than from the IOMS mailbox configuration
 * every other IOMS email uses.
 *
 * A customer replying to it would have been replying to `noreply@`, which
 * goes nowhere. Resetting a password is a SUPPORT conversation, so it
 * carries the support Reply-To, matching TenantActivated.
 *
 * Deliberately a Mailable on the existing `emails.layout`, not a second
 * mail system: same shell, same identity rules, same three-line envelope
 * as the other three. `User::sendPasswordResetNotification()` is the one
 * override that routes Laravel's password broker through it.
 */
class PasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $resetUrl,
        public int $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('ioms.emails.noreply'), 'IOMS'),
            // Somebody who cannot get into their account needs a human.
            replyTo: [new Address(config('ioms.emails.support'), 'IOMS Support')],
            subject: 'Reset your IOMS password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            with: [
                'resetUrl' => $this->resetUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
                'supportEmail' => config('ioms.emails.support'),
            ],
        );
    }
}
