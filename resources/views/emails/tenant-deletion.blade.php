{{--
    ADR 087 -- the scheduled-deletion notices. Every message says what will
    happen, on which date, what the customer can do (export, or ask us to keep
    the organization), and offers one action.
--}}
@php
    $fmt = fn ($d) => $d?->format('d M Y');
    $copy = match ($event) {
        'scheduled' => [
            'heading' => 'Your organization is scheduled for deletion',
            'tone' => 'danger',
            'preheader' => 'All data will be removed on '.$fmt($deleteOn).'. Nothing changes before then.',
            'lead' => 'The IOMS organization below has been scheduled for permanent deletion on '.$fmt($deleteOn).'.',
            'next' => 'Until that date nothing changes: your records stay available to open, search and export. '
                .'If this was not expected, or you want to keep your organization, reply to this email before the date.',
        ],
        'reminder' => [
            'heading' => 'Your organization will be deleted soon',
            'tone' => 'danger',
            'preheader' => 'Deletion on '.$fmt($deleteOn).'. Export anything you need before then.',
            'lead' => 'This is a reminder that the IOMS organization below will be permanently deleted on '.$fmt($deleteOn).'.',
            'next' => 'After that date its records cannot be recovered. Export what you need now, or reply to this email to keep the organization.',
        ],
        default => [
            'heading' => 'The deletion has been cancelled',
            'tone' => 'success',
            'preheader' => 'Your organization and its data stay in place.',
            'lead' => 'The scheduled deletion of the IOMS organization below has been cancelled.',
            'next' => 'Nothing was removed. No action is needed.',
        ],
    };
@endphp

@extends('emails.layout', [
    'heading' => $copy['heading'],
    'eyebrow' => 'Organization',
    'tone' => $copy['tone'],
    'preheader' => $copy['preheader'],
    'replyTo' => $supportEmail,
])

@section('content')
    <p style="margin:0 0 16px; font-size:14px; line-height:22px; color:#334155;">{{ $copy['lead'] }}</p>

    @include('emails.partials.summary', [
        'rows' => [
            ['label' => 'Organization', 'value' => $organization, 'strong' => true],
            ['label' => 'Deletion date', 'value' => $event === 'cancelled' ? null : $fmt($deleteOn)],
        ],
    ])

    <p style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">{{ $copy['next'] }}</p>

    @unless ($event === 'cancelled')
        @include('emails.partials.button', ['url' => $exportUrl, 'label' => 'Sign in to export', 'tone' => 'brand'])
    @endunless

    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        Questions? Reply to this email and it will reach IOMS Support.
    </p>
@endsection
