@extends('emails.layout', [
    'heading' => 'Invoice ' . $invoice->invoice_number,
    'eyebrow' => 'Billing',
    'tone' => 'billing',
    'preheader' => 'An invoice has been issued for your IOMS subscription.',
    'replyTo' => config('ioms.emails.billing'),
])

@section('content')
    <p style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">
        An invoice has been issued for your IOMS subscription. Your workspace activates once this
        payment is confirmed by the payment provider.
    </p>

    @include('emails.partials.summary', [
        'rows' => [
            ['label' => 'Invoice', 'value' => $invoice->invoice_number, 'strong' => true],
            ['label' => 'Plan', 'value' => $planName],
            ['label' => 'Billing period', 'value' => $invoice->period_start && $invoice->period_end
                ? $invoice->period_start->format('d M Y').' – '.$invoice->period_end->format('d M Y')
                : null],
            ['label' => 'Due', 'value' => $invoice->due_date?->format('d M Y')],
        ],
        'total' => ['label' => 'Amount due', 'value' => $amount],
    ])

    @if ($payUrl)
        @include('emails.partials.button', ['url' => $payUrl, 'label' => 'Pay this invoice', 'tone' => 'brand'])
    @endif

    @if ($invoiceUrl ?? null)
        <p style="margin:0 0 20px; font-size:13px; line-height:20px; color:#334155;">
            <a href="{{ $invoiceUrl }}" style="color:#2166c4; text-decoration:underline;">Download this invoice as a PDF</a>
        </p>
    @endif

    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        Payment is processed by our licensed payment provider. IOMS never receives or stores your
        card details.
    </p>
@endsection
