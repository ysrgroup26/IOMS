/*
 * IOMS service worker -- v2.73.0
 * =============================================================================
 *
 * THIS SERVICE WORKER DELIBERATELY CACHES ALMOST NOTHING, AND THAT IS THE
 * FEATURE.
 *
 * A service worker exists here for one reason: a browser will not offer to
 * install a web application without one. It is the installability
 * requirement, not an offline strategy, and conflating the two is how
 * multi-tenant platforms leak data.
 *
 * -----------------------------------------------------------------------------
 * WHY NOT CACHE THE APP THE WAY EVERY PWA TUTORIAL DOES
 * -----------------------------------------------------------------------------
 *
 * IOMS is a multi-tenant system where every meaningful response is
 * authenticated AND tenant-scoped. A service worker cache is keyed by URL
 * and is shared across everything that happens in that browser profile --
 * it has no idea who was logged in when an entry was written.
 *
 * So a cache-first strategy over application responses produces exactly
 * the failure this product cannot have:
 *
 *   1. A Super Admin of Tenant A opens /dashboard. The HTML, carrying that
 *      tenant's data in an Inertia prop bag, is written to the cache under
 *      the key "/dashboard".
 *   2. They log out. A different user -- a different TENANT -- logs in on
 *      the same device, which is normal on a shared site terminal.
 *   3. They open /dashboard. The service worker serves the cached response
 *      before the network is ever consulted.
 *
 * Tenant A's data is now on Tenant B's screen. No server-side scope was
 * bypassed and no authorization check failed; the request never reached
 * the server at all. Every isolation guarantee in this codebase --
 * TenantScope, BelongsToCompany, the 404-not-403 pattern, the entitlement
 * middleware -- is enforced server-side, and a cache that answers without
 * asking the server renders all of it irrelevant.
 *
 * The same reasoning rules out caching PDFs (a permit, an invoice), API or
 * Inertia JSON responses, uploaded evidence photographs, and exports. All
 * of them are private operational records.
 *
 * Logging out cannot fix this either: `caches.delete()` on logout only
 * helps if the logout actually happens on that device, which is precisely
 * the case that fails.
 *
 * -----------------------------------------------------------------------------
 * WHAT IS CACHED, AND WHY IT IS SAFE
 * -----------------------------------------------------------------------------
 *
 * Exactly one class of thing: BUILD ARTEFACTS AND BRAND ASSETS. Files
 * under /build/ and /branding/, which are
 *
 *   - identical for every tenant and every user,
 *   - served without authentication,
 *   - content-hashed by Vite (app-CqyoCrW4.js), so a new deploy is a new
 *     URL and a stale entry can never shadow a new build.
 *
 * Nothing else is read from the cache, ever. There is no navigation
 * fallback, no offline page serving stale application HTML, and no
 * runtime caching of anything the server would have authorised.
 *
 * -----------------------------------------------------------------------------
 * IF OFFLINE SUPPORT IS EVER WANTED
 * -----------------------------------------------------------------------------
 *
 * It needs a design, not a cache setting: per-identity cache names keyed
 * to the authenticated user and tenant, explicit eviction on logout AND on
 * identity change, an allow-list of records the user is known to be
 * entitled to, and a decision about what a stale permit on a phone at a
 * gate is allowed to claim. That is an ADR, not a line in this file.
 */

// Bumped when the cached-asset policy changes. Not a release version --
// changing it evicts the whole store on the next activate.
//
// v2.78.2 -- v1 -> v2 EVICTS THE STALE BRAND ASSETS THIS WORKER PINNED.
// See the two-policy note below for what went wrong and why bumping this
// alone would not have been enough.
const CACHE_NAME = 'ioms-static-v2';

