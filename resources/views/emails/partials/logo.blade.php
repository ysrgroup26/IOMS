{{--
    v2.58.0 -- THE ONE PLACE THE IOMS MARK IS DRAWN IN EMAIL.

    A professional logo is being prepared and is not here yet. Rather than
    designing the email system around a placeholder image and having to
    unpick it later, the mark is isolated to this single partial: every
    message renders it through here, so swapping it is one file, not a
    redesign.

    TODAY it is a typographic wordmark, which is deliberate and not a
    compromise. The shipped brand asset is not a usable IOMS mark (see
    BrandWordmark.jsx, which falls back to type for the same reason), and
    an image that fails to load leaves a broken icon in an inbox --
    roughly half of mail clients block remote images by default.

    WHEN THE OFFICIAL LOGO ARRIVES, replace the wordmark block below with:

        <img src="{{ config('branding.email_logo_url') }}"
             alt="IOMS" width="120" height="32"
             style="display:block; border:0; outline:none; text-decoration:none;">

    and set that config value to an ABSOLUTE https URL on the production
    domain. Keep the alt text, keep explicit width/height (Outlook needs
    them), and keep the wordmark as the fallback path for a client that
    blocks images -- do not delete it.

    Expects: $onDark (bool) -- the mark sits on the navy header band.
--}}
@php $onDark = $onDark ?? true; @endphp

<span style="font-size:18px; font-weight:700; letter-spacing:0.5px; color:{{ $onDark ? '#ffffff' : '#0f2747' }};">IOMS</span>
<span style="font-size:11px; color:{{ $onDark ? '#94a3b8' : '#64748b' }}; letter-spacing:1.5px; text-transform:uppercase; margin-left:10px;">Industrial Operations Platform</span>
