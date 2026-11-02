import './bootstrap';
import '../css/app.css';
import '@fontsource-variable/manrope';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

// v2.73.0 -- PWA. Called at module scope, before React mounts, because
// Chromium fires `beforeinstallprompt` once and early: a component that
// mounts later has already missed it. See lib/pwa.js.
import { initPwa } from './lib/pwa';
import { syncThemeAvailability } from './lib/useTheme';

// The browser tab title suffix must reflect the LIVE Branding Setting
// (Settings > Branding > Application Name), not a build-time env var --
// VITE_APP_NAME is baked into the compiled bundle at `npm run build` and
// never changes again, so an admin renaming the app would never see it
// reflected in the tab title (v1.5.2 fix). `router.on('navigate', ...)`
// fires on every Inertia visit, including the first, with that request's
// page props available -- caching the company name from there keeps this
// correct across the whole session without needing page-by-page changes.
// v2.38.0: was the forbidden long-form name. This value seeds the
// browser title before the first Inertia page resolves, so it was the
// single most visible place the wrong product name appeared.
let liveCompanyName = 'IOMS';
router.on('navigate', (event) => {
    const name = event.detail?.page?.props?.company?.name;
    if (name) liveCompanyName = name;
    // v2.139.0 (ADR 082): signing in or out is a visit, not a reload, so
    // whether the dark theme is offered is re-read from every page's props.
    syncThemeAvailability(event.detail?.page?.props?.darkTheme);
});

initPwa();

// v2.75.0 -- A public page's search title (config/seo.php, shared as
// `seoTitle`) is used verbatim, so the tab matches the <title> the server
// rendered for crawlers. Read from router.page, which Inertia updates BEFORE
// it renders the next page -- a value cached from the 'navigate' event would
// arrive one page late. router.page does not exist until the first render
// has happened, so the initial page's value is seeded in setup().
const pages = import.meta.glob('./Pages/**/*.jsx');

/** Fetches every page chunk a few at a time once the browser is idle. */
function prefetchPages() {
    const loaders = Object.values(pages);
    const idle = window.requestIdleCallback || ((fn) => window.setTimeout(fn, 200));
    const next = () => {
        const batch = loaders.splice(0, 6);
        if (batch.length === 0) return;
        Promise.allSettled(batch.map((load) => load())).then(() => idle(next));
    };
    idle(next);
}

let initialSeoTitle = null;
const currentSeoTitle = () => (router.page ? router.page.props?.seoTitle : initialSeoTitle);

createInertiaApp({
    title: (title) => currentSeoTitle() || (title ? `${title} - ${liveCompanyName}` : liveCompanyName),
    // v2.141.0 -- ONE CHUNK PER PAGE, PREFETCHED AFTER THE FIRST PAINT.
    //
    // v1.6.5 bundled every page eagerly to stop a blank flash on the first
    // visit to a page whose chunk had not arrived. The cost, measured on
    // 2026-11-01: one 2.3 MB script (577 KB gzipped) holding all 186 pages,
    // downloaded and parsed before the FIRST page could paint. Now each page
    // is its own chunk, so the first paint waits only for the page it shows;
    // Inertia keeps the current page on screen until the next one's chunk
    // has resolved; and `prefetchPages()` warms the rest during idle time,
    // so a later click finds its chunk already in the cache.
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, pages),
    setup({ el, App, props }) {
        // Seed the title suffix from the very first page's props too,
        // since 'navigate' only fires on subsequent client-side visits
        // in some Inertia versions -- this covers the initial hard load.
        const initialName = props?.initialPage?.props?.company?.name;
        if (initialName) liveCompanyName = initialName;
        initialSeoTitle = props?.initialPage?.props?.seoTitle ?? null;
        syncThemeAvailability(props?.initialPage?.props?.darkTheme);

        const root = createRoot(el);
        root.render(<App {...props} />);

        /*
         * v2.128.0 -- THE BOOT SPLASH COMES DOWN HERE.
         *
         * The server paints `#ioms-boot` into the response so that an
         * installed app shows the IOMS mark rather than a blank screen
         * while this bundle loads (see resources/views/partials/
         * boot-splash.blade.php). This is the normal way it is removed:
         * the frame after the first render, so the application is already
         * painted underneath and there is no flash of white between the
         * two. `app.blade.php` carries a timeout as the only other path,
         * for a bundle that never arrives.
         */
        requestAnimationFrame(() => {
            window.setTimeout(prefetchPages, 1500);
            document.getElementById('ioms-boot')?.remove();
        });
    },
    progress: {
        color: '#2563eb',
        // v1.6.6 QA: no `delay` was set here before, so Inertia used its
        // library default (~250ms) before showing the bar at all. Any
        // navigation slower than "instant" but faster than that
        // threshold had a real dead zone -- zero visual feedback while a
        // response was still in flight, which reads as a blank flash.
        // PPE Dashboard now runs more queries than any other single page
        // (several count queries plus one doesntHave subquery added in a
        // recent session), making it the most likely page to land in
        // exactly that window. delay: 0 shows the bar immediately on
        // every navigation instead, closing the gap for good -- this is
        // Inertia's own real loading indicator surfaced promptly, not a
        // fake screen covering anything up.
        delay: 0,
    },
});
