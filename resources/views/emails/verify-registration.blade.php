@extends('emails.layout', ['heading' => 'Confirm your email address'])

@section('content')
    <p style="margin:0 0 14px; font-size:14px; line-height:1.65; color:#334155;">
        Hi {{ $registration->contact_name }},
    </p>

    <p style="margin:0 0 18px; font-size:14px; line-height:1.65; color:#334155;">
        Thank you for starting an IOMS subscription for <strong style="color:#0f2747;">{{ $registration->displayName() }}</strong>.
        Confirm this email address to continue to plan review and payment.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#2166c4; border-radius:8px;">
                <a href="{{ $url }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                    Confirm email address
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 18px; font-size:12px; line-height:1.6; color:#64748b;">
        If the button does not work, copy this link into your browser:<br>
        <span style="color:#2166c4; word-break:break-all;">{{ $url }}</span>
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:8px; background-color:#f8fafc;">
        <tr>
            <td style="padding:14px 16px;">
                <p style="margin:0 0 6px; font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8;">Your registration</p>
                <p style="margin:0; font-size:13px; color:#0f2747;">
                    <strong>{{ $registration->reference }}</strong><br>
                    {{ $registration->package?->name }} &middot; {{ $registration->billing_cycle === 'monthly' ? 'Monthly' : 'Annual' }} billing
                </p>
            </td>
        </tr>
    </table>

    {{-- Says plainly where the customer is in the process. Nothing is
         active yet and the email must not imply otherwise. --}}
    <p style="margin:18px 0 0; font-size:12px; line-height:1.6; color:#64748b;">
        Your IOMS workspace is not active yet. It is created once your payment is confirmed by the payment provider.
        If you did not start this registration, you can ignore this email &mdash; no account has been created.
    </p>
@endsection
