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

    'default_wordmark_path' => '/branding/wordmark.png',

    'default_icon_path' => '/branding/icon.png',

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
