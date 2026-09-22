<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * v2.78.0 -- THE SUBSCRIPTION LIFECYCLE, BY EMAIL.
 *
 * Three events, one mailable, one template. The fourth lifecycle message --
 * "your period is about to end" -- is the renewal invoice email
 * (InvoiceIssued), which already goes out once, inside the renewal lead
 * window, with the amount and a pay link. A separate "expiring soon" email
 * beside it would be two messages about the same thing a day apart.
 *
 *   grace    the paid period ended; full access continues until a date
 *   lapsed   recording paused; every record still readable; renew to restore
 *   renewed  a VERIFIED payment extended the period (never sent otherwise)
 *
 * Sent from the noreply mailbox as "IOMS", with replies going to billing --
 * the same identity InvoiceIssued uses, because these are billing
 * conversations.
 */
class SubscriptionLifecycleNotice extends Mailable
{
    use Queueable, SerializesModels;

    public const EVENT_GRACE = 'grace';
    public const EVENT_LAPSED = 'lapsed';
    public const EVENT_RENEWED = 'renewed';

    public function __construct(
        public Subscription $subscription,
        public string $event,
        public string $billingUrl,
        // Only meaningful for `renewed`: whether recording had been paused,
        // so the email can say plainly that it is back.
        public bool $writesRestored = false,
    ) {
        if (! in_array($event, [self::EVENT_GRACE, self::EVENT_LAPSED, self::EVENT_RENEWED], true)) {
            throw new InvalidArgumentException("Unknown subscription lifecycle event [{$event}].");
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('ioms.emails.noreply'), 'IOMS'),
            replyTo: [new Address(config('ioms.emails.billing'), 'IOMS Billing')],
            subject: match ($this->event) {
                self::EVENT_GRACE => 'Your IOMS subscription period has ended',
                self::EVENT_LAPSED => 'IOMS is now read-only for your organization',
                self::EVENT_RENEWED => 'Your IOMS subscription has been renewed',
            },
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-lifecycle',
            with: [
                'event' => $this->event,
                'organization' => $this->subscription->tenant?->name,
                'planName' => $this->subscription->package?->name,
                'periodEndsAt' => $this->subscription->periodEndsAt(),
                'graceEndsAt' => $this->subscription->graceEndsAt(),
                'billingUrl' => $this->billingUrl,
                'writesRestored' => $this->writesRestored,
            ],
        );
    }
}
