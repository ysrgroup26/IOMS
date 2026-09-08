{{--
    v2.58.0 -- THE IOMS EMAIL DESIGN SYSTEM.

    One shell for every transactional message IOMS sends. Table-based and
    inline-styled on purpose: every serious mail client (Outlook desktop
    above all) still renders a stripped subset of CSS with no flex, no
    grid and no external stylesheet, so the layout survives on the same
    primitives the PDF templates use.

    WHAT THIS SHELL PROVIDES, and why each part earns its place:

      PREHEADER   the hidden line an inbox shows next to the subject. Left
                  out, clients scrape the first visible words instead --
                  usually "IOMS Industrial Operations Platform", which is
                  the same for every message and tells a reader nothing.
      HEADER      the IOMS mark, drawn through emails.partials.logo so the
                  official logo lands in ONE file (see that partial).
      TONE BAND   a 3px rule under the header, coloured by purpose. This is
                  the whole of the contextual treatment: a security message
                  and an invoice should be distinguishable at a glance
                  without either becoming a marketing layout.
      EYEBROW     a small uppercase category above the heading, so the
                  reader knows what KIND of message this is before reading
                  a sentence.
      BODY        the caller's own @section('content').
      FOOTER      who sent it, which mailbox answers replies, and the
                  policy links a paying customer is entitled to find.

    CARRIES THE IOMS PLATFORM IDENTITY, NOT A TENANT'S. These messages come
    from IOMS to a customer about their IOMS subscription; tenant branding
    belongs on documents the tenant generates, not on billing mail from
    their supplier.

    RESTRAINED ON PURPOSE. No hero imagery, no illustration, no
    multi-column marketing furniture. A transactional email is read in
    four seconds by somebody who wants one fact.

    Optional variables, all with sensible defaults so every existing
    template keeps working unchanged:
      $heading    h1 line
      $eyebrow    small uppercase category above it
      $tone       'brand' (default) | 'security' | 'billing' | 'success' | 'danger'
      $preheader  inbox preview line
      $replyTo    which mailbox this conversation belongs to
--}}
@php
    $tone = $tone ?? 'brand';
    $toneColor = match ($tone) {
        'security' => '#7c3aed',
        'billing' => '#0f766e',
        'success' => '#15803d',
        'danger' => '#b91c1c',
        default => '#2166c4',
    };
    $emails = config('ioms.emails');
    $replyTo = $replyTo ?? ($emails['support'] ?? null);
    $eyebrow = $eyebrow ?? null;
    $preheader = $preheader ?? null;
    $site = config('app.url');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subjectLine ?? $heading ?? config('ioms.name') }}</title>
    <style>
        /* The ONLY <style> block, and it holds only what inline styles
           cannot express: media queries. Everything structural is inline,
           because Gmail strips <style> in some contexts. */
        @media only screen and (max-width: 600px) {
            .ioms-shell { width: 100% !important; }
            .ioms-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .ioms-h1 { font-size: 20px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; width:100%; background-color:#f1f5f9; font-family:Segoe UI, Roboto, Helvetica, Arial, sans-serif; color:#0f2747; -webkit-font-smoothing:antialiased;">

@if ($preheader)
    {{-- Hidden preview text. The trailing entities stop the client from
         pulling body copy in after it. --}}
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#f1f5f9;">
        {{ $preheader }}
        {!! str_repeat('&#8199;&#65279;&#847; ', 40) !!}
    </div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;">
    <tr>
        <td align="center" style="padding:32px 16px;">

            <table role="presentation" class="ioms-shell" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px; max-width:560px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">

                {{-- Header --}}
                <tr>
                    <td class="ioms-pad" bgcolor="#0f2747" style="background-color:#0f2747; padding:22px 28px;">
                        @include('emails.partials.logo', ['onDark' => true])
                    </td>
                </tr>

                {{-- Tone band: the entire contextual treatment. --}}
                <tr>
                    <td bgcolor="{{ $toneColor }}" style="background-color:{{ $toneColor }}; height:3px; line-height:3px; font-size:0;">&nbsp;</td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td class="ioms-pad" style="padding:28px;">
                        @if ($eyebrow)
                            <p style="margin:0 0 8px; font-size:11px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:{{ $toneColor }};">{{ $eyebrow }}</p>
                        @endif

                        @if (! empty($heading))
                            <h1 class="ioms-h1" style="margin:0 0 14px; font-size:19px; line-height:26px; font-weight:600; color:#0f2747;">{{ $heading }}</h1>
                        @endif

                        @yield('content')
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td class="ioms-pad" style="border-top:1px solid #e2e8f0; padding:18px 28px; background-color:#f8fafc;">
                        <p style="margin:0 0 6px; font-size:11px; line-height:18px; color:#64748b;">
                            Sent by <strong style="color:#334155;">IOMS</strong> — Industrial Operations Platform.
                            @if ($replyTo)
                                Replies to this message reach
                                <a href="mailto:{{ $replyTo }}" style="color:#2166c4; text-decoration:none;">{{ $replyTo }}</a>.
                            @endif
                        </p>
                        <p style="margin:0 0 6px; font-size:11px; line-height:18px; color:#94a3b8;">
                            <a href="{{ $site }}/terms" style="color:#64748b; text-decoration:none;">Terms</a>
                            &nbsp;·&nbsp;
                            <a href="{{ $site }}/privacy" style="color:#64748b; text-decoration:none;">Privacy</a>
                            &nbsp;·&nbsp;
                            <a href="{{ $site }}/refund-policy" style="color:#64748b; text-decoration:none;">Refund &amp; Cancellation</a>
                            &nbsp;·&nbsp;
                            <a href="{{ $site }}/contact" style="color:#64748b; text-decoration:none;">Contact</a>
                        </p>
                        {{-- Said once, plainly: this is a transactional message
                             about an account, not marketing, so there is no
                             unsubscribe to offer and pretending otherwise
                             would be worse than saying so. --}}
                        <p style="margin:0; font-size:11px; line-height:18px; color:#94a3b8;">
                            You are receiving this because of your IOMS account. It is a service message, not marketing.
                        </p>
                    </td>
                </tr>

            </table>

            <p style="margin:16px 0 0; font-size:11px; line-height:18px; color:#94a3b8;">
                &copy; {{ config('ioms.copyright_year', date('Y')) }} {{ config('ioms.website') }}
            </p>

        </td>
    </tr>
</table>

</body>
</html>
