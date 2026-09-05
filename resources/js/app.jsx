import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

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
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${liveCompanyName}` : liveCompanyName),
    resolve: (name) =>
        // Root cause of the white-flash-on-first-navigation bug (v1.6.5 QA):
        // `import.meta.glob` defaults to LAZY mode, meaning every page is
        // its own dynamically-imported chunk, fetched over the network the
        // first time it's visited in a session. Inertia's progress bar
        // only tracks the server round-trip, not this separate chunk
        // fetch/parse step -- so the bar can finish while the page's own
        // JS is still loading, which paints as a blank flash right after
        // the bar disappears. `{ eager: true }` bundles every page upfront
        // at build time instead, removing that gap entirely. Trades a
        // slightly larger initial bundle for eliminating this whole class
        // of flash on every "first visit to a not-yet-loaded route," not
        // just PPE specifically -- PPE was just where it was noticed.
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx', { eager: true })),
    setup({ el, App, props }) {
        // Seed the title suffix from the very first page's props too,
        // since 'navigate' only fires on subsequent client-side visits
        // in some Inertia versions -- this covers the initial hard load.
        const initialName = props?.initialPage?.props?.company?.name;
        if (initialName) liveCompanyName = initialName;

        const root = createRoot(el);
        root.render(<App {...props} />);
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
