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

    /*
     * v2.128.0 -- THE BOOT SPLASH, on the application only.
     *
     * $seoPage is non-null exactly for the public marketing pages in
     * config/seo.php. Those arrive with content a visitor can read at once,
     * so covering them with a splash would hide what is already there; the
     * application arrives as an empty div and is what needs covering.
     *
     * The lockup is read from disk and inlined rather than linked, so it
     * paints with this response instead of after a second request. A
     * missing file yields an empty string and the splash degrades to the
     * white ground and the bar -- never a broken-image box.
     */
    $iomsBootSplash = $seoPage === null;
    $iomsBootLockup = '';

    if ($iomsBootSplash) {
        $iomsLockupPath = public_path(ltrim(config('branding.assets.lockup_stacked', '/branding/ioms-lockup-stacked.svg'), '/'));
        $iomsBootLockup = is_file($iomsLockupPath)
            ? preg_replace('/<\?xml.*?\?>/s', '', (string) file_get_contents($iomsLockupPath))
            : '';
    }
@endphp
<html lang="{{ $htmlLang }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- v2.90.0 -- every active IOMS icon is generated from the canonical,
         owner-supplied transparent artwork in config/branding.php.

         ICO remains first for older clients that request /favicon.ico;
         PNG fallbacks cover crawlers and other browser surfaces; the compact
         SVG derivative uses the same pixels with its empty canvas trimmed.
         Apple and PWA icons are PNG because those consumers require raster
         files. All are transparent, including the Apple touch icon and both
         PWA purposes. A maskable-capable browser may composite transparency
         onto its own solid fill; that platform choice is not baked into IOMS.
         --}}
    <link rel="icon" href="{{ asset(config('branding.assets.favicon_ico')) }}" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/svg+xml" href="{{ asset(config('branding.assets.favicon')) }}">
    <link rel="icon" type="image/png" sizes="96x96" href="{{ asset(config('branding.assets.favicon_96')) }}">
    <link rel="icon" type="image/png" sizes="48x48" href="{{ asset(config('branding.assets.favicon_48')) }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset(config('branding.assets.favicon_png')) }}">
    <link rel="apple-touch-icon" type="image/png" sizes="180x180" href="{{ asset(config('branding.assets.apple_touch_icon')) }}">
    {{-- v2.128.0 -- WHITE, NOT NAVY.

         This was #00004f, chosen in v2.73.0 "so the OS chrome around the
         installed window matches the app rather than framing it in white".
         On a phone that reasoning inverted: Android paints the status bar
         with `theme_color`, so an installed IOMS opened to a dark band
         above a white application, and the platform splash did the same --
         the "black area" in the owner's report. The application is light on
         every surface a phone shows; the chrome around it now says so.
         `color-scheme` keeps the browser from compositing its own dark
         ground underneath while the document is still parsing. --}}
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">

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
    {{-- v2.128.0 -- `default`, not `black-translucent`.

         `black-translucent` puts the web content UNDER the status bar and
         draws the clock and battery in white. Over this application's white
         ground that is white on white: on the reference iPhone the status
         bar read as an empty black-filled notch area with nothing legible
         beside it. `default` gives a white bar with dark glyphs, which is
         what the owner's reference shows and what the app underneath
         actually is. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
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
            /*
             * v2.128.0 -- "JavaScript is running", said before first paint.
             *
             * The boot splash is hidden by default and shown only under this
             * class, so a client that will never run the code that REMOVES
             * the splash is never shown it in the first place. A script-off
             * fallback block would have done the same job, but private pages
             * deliberately carry none of those (LandingPositioningTest pins
             * that the crawler summary is public-only), and a second kind of
             * them there would blunt that check.
             */
            document.documentElement.classList.add('ioms-js');

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
                /*
                 * v2.89.0 -- THE REGISTERED ENTITY, NAMED SEPARATELY.
                 *
                 * `name` stays IOMS: that is what the site is about, what a
                 * search result should read, and what every link to it says.
                 * `legalName` is a different fact, and schema.org has a field
                 * for it precisely because the two are rarely the same thing.
                 * Stating the entity here rather than renaming the
                 * Organization keeps the search identity intact while making
                 * the publisher accurate.
                 */
                'legalName' => config('ioms.legal.entity_name'),
                'url' => $homeUrl,
                // The LIGHT-surface raster lockup: a search engine composites
                // it onto its own white ground, where the near-white dark
                // variant would vanish.
                'logo' => $search->assetUrl(config('branding.assets.logo_png')),
                'image' => $socialImage,
                'description' => config('seo.pages.home.description'),
                'slogan' => 'Built for Industrial Operations',
                'email' => config('ioms.emails.hello'),
            ]];

            if ($seoRoute === 'home') {
                $graph[] = [
                    '@type' => 'WebSite',
                    '@id' => $homeUrl.'#website',
                    'name' => $brandName,
                    'alternateName' => $brandName.': '.$brandDescriptor,
                    'url' => $homeUrl,
                    'inLanguage' => 'en',
                    'publisher' => ['@id' => $organizationId],
                ];

                $software = [
                    '@type' => 'SoftwareApplication',
                    'name' => $brandName,
                    'description' => config('seo.pages.home.description'),
                    'applicationCategory' => 'BusinessApplication',
                    'applicationSubCategory' => $brandDescriptor,
                    // v2.76.0 -- the operational domains, worded exactly as
                    // the home page's visible story headings name them, so
                    // the markup restates the page rather than adding claims
                    // the page does not make.
                    'featureList' => [
                        'Industrial Operations', 'HSE & Safety', 'People & Workforce', 'Field Operations',
                        'Warehouse Logistics', 'Management Visibility',
                    ],
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
        <meta property="og:image:alt" content="{{ $brandName }}: {{ $brandDescriptor }}">

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
    {{-- v2.85.0 -- THREE FAMILIES, ONE REQUEST.

         Inter stays the UI face: it is what the authenticated application
         is built in, and nothing here changes that.

         Archivo is the DISPLAY face, added for the public website only. A
         grotesque with tighter apertures and a squarer bowl than Inter, it
         reads as industrial signage rather than as another SaaS landing
         page. Inter at 60px is the single most recognisable typographic
         tell of a generated marketing site; the product it fronts deserves
         a headline voice of its own.

         IBM Plex Mono carries INSTRUMENT LABELS -- eyebrows, plan
         capacities, step numbers, reference figures. On an operations
         platform a monospaced label is not decoration: it is the
         convention the reader already associates with a reading taken off
         a system rather than a claim written by marketing.

         One combined request, weights restricted to those actually used. --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @if ($iomsBootSplash)
        {{-- Inline, and above the bundle: these rules must apply on the
             first paint, and a stylesheet Vite serves is another request. --}}
        <style>
            /* The ground. Set on html and body as well, so the area under
               a phone's status bar and gesture bar -- which the browser
               paints from the document background, not from any element --
               is white rather than the UA default. */
            html, body { background-color: #ffffff; }

            /* Hidden unless JavaScript is running -- see the head script. */
            #ioms-boot { display: none; }

            .ioms-js #ioms-boot {
                position: fixed;
                inset: 0;
                z-index: 2147483000;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background-color: #ffffff;
                /* Keeps the composition off the notch and the gesture bar
                   on a phone, and is simply 0 everywhere else. */
                padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
                /* Optically centred: a composition placed on the exact
                   vertical centre of a tall screen reads as low. */
                padding-bottom: calc(env(safe-area-inset-bottom) + 6vh);
            }

            /* Proportional to the viewport and capped, so the mark is the
               same size relative to the screen on a phone and on a desktop
               rather than a postage stamp on one of them. */
            .ioms-js #ioms-boot .ioms-boot-mark {
                width: min(58vw, 260px);
                line-height: 0;
            }
            /* The DIRECT child only. The lockup places the symbol and
               the word in nested <svg> elements carrying their own x, y,
               width and height; a bare `svg` selector here overrode all
               four on both of them, so the first render drew the symbol at
               the full width of the box, off centre, with the word missing
               entirely. Seen in a screenshot, not in the markup. */
            .ioms-js #ioms-boot .ioms-boot-mark > svg { width: 100%; height: auto; display: block; }

            /* A minimal indeterminate bar. Indeterminate because nothing
               here knows how far the bundle has got, and a bar that claims
               a percentage it cannot measure is a lie told in CSS. */
            .ioms-js #ioms-boot .ioms-boot-bar {
                position: relative;
                overflow: hidden;
                width: min(42vw, 184px);
                height: 3px;
                margin-top: 10vh;
                border-radius: 999px;
                background-color: #e6eef5;
            }
            .ioms-js #ioms-boot .ioms-boot-bar span {
                position: absolute;
                top: 0;
                left: 0;
                height: 100%;
                width: 40%;
                border-radius: 999px;
                background-color: #01c1ed;
                animation: ioms-boot-slide 1.15s ease-in-out infinite;
            }
            @keyframes ioms-boot-slide {
                0%   { transform: translateX(-100%); }
                100% { transform: translateX(250%); }
            }
            /* Respect a reduced-motion preference: the bar stays, it just
               stops travelling. */
            @media (prefers-reduced-motion: reduce) {
                .ioms-js #ioms-boot .ioms-boot-bar span { animation: none; width: 100%; opacity: 0.55; }
            }

            .ioms-js #ioms-boot .ioms-boot-text {
                margin: 10px 0 0 0;
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
                font-size: 12px;
                letter-spacing: 0.02em;
                color: #94a3b8;
            }
        </style>
    @endif

    @inertiaHead
