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

        // v2.75.0 -- the dark lockup FLATTENED onto the email header navy
        // (#0f2747), derived from logo_dark_png without touching a path.
        // The near-white wordmark on a transparent PNG depends on the client
        // keeping the header's background colour; several webmail clients
        // and dark-mode rewriters do not, and the logo then renders as an
        // empty box. Opaque, it looks identical where the navy survives and
        // still reads where it does not.
        'logo_email_png' => '/branding/ioms-logo-email.png',

        // The general product mark stays on its existing vector master for
        // normal brand surfaces such as the watermark and controlled PDFs.
        'icon' => '/branding/ioms-icon.svg',

        // v2.90.0 -- the owner-designated transparent favicon artwork is the
        // source for every browser, Apple and PWA icon below. `npm run favicons`
        // leaves that supplied file byte-for-byte intact, trims only its empty
        // canvas and builds a transparent derivative for each required format.

        // The source is a 4096px PNG embedded in an SVG wrapper. The generated
        // SVG favicon is a compact, tightly-cropped wrapper around a 512px
        // derivative, so it keeps the official pixels without the source's
        // oversized canvas or 679 KB transfer. Other listed PNGs are generated
        // from the same source. No background or color is added.
        'favicon' => '/branding/ioms-favicon.svg',
        'favicon_ico' => '/favicon.ico',
        'favicon_48' => '/branding/ioms-favicon-48.png',
        'favicon_96' => '/branding/ioms-favicon-96.png',
        'favicon_png' => '/branding/ioms-favicon-32.png',
        // Canonical owner-supplied artwork; never rewrite this input file.
        'favicon_source' => '/branding/ioms-favicon-transparent.svg',
        'apple_touch_icon' => '/branding/ioms-apple-touch-icon.png',

        // Social preview. Scrapers do not accept SVG, so this one must be
        // raster.
        'social' => '/branding/ioms-og.png',

        /*
        | v2.90.0 -- PWA PNGs are generated from the same canonical transparent
        | source. `any` and `maskable` remain separate purpose entries: the
        | maskable artwork is padded to fit within the W3C guaranteed safe
        | circle. The Web App Manifest spec says transparent maskable pixels
        | are composited onto a solid fill chosen by the user agent; IOMS does
        | not prescribe that fill. Apple touch icons also stay transparent:
        | current Safari handles custom-shaped transparent icons and may adjust
        | spacing for its native icon background (Safari 17.2 release notes).
        */
        'icon_192' => '/branding/ioms-icon-192.png',
        'icon_512' => '/branding/ioms-icon-512.png',
        'maskable_192' => '/branding/ioms-maskable-192.png',
        'maskable_512' => '/branding/ioms-maskable-512.png',
    ],

    /*
    | v2.78.2 -- `default_wordmark_path` and `default_icon_path` lived here and
    | were read by NOTHING. They were a second declaration of assets that
    | `assets.logo` and `assets.icon` already own: a tenant that has not
    | uploaded a mark falls back to those in HandleInertiaRequests, and the
    | PDF letterhead simply renders no logo. Two keys naming one file is how
    | one of them ends up stale, so the unread pair is gone.
    |
    | The fallback itself is unchanged and still points at the official
    | artwork -- see `assets` above. Before v2.72.0 these pointed at
    | wordmark.png / icon.png, a 2 MB photograph of a neon sign reading
    | "icms"; those files are deleted, not merely unreferenced.
    */

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