/*
 * TWO POLICIES, BECAUSE THE TWO PREFIXES DIFFER IN ONE DECISIVE WAY.
 *
 * v2.73.0 cached both of these cache-first and never revalidated, which
 * is correct for exactly one of them:
 *
 *   /build/     Vite content-hashes every filename. New build, new URL,
 *               so a cached entry can never be stale -- it is simply
 *               never asked for again. Cache-first is ideal.
 *
 *   /branding/  STABLE filenames. ioms-logo-dark.svg is that name
 *               forever, and its BYTES change when the brand does.
 *               Cache-first with no revalidation therefore pinned the
 *               old logo and the old favicon in every returning
 *               visitor's browser: redeploying the file changed nothing
 *               for them, and it was never browser cache, so clearing
 *               that did not help either. Only a cache-name bump could
 *               shift it -- once, manually, per brand change.
 *
 * Brand assets are now stale-while-revalidate: the cached copy is served
 * immediately (so the rail and the tab icon still paint instantly and
 * still work offline), and the network copy is fetched in the background
 * and written over it. A changed asset therefore appears on the visit
 * after it ships, with no cache-name bump and nothing for anyone to
 * clear.
 *
 * The allow-list itself is unchanged, and so is everything it protects:
 * same-origin only, these two prefixes only, nothing authenticated.
 */
const IMMUTABLE_PREFIXES = ['/build/'];
const REVALIDATE_PREFIXES = ['/branding/'];

function isCacheableAsset(url) {
    // Same-origin only. A cross-origin font or CDN script is not ours to
    // cache and is already handled by ordinary HTTP caching.
    if (url.origin !== self.location.origin) return false;

    return [...IMMUTABLE_PREFIXES, ...REVALIDATE_PREFIXES]
        .some((prefix) => url.pathname.startsWith(prefix));
}

function shouldRevalidate(url) {
    return REVALIDATE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

self.addEventListener('install', (event) => {
    // No precache list. Vite's filenames are content-hashed and change
    // every build, so a hardcoded list here would be wrong the moment it
    // was written. Assets enter the cache when they are first requested.
    //
    // skipWaiting so a new worker takes over on the next load rather than
    // waiting for every tab to close -- important because the ONLY thing
    // this worker governs is static assets, where "newest wins" is always
    // the right answer.
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // Drop every cache store this worker does not currently own,
            // including any left by an earlier policy.
            const names = await caches.keys();
            await Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)));
            await self.clients.claim();
        })()
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // GET only. A POST/PUT/DELETE is a write against authenticated,
    // tenant-scoped state and must always reach the server.
    if (request.method !== 'GET') return;

    // `credentials: 'include'` marks a request the application made as the
    // signed-in user. Nothing bearing an identity is eligible, whatever
    // its URL looks like.
    if (request.credentials === 'include' && !isCacheableAsset(new URL(request.url))) return;

    const url = new URL(request.url);

    if (!isCacheableAsset(url)) {
        // Explicitly NOT handled: no respondWith at all, so the browser
        // performs its normal network fetch. Every page, every Inertia
        // response, every PDF, every upload and every export goes to the
        // server and is authorised there, exactly as if no service worker
        // existed.
        return;
    }

    event.respondWith(
        (async () => {
            const cache = await caches.open(CACHE_NAME);
            const cached = await cache.match(request);

            if (cached) {
                // A brand asset keeps its filename when its artwork
                // changes, so the cached copy is served now and refreshed
                // behind it. waitUntil keeps the worker alive for the
                // write; a failed refresh is ignored, leaving the cached
                // copy exactly as it was.
                if (shouldRevalidate(url)) {
                    event.waitUntil(
                        fetch(request)
                            .then((fresh) => {
                                if (fresh && fresh.status === 200 && fresh.type === 'basic') {
                                    return cache.put(request, fresh.clone());
                                }
                            })
                            .catch(() => {})
                    );
                }

                return cached;
            }

            const response = await fetch(request);

            // Only store a clean, complete, same-origin 200. An opaque
            // response has an unreadable status and could be an error
            // page cached forever.
            if (response && response.status === 200 && response.type === 'basic') {
                cache.put(request, response.clone());
            }

            return response;
        })()
    );
});
