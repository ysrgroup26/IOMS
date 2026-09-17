{{--
    v2.58.0 -- THE ONE PLACE THE IOMS MARK IS DRAWN IN EMAIL.

    Every message renders the mark through here, so changing it is one
    file rather than a redesign.

    v2.72.0 -- THE OFFICIAL LOGO ARRIVED, AND THIS IS WHAT THAT PARTIAL
    ALWAYS SAID TO DO.

    The previous version carried instructions for its own replacement: use
    an <img> with an ABSOLUTE URL, keep explicit width and height because
    Outlook needs them, keep the alt text, and KEEP THE WORDMARK BENEATH
    IT as the fallback. All four are honoured below.

    THREE EMAIL-SPECIFIC CONSTRAINTS decided the shape of this:

      1. PNG, not SVG. Outlook renders the mark through Word's engine,
         which does not support SVG at all. The raster twin of the
         dark-surface lockup is used (config('branding.assets.
         logo_dark_png')) -- the header band is navy, so the near-white
         wordmark is the correct official variant.

      2. An absolute URL. A relative path resolves against the mail
         client, not the site, and would 404 in every inbox. `asset()`
         produces an absolute URL here because APP_URL is set; behind the
         hosting proxy the scheme is correct because trusted proxies are
         configured (see bootstrap/app.php).

      3. IT MUST SURVIVE BEING BLOCKED. Roughly half of mail clients
         block remote images by default. Email offers no way to detect
         that and swap in a fallback, so the degradation is carried by
         two things that always render: the alt text, which is the
         product name and is what Outlook and Gmail draw in the image's
         place, and the descriptor line below, which is set as type and
         is never an image. A blocked mark therefore still reads as
         "IOMS -- Industrial Operations Platform" rather than as a broken
         icon.

    Expects: $onDark (bool) -- the mark sits on the navy header band.
--}}
@php
    $onDark = $onDark ?? true;
    $logoUrl = asset(config('branding.assets.logo_dark_png'));
@endphp

{{-- Width and height are attributes, not CSS: Outlook ignores the CSS
     and would otherwise draw the image at its intrinsic 480px. --}}
<img src="{{ $logoUrl }}"
     alt="IOMS"
     width="132"
     height="34"
     style="display:block; border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic;">

{{-- The descriptor, as type. Always rendered -- it is the second half of
     the blocked-image story above, not a swap, because email cannot tell
     us whether the image arrived. --}}
<div style="margin-top:6px;">
    <span style="font-size:11px; color:{{ $onDark ? '#94a3b8' : '#64748b' }}; letter-spacing:1.5px; text-transform:uppercase;">Industrial Operations Platform</span>
</div>