</head>
<body class="h-full font-sans">
    {{-- v2.76.0 -- THE DEFINITION, IN THE HTML THE SERVER SENDS.

         Inertia SSR is off (config/inertia.php) and needs a Node process the
         current shared hosting does not run, so every page body is drawn in
         the browser. Google renders JavaScript and reads the full page; a
         crawler or reader that does not gets only the head. This gives
         those clients what this page is -- its title and the one-sentence
         summary from config/seo.php -- and links to the rest of the public
         site, and nothing else. It is never shown to a browser with
         JavaScript on, and it makes no claim the page itself does not. --}}
    @if ($seoPage)
        <noscript>
            <header>
                <p>{{ config('ioms.name', 'IOMS') }}: {{ config('ioms.descriptor', 'Industrial Operations Platform') }}</p>
                <h1>{{ $seoPage['title'] }}</h1>
                <p>{{ $seoPage['description'] }}</p>
            </header>
            <nav aria-label="IOMS">
                <ul>
                    @foreach ($search->pages() as $navRoute => $navPage)
                        <li><a href="{{ route($navRoute) }}">{{ $navPage['title'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </noscript>
    @endif
    @inertia

    @if ($iomsBootSplash)
        @include('partials.boot-splash')
        <script>
            /*
             * The safety net, not the normal path. `app.jsx` removes the
             * splash the moment React mounts; this removes it if the bundle
             * never arrives at all, because a splash that outlives its
             * reason is a white screen with a logo on it and the person
             * holding the phone cannot tell those apart.
             */
            setTimeout(function () {
                var el = document.getElementById('ioms-boot');
                if (el) el.remove();
            }, 12000);
        </script>
    @endif
</body>
</html>
