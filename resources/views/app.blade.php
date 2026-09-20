<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- v2.72.0 -- SITE IDENTITY.

         Until this release the favicon pointed at `branding/icon.png`,
         which was a 2 MB photograph of a neon sign reading "icms" -- the
         pre-rebrand name. Every browser tab in the product showed the
         wrong brand, and downloaded two megabytes to do it.

         SVG first, PNG behind it. An SVG favicon is crisp at every size
         and is what modern browsers prefer; the 32px PNG covers the rest,
         and `apple-touch-icon` must be PNG because iOS does not accept
         SVG. All three are the icon-only mark on its own brand ground --
         the one place the compact square identity is genuinely required,
         rather than the full lockup. --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset(config('branding.assets.favicon')) }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset(config('branding.assets.favicon_png')) }}">
    <link rel="apple-touch-icon" href="{{ asset(config('branding.assets.apple_touch_icon')) }}">
    <meta name="theme-color" content="#00004f">

    {{-- v2.73.0 -- INSTALLABILITY.

         The manifest is what lets a browser offer to install IOMS as an
         application rather than bookmark it. Served from a route because
         start_url and scope depend on the deployed host, which is only
         known from the live request behind the hosting proxy.

         `apple-mobile-web-app-capable` is the iOS equivalent of the
         manifest's display:standalone -- Safari reads neither the manifest
         nor beforeinstallprompt, so the standalone behaviour and the
         status-bar style have to be declared here as well.

         NOTE: none of this makes IOMS work offline, and the service worker
         deliberately caches almost nothing. Caching an authenticated,
         multi-tenant operations platform would let one tenant's cached
         page be served to the next person who signs in on that device --
         see public/service-worker.js for the full reasoning. --}}
    <link rel="manifest" href="{{ route('manifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('ioms.name', 'IOMS') }}">
    <meta name="mobile-web-app-capable" content="yes">

    {{-- Applies the saved theme (or OS preference) before first paint, so
         there's no flash of the wrong theme while React hydrates. Kept as
         a tiny inline script (not bundled JS) specifically so it runs
         synchronously, before the page renders anything.

         Final Bug Fix Before Beta: Dark Mode temporarily disabled
         app-wide (matches DARK_MODE_ENABLED in resources/js/lib/useTheme.js
         -- this plain script can't import that constant, so the same
         `false` is duplicated here; flip both together when re-enabling).
         The detection logic below is left intact, just short-circuited,
         so restoring it later is uncommenting one line, not rewriting. --}}
    <script>
        (function () {
            var DARK_MODE_ENABLED = false;
            var stored = localStorage.getItem('ioms-theme');
            var theme = (DARK_MODE_ENABLED && (stored === 'light' || stored === 'dark'))
                ? stored
                : (DARK_MODE_ENABLED && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            if (theme === 'dark') document.documentElement.classList.add('dark');
        })();
    </script>

    {{-- v2.22.0 (Complete Product UI/UX Transformation, Part 1): IOMS is
         the product name, the same way "SAP"/"Workday"/"ServiceNow" are
         product names, not their own full expansions -- this is the
         single most-visible instance of the app name in the entire
         product (every browser tab), so it's the highest-value place to
         fix first. The full expansion remains available (About dialog,
         internal docs) but no longer dominates here. --}}
    <title inertia>{{ config('app.name', 'IOMS') }}</title>

    {{-- v2.72.0 -- SEARCH AND SOCIAL IDENTITY.

         None of this existed: no canonical, no social preview, no
         structured data. A crawler had no way to associate the product's
         name with its mark, and a link pasted into Slack or WhatsApp
         rendered as a bare URL.

         SCOPED TO THE PUBLIC SITE. The authenticated application is
         behind a login and is explicitly disallowed in robots.txt; adding
         Organization markup to it would be describing pages no crawler
         should be reading in the first place.

         This makes the site technically ELIGIBLE for a search engine to
         pick up the brand. It does not make it happen, and nothing here
         should be read as a promise about what Google will show or when. --}}
    @if (auth()->guest())
        @php
            $brandName = config('ioms.name', 'IOMS');
            $brandDescriptor = config('ioms.descriptor', 'Industrial Operations Platform');
            $brandDescription = $brandName.' is an '.$brandDescriptor.' for shipyards, construction, '
                .'manufacturing, mining, oil and gas, energy, marine and heavy industry -- HSE, permits to work, '
                .'people, assets, maintenance and procurement in one standardised enterprise platform.';
            $socialImage = asset(config('branding.assets.social'));

            /*
             * Built here rather than inline in the directive below.
             * Blade's directive-argument scanner is a PCRE recursive
             * pattern, and a literal array this size passed straight to
             * @json() exceeds what it will match: it silently TRUNCATES
             * the argument mid-array and emits unbalanced PHP, which
             * fails to compile. Because every page in the product renders
             * through this one layout, that took the whole application
             * down -- caught by the suite, which could not render a
             * single response. A directive whose argument is one short
             * variable cannot hit that limit.
             */
            $organizationLd = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $brandName,
                'alternateName' => $brandName.' — '.$brandDescriptor,
                'url' => url('/'),
                'logo' => asset(config('branding.assets.logo_png')),
                'image' => $socialImage,
                'description' => $brandDescription,
                'email' => config('ioms.emails.hello'),
            ];
        @endphp

        <link rel="canonical" href="{{ url()->current() }}">
        <meta name="description" content="{{ $brandDescription }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $brandName }}">
        <meta property="og:title" content="{{ $brandName }} — {{ $brandDescriptor }}">
        <meta property="og:description" content="{{ $brandDescription }}">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ $socialImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $brandName }} — {{ $brandDescriptor }}">
        <meta name="twitter:description" content="{{ $brandDescription }}">
        <meta name="twitter:image" content="{{ $socialImage }}">

        {{-- `logo` is the property a search engine reads to associate a
             mark with an organisation. It wants a raster image, so this
             is the PNG rather than the SVG -- and specifically the
             LIGHT-surface lockup, because the image is composited onto
             the engine's own white surface where the near-white wordmark
             would vanish and leave the icon standing alone. --}}
        <script type="application/ld+json">
            @json($organizationLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        </script>
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="h-full font-sans">
    @inertia
</body>
</html>
