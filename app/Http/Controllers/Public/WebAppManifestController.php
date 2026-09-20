<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * v2.73.0 -- THE WEB APP MANIFEST.
 *
 * Served as a route rather than a static `public/manifest.json` for the
 * same reason `robots.txt` and `sitemap.xml` are (v2.72.0,
 * SiteIdentityController): `start_url` and `scope` are absolute-ish
 * against the deployed host, and IOMS runs behind a proxy on shared
 * hosting where that host is only known from the live request. A
 * committed file would be right on one environment and wrong on every
 * other.
 *
 * It also lets the manifest read `config/ioms.php`, so the installed app
 * is named by the same single source of truth as the browser title and
 * the About dialog rather than a fourth hardcoded copy of the name.
 *
 * WHAT THE MANIFEST DOES AND DOES NOT DO. It makes the application
 * INSTALLABLE -- the browser's own install flow, a real standalone
 * window, the correct icon and theme. It does not make it work offline,
 * and this release deliberately does not either: see
 * `public/service-worker.js` for why caching an authenticated,
 * multi-tenant operations platform is a security decision rather than a
 * performance one.
 */
class WebAppManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $name = config('ioms.name', 'IOMS');
        $descriptor = config('ioms.descriptor', 'Industrial Operations Platform');

        return response()->json([
            'name' => $name.' — '.$descriptor,
            // `short_name` is what fits under a home-screen icon. Android
            // truncates around 12 characters, so this is the product name
            // alone and nothing else.
            'short_name' => $name,
            'description' => $name.' is an '.$descriptor.' for HSE, permits to work, people, assets, '
                .'maintenance and procurement in one standardised enterprise platform.',

            /*
             * START AT THE DASHBOARD, NOT AT `/`.
             *
             * `/` is the public marketing site, and it redirects an
             * authenticated user onward. Someone who installed IOMS wants
             * the application; sending them to a landing page first --
             * inside a standalone window with no address bar -- is a
             * worse first impression than a login screen, which is where
             * `/dashboard` sends them if their session has expired.
             */
            'start_url' => url('/dashboard'),

            /*
             * SCOPE IS THE WHOLE ORIGIN, deliberately.
             *
             * A narrower scope (say `/dashboard`) would open every link
             * outside it -- Settings, a PDF, the public pricing page --
             * in a separate browser window, which reads as the app
             * throwing the user out. IOMS is one application across its
             * origin.
             */
            'scope' => url('/'),

            'display' => 'standalone',
            // Falls back through progressively less app-like modes on
            // platforms that do not support the one above.
            'display_override' => ['standalone', 'minimal-ui', 'browser'],
            'orientation' => 'any',

            // The navy the product's own rail and login panel use, so the
            // OS chrome around the installed window matches the app
            // rather than framing it in white.
            'theme_color' => '#00004f',
            // The splash/ground colour while the app boots. Light,
            // because the application itself is light -- a navy splash
            // followed by a white app is a visible flash.
            'background_color' => '#f8fafc',

            'lang' => 'en',
            'dir' => 'ltr',
            'categories' => ['business', 'productivity', 'utilities'],

            /*
             * `any` and `maskable` are SEPARATE entries, not one icon
             * declared as both.
             *
             * A maskable icon is drawn with its artwork inside the middle
             * ~80% so a circular or squircle crop cannot cut it; using
             * that same padded image as `any` would render the mark
             * needlessly small everywhere that does not crop. Declaring
             * one icon `"purpose": "any maskable"` forces exactly that
             * compromise, so IOMS ships both drawings.
             */
            'icons' => [
                [
                    'src' => url(config('branding.assets.icon_192')),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => url(config('branding.assets.icon_512')),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => url(config('branding.assets.maskable_192')),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src' => url(config('branding.assets.maskable_512')),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],

            /*
             * Jump-list entries on a long-press or right-click of the
             * installed icon. Kept to the three things a field user opens
             * the app to DO, not a copy of the sidebar: reporting an
             * incident and raising a permit are both time-critical, and
             * My Work is where a field account lands anyway.
             */
            'shortcuts' => [
                [
                    'name' => 'Report Incident',
                    'short_name' => 'Incident',
                    'url' => url('/incidents/create'),
                ],
                [
                    'name' => 'New Permit To Work',
                    'short_name' => 'PTW',
                    'url' => url('/permits-to-work/create'),
                ],
                [
                    'name' => 'My Work',
                    'short_name' => 'My Work',
                    'url' => url('/my-work'),
                ],
            ],
        ], 200, [
            // The spec's own media type. Browsers accept application/json
            // but some installability checks look for this one.
            'Content-Type' => 'application/manifest+json',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
