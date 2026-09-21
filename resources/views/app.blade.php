<!DOCTYPE html>
@php
    /*
     * v2.75.0 -- search identity is resolved ONCE, here, from
     * App\Services\SearchIdentity and config/seo.php. $seoPage is null for
     * every route that is not a public page, which is what switches all of
     * the public metadata below off for the authenticated product.
     */
    $search = app(\App\Services\SearchIdentity::class);
    $seoRoute = request()->route()?->getName();
    $seoPage = $search->page($seoRoute);
    $seoIndexable = $search->isIndexable(request());

    // Legal documents are written in Indonesian; everything else is English
    // per the language hierarchy (docs/CONVENTIONS.md).
    $htmlLang = $seoPage['lang'] ?? 'en';
@endphp
<html lang="{{ $htmlLang }}" class="h-full">
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
    {{-- v2.75.0: a public page's full search title is rendered by the
         server, so crawlers and link previews that do not run JavaScript
         see it; app.jsx keeps the browser tab identical afterwards. --}}
    <title inertia>{{ $seoPage['title'] ?? config('app.name', 'IOMS') }}</title>

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
    {{-- v2.75.0 -- PER-PAGE, CANONICAL, AND OFF BY DEFAULT.

         Until v2.75.0 every public page shared one description and one
         social title, the canonical URL was whatever host served the
         request (so the legacy domain or a proxy-internal host could be
         named canonical), and a guest on /login received the same
         Organization markup as the home page.

         Now: only routes listed in config/seo.php get any of this, each
         with its own title and description; canonical, og:url and every
         structured-data URL are built from config('ioms.public_url'); and
         anything not indexable says so, here and in an X-Robots-Tag header
         (App\Http\Middleware\SetRobotsHeader). --}}
    @unless ($seoIndexable)
        <meta name="robots" content="noindex, nofollow">
    @endunless

    @if ($seoPage)
        @php
            $brandName = config('ioms.name', 'IOMS');
            $brandDescriptor = config('ioms.descriptor', 'Industrial Operations Platform');
            $canonical = $search->canonicalUrl($seoRoute);
            $homeUrl = $search->canonicalUrl('home');
            $socialImage = $search->assetUrl(config('branding.assets.social'));

            /*
             * Structured data, built as one @graph here and passed to the
             * directive below as ONE short variable. Blade's directive-
             * argument scanner is a recursive PCRE pattern, and a literal
             * array this size passed straight to @json() is silently
             * TRUNCATED mid-array -- which in v2.72.0 took every page of
             * the product down at once. See docs/CONVENTIONS.md.
             *
             * Organization on every public page: it is who publishes the
             * site. WebSite and SoftwareApplication on the home page only,
             * where they describe what that page is -- repeating them on
             * every page adds nothing a crawler uses. No BreadcrumbList:
             * every public page is one level below home, and a two-item
             * trail describes no hierarchy that exists.
             */
            $organizationId = $homeUrl.'#organization';
            $graph = [[
                '@type' => 'Organization',
                '@id' => $organizationId,
                'name' => $brandName,
                'url' => $homeUrl,
                // The LIGHT-surface raster lockup: a search engine composites
                // it onto its own white ground, where the near-white dark
                // variant would vanish.
                'logo' => $search->assetUrl(config('branding.assets.logo_png')),
                'image' => $socialImage,
                'description' => config('seo.pages.home.description'),
                'email' => config('ioms.emails.hello'),
            ]];

            if ($seoRoute === 'home') {
                $graph[] = [
                    '@type' => 'WebSite',
                    '@id' => $homeUrl.'#website',
                    'name' => $brandName,
                    'alternateName' => $brandName.' — '.$brandDescriptor,
                    'url' => $homeUrl,
                    'inLanguage' => 'en',
                    'publisher' => ['@id' => $organizationId],
                ];

                $software = [
                    '@type' => 'SoftwareApplication',
                    'name' => $brandName,
                    'description' => config('seo.pages.home.description'),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web browser',
                    'url' => $homeUrl,
                    'publisher' => ['@id' => $organizationId],
                ];

                // Published prices only, straight from the catalogue the
                // Pricing page reads. A custom plan has no amount and is
                // left out rather than quoted as zero.
                $amounts = collect(app(\App\Services\PricingService::class)->publicPlans())
                    ->pluck('monthly.amount')->filter(fn ($a) => is_numeric($a) && $a > 0);

                if ($amounts->isNotEmpty()) {
                    $software['offers'] = [
                        '@type' => 'AggregateOffer',
                        'priceCurrency' => 'IDR',
                        'lowPrice' => (string) $amounts->min(),
                        'highPrice' => (string) $amounts->max(),
                        'offerCount' => $amounts->count(),
                        'url' => $search->canonicalUrl('pricing'),
                    ];
                }

                $graph[] = $software;
            }

            $structuredData = ['@context' => 'https://schema.org', '@graph' => $graph];
        @endphp

        <link rel="canonical" href="{{ $canonical }}">
        <meta name="description" content="{{ $seoPage['description'] }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $brandName }}">
        <meta property="og:locale" content="{{ $htmlLang === 'id' ? 'id_ID' : 'en_US' }}">
        <meta property="og:title" content="{{ $seoPage['title'] }}">
        <meta property="og:description" content="{{ $seoPage['description'] }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:image" content="{{ $socialImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $brandName }} — {{ $brandDescriptor }}">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $seoPage['title'] }}">
        <meta name="twitter:description" content="{{ $seoPage['description'] }}">
        <meta name="twitter:image" content="{{ $socialImage }}">

        <script type="application/ld+json">
            @json($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
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
