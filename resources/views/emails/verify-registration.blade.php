@extends('emails.layout', [
    'heading' => 'Confirm your email address',
    'eyebrow' => 'Registration',
    'tone' => 'brand',
    'preheader' => 'Confirm this address to continue to plan review and payment.',
    'replyTo' => config('ioms.emails.hello'),
])

@section('content')
    <p style="margin:0 0 14px; font-size:14px; line-height:22px; color:#334155;">
        Hi {{ $registration->contact_name }},
    </p>

    <p style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">
        Thank you for starting an IOMS subscription for
        <strong style="color:#0f2747;">{{ $registration->displayName() }}</strong>.
        Confirm this email address to continue to plan review and payment.
    </p>

    @include('emails.partials.button', ['url' => $url, 'label' => 'Confirm email address', 'tone' => 'brand'])

    <p style="margin:0 0 20px; font-size:12px; line-height:19px; color:#64748b;">
        If the button does not work, copy this link into your browser:<br>
        <span style="color:#2166c4; word-break:break-all;">{{ $url }}</span>
    </p>

    @include('emails.partials.summary', ['rows' => [
        ['label' => 'Reference', 'value' => $registration->reference, 'strong' => true],
        ['label' => 'Plan', 'value' => $registration->package?->name],
        ['label' => 'Billing', 'value' => $registration->billing_cycle === 'monthly' ? 'Monthly' : 'Annual'],
    ]])

    {{-- Says plainly where the customer is in the process. Nothing is
         active yet and the email must not imply otherwise. --}}
    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        Your IOMS workspace is not active yet — it is created once your payment is confirmed by the
        payment provider. If you did not start this registration you can ignore this email; no account
        has been created.
    </p>
@endsection
