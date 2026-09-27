{{--
    v2.80.0 -- a support answer, in the shared email design system.

    Deliberately plain. The only thing a customer wants from this message is
    the answer, so the answer IS the body and everything else is one line of
    context (their reference) plus one line telling them a reply reaches a
    human. No action button: the action is replying.

    This is a CUSTOMER-facing message, so it follows the language hierarchy in
    docs/CONVENTIONS.md -- English labels, Indonesian guidance -- unlike the
    Master Admin console the operator wrote it from (ADR 040).

    The operator's text is rendered with nl2br(e()) -- escaped first, then
    line breaks restored -- so a reply typed into a textarea keeps its
    paragraphs without support copy becoming an injection surface.
--}}
@extends('emails.layout', [
    'heading' => $ticket->subject,
    'eyebrow' => 'Support',
    'tone' => 'brand',
    'preheader' => 'Balasan dari tim dukungan IOMS untuk tiket '.$ticket->reference.'.',
    'replyTo' => config('ioms.emails.support'),
])

@section('content')
    <div style="margin:0 0 20px; font-size:14px; line-height:22px; color:#334155;">
        {!! nl2br(e($body)) !!}
    </div>

    @include('emails.partials.summary', [
        'rows' => [
            ['label' => 'Ticket', 'value' => $ticket->reference, 'strong' => true],
            ['label' => 'Organization', 'value' => $organization],
            ['label' => 'Answered by', 'value' => $authorName],
        ],
    ])

    <p style="margin:0; font-size:12px; line-height:19px; color:#64748b;">
        Balas email ini untuk melanjutkan percakapan — balasan Anda masuk ke tiket
        {{ $ticket->reference }} dan dibaca oleh tim dukungan IOMS. Bila Anda mengirim pesan baru,
        mohon sertakan nomor tiket pada subjek.
    </p>
@endsection
