<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ADR 087 -- what an organization's administrators are told about a scheduled
 * deletion: when it was scheduled, each reminder, and if it is cancelled.
 */
class TenantDeletionNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant, public string $event, public ?int $daysLeft = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('ioms.emails.noreply'), 'IOMS'),
            replyTo: [new Address(config('ioms.emails.support'), 'IOMS Support')],
            subject: match ($this->event) {
                'scheduled' => 'Your IOMS organization is scheduled for deletion',
                'reminder' => 'Reminder: your IOMS organization will be deleted in '.max(1, (int) $this->daysLeft).' day(s)',
                default => 'The deletion of your IOMS organization has been cancelled',
            },
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.tenant-deletion', with: [
            'event' => $this->event,
            'organization' => $this->tenant->name,
            'deleteOn' => $this->tenant->deletion_scheduled_for,
            'daysLeft' => $this->daysLeft,
            'supportEmail' => config('ioms.emails.support'),
            'exportUrl' => url('/login'),
        ]);
    }
}
