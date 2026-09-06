<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * v2.51.0. Notifies the billing contact that an invoice exists and is
 * payable. Sent for both the first subscription invoice and any later
 * renewal invoice, so invoice-per-cycle renewal works identically to the
 * initial purchase without a second notification path.
 */
class InvoiceIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public string $planName,
        public string $amount,
        public ?string $payUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'IOMS invoice '.$this->invoice->invoice_number);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice-issued',
            with: [
                'invoice' => $this->invoice,
                'planName' => $this->planName,
                'amount' => $this->amount,
                'payUrl' => $this->payUrl,
            ],
        );
    }
}
