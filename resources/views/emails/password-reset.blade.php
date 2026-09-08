@extends('emails.layout', [
    'heading' => 'Reset your IOMS password',
    'eyebrow' => 'Security',
    'tone' => 'security',
    'preheader' => 'Use the link inside to choose a new password. It expires shortly.',
    'replyTo' => config('ioms.emails.support'),
])

@section('content')
    <p style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">
        We received a request to reset the password for this IOMS account. Use the button below to
        choose a new one.
    </p>

    @include('emails.partials.button', ['url' => $resetUrl, 'label' => 'Reset password', 'tone' => 'brand'])

    <p style="margin:0 0 20px; font-size:13px; line-height:20px; color:#64748b;">
        This link expires in {{ $expiresInMinutes }} minutes. If the button does not work, copy this
        address into your browser:
        <br>
        <span style="word-break:break-all; color:#2166c4;">{{ $resetUrl }}</span>
    </p>

    {{-- Said plainly rather than as boilerplate: a reset link arriving
         unexpectedly is the one signal a customer has that somebody is
         trying to get into their account. --}}
    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        If you did not request this, no action is needed and your password stays unchanged. If you
        keep receiving these, contact {{ $supportEmail }}.
    </p>
@endsection
