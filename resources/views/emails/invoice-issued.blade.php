@extends('emails.layout', ['heading' => 'Invoice ' . $invoice->invoice_number])

@section('content')
    <p style="margin:0 0 18px; font-size:14px; line-height:1.65; color:#334155;">
        An invoice has been issued for your IOMS subscription. Your workspace activates once this payment
        is confirmed by the payment provider.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:8px; margin:0 0 20px;">
        <tr>
            <td style="padding:16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding:4px 0; font-size:13px; color:#64748b;">Invoice</td>
                        <td style="padding:4px 0; font-size:13px; color:#0f2747; text-align:right;"><strong>{{ $invoice->invoice_number }}</strong></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0; font-size:13px; color:#64748b;">Plan</td>
                        <td style="padding:4px 0; font-size:13px; color:#0f2747; text-align:right;">{{ $planName }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0; font-size:13px; color:#64748b;">Billing period</td>
                        <td style="padding:4px 0; font-size:13px; color:#0f2747; text-align:right;">
                            {{ $invoice->period_start?->format('d M Y') }} &ndash; {{ $invoice->period_end?->format('d M Y') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px 0 4px; border-top:1px solid #e2e8f0; font-size:14px; color:#0f2747;"><strong>Amount due</strong></td>
                        <td style="padding:10px 0 4px; border-top:1px solid #e2e8f0; font-size:16px; color:#0f2747; text-align:right;"><strong>{{ $amount }}</strong></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($payUrl)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
            <tr>
                <td style="background-color:#2166c4; border-radius:8px;">
                    <a href="{{ $payUrl }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                        Pay this invoice
                    </a>
                </td>
            </tr>
        </table>
    @endif

    <p style="margin:0; font-size:12px; line-height:1.6; color:#64748b;">
        Payment is processed by our payment provider. IOMS never receives or stores your card details.
    </p>
@endsection
