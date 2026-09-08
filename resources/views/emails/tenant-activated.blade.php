@extends('emails.layout', [
    'heading' => 'Your IOMS workspace is ready',
    'eyebrow' => 'Subscription activated',
    'tone' => 'success',
    'preheader' => 'Your payment is confirmed and your workspace is now active.',
    'replyTo' => config('ioms.emails.support'),
])

@section('content')
    <p style="margin:0 0 14px; font-size:14px; line-height:22px; color:#334155;">
        Hi {{ $registration->contact_name }},
    </p>

    <p style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">
        Your payment has been confirmed and the IOMS workspace for
        <strong style="color:#0f2747;">{{ $registration->displayName() }}</strong> is now active.
        Sign in with the email address and password you chose during registration.
    </p>

    @include('emails.partials.button', ['url' => $loginUrl, 'label' => 'Sign in to IOMS', 'tone' => 'brand'])

    @include('emails.partials.summary', ['rows' => [
        ['label' => 'Plan', 'value' => $registration->package?->name, 'strong' => true],
        ['label' => 'Billing', 'value' => $registration->billing_cycle === 'monthly' ? 'Monthly' : 'Annual'],
        ['label' => 'Administrator', 'value' => $registration->contact_email],
        ['label' => 'Reference', 'value' => $registration->reference],
    ]])

    {{-- No credential is ever sent by email. The administrator set their
         own password before paying; IOMS stored only the hash. --}}
    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        For security, IOMS never sends passwords by email. Use the password you created during
        registration, or reset it from the sign-in page.
    </p>
@endsection
