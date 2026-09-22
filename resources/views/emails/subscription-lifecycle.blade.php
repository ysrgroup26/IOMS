{{--
    v2.78.0 -- one template for the three lifecycle emails (grace, lapsed,
    renewed). See App\Mail\SubscriptionLifecycleNotice for why "expiring
    soon" is the renewal invoice email rather than a fourth one here.

    Every message answers the same three questions in the same order:
    what state the subscription is in, the date that matters, and what to
    do about it -- then offers exactly one action.
--}}
@php
    $fmt = fn ($d) => $d?->format('d M Y');

    $copy = match ($event) {
        'grace' => [
            'heading' => 'Your subscription period has ended',
            'tone' => 'billing',
            'preheader' => 'Full access continues until '.$fmt($graceEndsAt).'. Renew to keep recording.',
            'state' => 'Renewal overdue — grace period',
            'lead' => 'The paid period for your IOMS subscription ended on '.$fmt($periodEndsAt).'. '
                .'Your organization keeps full access until '.$fmt($graceEndsAt).'.',
            'next' => 'If the subscription is not renewed by then, IOMS becomes read-only: every record stays '
                .'available to open, search and export, but new records cannot be saved until payment is received.',
            'action' => 'Renew subscription',
        ],
        'lapsed' => [
            'heading' => 'IOMS is now read-only',
            'tone' => 'danger',
            'preheader' => 'Your data is intact. Renew to restore recording.',
            'state' => 'Read-only',
            'lead' => 'The grace period for your IOMS subscription ended on '.$fmt($graceEndsAt).', so '
                .'recording new data is paused.',
            'next' => 'Nothing has been deleted. Every record remains available to open, search and export. '
                .'Recording resumes as soon as a renewal payment is confirmed.',
            'action' => 'Renew subscription',
        ],
        'renewed' => [
            'heading' => 'Your subscription has been renewed',
            'tone' => 'success',
            'preheader' => 'Active until '.$fmt($periodEndsAt).'.',
            'state' => 'Active',
            'lead' => 'We have received your payment. Your IOMS subscription is active until '.$fmt($periodEndsAt).'.',
            'next' => $writesRestored
                ? 'Recording new data is available again for your whole organization. All existing records were kept.'
                : 'No action is needed. Your organization continues without interruption.',
            'action' => 'View billing',
        ],
    };
@endphp

@extends('emails.layout', [
    'heading' => $copy['heading'],
    'eyebrow' => 'Subscription',
    'tone' => $copy['tone'],
    'preheader' => $copy['preheader'],
    'replyTo' => config('ioms.emails.billing'),
])

@section('content')
    <p style="margin:0 0 16px; font-size:14px; line-height:22px; color:#334155;">
        {{ $copy['lead'] }}
    </p>

    @include('emails.partials.summary', [
        'rows' => [
            ['label' => 'Organization', 'value' => $organization, 'strong' => true],
            ['label' => 'Plan', 'value' => $planName],
            ['label' => 'Status', 'value' => $copy['state']],
            ['label' => $event === 'renewed' ? 'Active until' : 'Period ended', 'value' => $fmt($periodEndsAt)],
            ['label' => $event === 'lapsed' ? 'Read-only since' : 'Read-only from',
                'value' => $event === 'renewed' ? null : $fmt($graceEndsAt)],
        ],
    ])

    <p style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">
        {{ $copy['next'] }}
    </p>

    @include('emails.partials.button', [
        'url' => $billingUrl,
        'label' => $copy['action'],
        'tone' => 'brand',
    ])

    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        @unless ($event === 'renewed')
            Renewing adds a new period after the one you already paid for, never from the date you pay.
        @endunless
        Questions about your subscription? Reply to this email and it will reach the IOMS billing team.
    </p>
@endsection
