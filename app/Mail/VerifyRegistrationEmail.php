<?php

namespace App\Mail;

use App\Models\TenantRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v2.51.0. Sent the moment a prospect submits the onboarding form.
 *
 * Email verification is a real gate, not decoration: it is what proves
 * the address IOMS will send invoices and activation notices to actually
 * belongs to the person who typed it, before any invoice exists.
 */
class VerifyRegistrationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TenantRegistration $registration, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Confirm your email to continue your IOMS registration ('.$this->registration->reference.')',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-registration',
            with: ['registration' => $this->registration, 'url' => $this->url],
        );
    }
}
