<?php

namespace App\Mail;

use App\Models\TenantRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v2.51.0. Sent by TenantProvisioningService AFTER a server-verified
 * payment created the tenant -- never on a browser redirect, and never
 * before the workspace genuinely exists.
 *
 * Contains no credential. The administrator chose their own password
 * during registration and IOMS only ever stored its hash.
 */
class TenantActivated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TenantRegistration $registration) {}

    public function envelope(): Envelope
    {
        // A new customer's first questions are support questions.
        return new Envelope(
            from: new Address(config('ioms.emails.noreply'), 'IOMS'),
            replyTo: [new Address(config('ioms.emails.support'), 'IOMS Support')],
            subject: 'Workspace IOMS untuk '.$this->registration->displayName().' sudah aktif',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.tenant-activated',
            with: [
                'registration' => $this->registration,
                'loginUrl' => route('login'),
            ],
        );
    }
}
