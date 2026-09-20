<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Brand Assets
    |--------------------------------------------------------------------------
    |
    | Shipped with the application (public/branding/), used whenever no
    | admin-uploaded override exists in company_settings. These are static
    | paths, safe to keep in config() since they never depend on the
    | database -- unlike the actual effective values the app renders,
    | which are resolved per-request in HandleInertiaRequests (see the
    | `branding` shared prop) so an admin override is never masked by
    | config:cache.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | The IOMS brand assets (v2.72.0)
    |--------------------------------------------------------------------------
    |
    | THE ONE PLACE THE PRODUCT'S OWN MARK IS NAMED. React, Blade, email
    | and PDF all resolve through here, so adding a variant later is an
    | entry in this array rather than a hunt through four languages.
    |
    | These are derived from the official artwork in `resources/brand/`,
    | which is committed as the archive of record. Every path `d` is the
    | designer's, verbatim; the only changes made to produce the web files
    | were removing the full-bleed background rect and cropping the
    | viewBox from the official 2000x2000 square to the artwork's own
    | measured bounds, so `h-6 w-auto` sizes the LOGO instead of a square
    | of mostly empty canvas. No coordinate, proportion, colour or shape
    | was altered, and nothing was redrawn.
    |
    | TWO LOCKUPS, BECAUSE THE OFFICIAL SET CONTAINS TWO. The wordmark is
    | near-white (#f7fafc) in one and navy (#00004f) in the other -- the
    | designer supplied both precisely so the mark stays legible on a dark
    | rail and on a white page. Picking the right one per surface is
    | therefore NOT a recolour; using one everywhere would be, and on a
    | white page the near-white wordmark is simply invisible.
    |
    | `logo` is the primary: icon + wordmark. The icon-only mark is used
    | only where a square is technically required (favicon, app icon).
    |
    */

    'assets' => [
        // Primary lockup, for light surfaces: public site, auth, PDFs.
        'logo' => '/branding/ioms-logo.svg',

        // Primary lockup, for dark surfaces: the app rail, email header.
        'logo_dark' => '/branding/ioms-logo-dark.svg',

        // Raster twins of the two lockups, for the consumers that cannot
        // take SVG at all.
        //
        // `logo_dark_png` is for email: Outlook renders through Word's
        // engine, which has no SVG support -- see
        // emails/partials/logo.blade.php for the rest of that story.
        //
        // `logo_png` is for schema.org Organization `logo`, and it must
        // be the LIGHT-surface variant. A search engine composites that
        // image onto its own white surface, where the near-white wordmark
        // of `logo_dark_png` would disappear entirely and leave the cyan
        // icon alone standing for the brand.
        'logo_png' => '/branding/ioms-logo.png',
        'logo_dark_png' => '/branding/ioms-logo-dark.png',

        // The mark alone, transparent.
        'icon' => '/branding/ioms-icon.svg',

        // The mark on its own brand ground -- a favicon needs a
        // background of its own to stay legible against light and dark
        // browser chrome alike.
        'favicon' => '/branding/ioms-favicon.svg',
        'favicon_png' => '/branding/ioms-favicon-32.png',
        'apple_touch_icon' => '/branding/ioms-apple-touch-icon.png',

        // Social preview. Scrapers do not accept SVG, so this one must be
        // raster.
        'social' => '/branding/ioms-og.png',

        /*
        | v2.73.0 -- PWA INSTALL ICONS.
        |
        | Rasterised from the official icon SVGs; no path was redrawn.
        | A manifest needs PNG at fixed sizes -- no browser will install an
        | app from an SVG icon alone.
        |
        | `any` and `maskable` ARE TWO DIFFERENT DRAWINGS, not one image
        | labelled twice. A maskable icon keeps its artwork inside the
        | middle ~80% so Android's circular and squircle crops cannot cut
        | the mark, and its brand ground fills the bleed. Using that padded
        | drawing as `any` would render the mark needlessly small on every
        | platform that does not crop, which is what declaring a single
        | icon "any maskable" forces. IOMS ships both.
        */
        'icon_192' => '/branding/ioms-icon-192.png',
        'icon_512' => '/branding/ioms-icon-512.png',
        'maskable_192' => '/branding/ioms-maskable-192.png',
        'maskable_512' => '/branding/ioms-maskable-512.png',
    ],

    /*
    | Fallbacks for a tenant that has not uploaded its own mark. These now
    | point at the real IOMS artwork; before v2.72.0 they pointed at
    | `wordmark.png`/`icon.png`, which were a 2 MB photograph of a neon
    | sign reading "icms" -- the pre-rebrand name -- and were rendering on
    | the login screen, the public site and every PDF header. Those files
    | are deleted, not merely unreferenced.
    */
    'default_wordmark_path' => '/branding/ioms-logo.svg',

    'default_icon_path' => '/branding/ioms-icon.svg',

    /*
    |--------------------------------------------------------------------------
    | Watermark Defaults
    |--------------------------------------------------------------------------
    |
    | Fallbacks used only until an admin sets an override via Settings >
    | Branding (future release -- see ROADMAP.md). Opacity is expressed as
    | a decimal (0.03 = 3%).
    |
    */

    'watermark_enabled' => true,
    'dashboard_watermark_enabled' => true,
    'login_watermark_enabled' => true,
    'home_watermark_enabled' => true,
    'about_watermark_enabled' => true,
    'watermark_opacity' => 0.03,

];
