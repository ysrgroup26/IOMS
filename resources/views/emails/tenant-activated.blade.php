@extends('emails.layout', ['heading' => 'Your IOMS workspace is ready'])

@section('content')
    <p style="margin:0 0 14px; font-size:14px; line-height:1.65; color:#334155;">
        Hi {{ $registration->contact_name }},
    </p>

    <p style="margin:0 0 18px; font-size:14px; line-height:1.65; color:#334155;">
        Your payment has been confirmed and the IOMS workspace for
        <strong style="color:#0f2747;">{{ $registration->displayName() }}</strong> is now active.
        Sign in with the email address and password you chose during registration.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
        <tr>
            <td style="background-color:#2166c4; border-radius:8px;">
                <a href="{{ $loginUrl }}" style="display:inline-block; padding:12px 24px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none;">
                    Sign in to IOMS
                </a>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:8px; background-color:#f8fafc;">
        <tr>
            <td style="padding:14px 16px;">
                <p style="margin:0 0 8px; font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#94a3b8;">Subscription</p>
                <p style="margin:0; font-size:13px; line-height:1.7; color:#0f2747;">
                    Plan: <strong>{{ $registration->package?->name }}</strong><br>
                    Billing: {{ $registration->billing_cycle === 'monthly' ? 'Monthly' : 'Annual' }}<br>
                    Administrator: {{ $registration->contact_email }}<br>
                    Reference: {{ $registration->reference }}
                </p>
            </td>
        </tr>
    </table>

    {{-- No credential is ever sent by email. The administrator set their
         own password before paying; IOMS stored only the hash. --}}
    <p style="margin:18px 0 0; font-size:12px; line-height:1.6; color:#64748b;">
        For security, IOMS never sends passwords by email. Use the password you created during registration,
        or reset it from the sign-in page.
    </p>
@endsection
