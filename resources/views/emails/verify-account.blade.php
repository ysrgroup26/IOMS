@extends('emails.layout', [
    'heading' => 'Confirm your email address',
    'eyebrow' => 'IOMS Account',
    'tone' => 'brand',
    'preheader' => 'Confirm this address to finish setting up your IOMS account.',
    'replyTo' => config('ioms.emails.hello'),
])

@section('content')
    <p style="margin:0 0 14px; font-size:14px; line-height:22px; color:#334155;">
        Hi {{ $user->name }},
    </p>

    {{-- v2.74.0: says WHY this arrived and WHAT it is for, in the first
         sentence. An account confirmation must not read like an invoice or
         imply that anything has been purchased -- at this moment there is
         no organization, no plan and no subscription, and the copy below
         is careful to say so. --}}
    <p style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">
        You are receiving this because an IOMS account was created with this email address.
        Confirm the address to finish setting up your account.
    </p>

    @include('emails.partials.button', ['url' => $url, 'label' => 'Confirm email address', 'tone' => 'brand'])

    <p style="margin:0 0 20px; font-size:12px; line-height:19px; color:#64748b;">
        If the button does not work, copy this link into your browser:<br>
        <span style="color:#2166c4; word-break:break-all;">{{ $url }}</span>
    </p>

    @include('emails.partials.summary', ['rows' => [
        ['label' => 'Account', 'value' => $user->email, 'strong' => true],
        ['label' => 'Link valid for', 'value' => $expiresInMinutes >= 60
            ? intdiv($expiresInMinutes, 60).' hour'.(intdiv($expiresInMinutes, 60) === 1 ? '' : 's')
            : $expiresInMinutes.' minutes'],
    ]])

    {{-- The security note. Two separate facts, both of which matter: the
         link expires, and doing nothing is a safe response. --}}
    <p style="margin:0 0 12px; font-size:12px; line-height:19px; color:#64748b;">
        This link expires after the period shown above and can only be used once. If it has expired,
        sign in and request a new one from your account page.
    </p>

    <p style="margin:0 0 12px; font-size:12px; line-height:19px; color:#64748b;">
        <strong style="color:#0f2747;">If you did not create this account</strong>, you can safely ignore
        this email. The address will not be confirmed and the account cannot be used without it.
    </p>

    {{-- Stated plainly, because a confirmation email is exactly where
         somebody assumes they have bought something. --}}
    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        Creating an account does not start a subscription and does not charge anything. You choose a plan
        for your organization whenever you are ready. Questions? Reply to this email or contact
        <span style="color:#2166c4;">{{ config('ioms.emails.hello') }}</span>.
    </p>
@endsection
