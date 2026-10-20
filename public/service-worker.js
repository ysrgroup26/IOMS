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

/* =============================================================================
 * v2.129.0 -- BEING WOKEN UP
 * =============================================================================
 *
 * Everything above is about caching, and deliberately caches almost nothing.
 * Everything below is the OTHER reason a service worker exists here: it is
 * the only code in a browser that runs when IOMS is not open, which is what
 * makes a permit waiting for approval reach a phone in somebody's pocket.
 *
 * THE PUSH CARRIES NOTHING, AND THAT IS THE DESIGN. The server sends a
 * payloadless "tickle" (see App\Services\Notifications\WebPushService), and
 * this worker then FETCHES the notification over the person's own
 * authenticated session. So nothing operational crosses a lock screen that
 * the server did not just authorise: if the session has ended, or the account
 * was deactivated, or its access was narrowed, the fetch says so and the
 * person sees a generic line instead of a permit's details.
 *
 * The honest cost: a device that is online but signed out shows "IOMS" and
 * nothing more. That is a worse notification, not a wrong one.
 */

/** Where a tap goes when the server could not tell us anything better. */
const FALLBACK_URL = '/dashboard';

self.addEventListener('push', (event) => {
    /*
     * waitUntil keeps the worker alive for the fetch. Without it the worker
     * can be killed the instant this handler returns, which on a cold start
     * is well before a network round trip -- the notification then never
     * appears and nothing anywhere says why.
     */
    event.waitUntil(
        (async () => {
            let title = 'IOMS';
            let body = 'Ada pemberitahuan baru.';
            let url = FALLBACK_URL;
            let tag = 'ioms-generic';

            try {
                /*
                 * `credentials: 'include'` is what makes this the PERSON's
                 * request rather than an anonymous one -- a service worker
                 * fetch does not send cookies otherwise, and the endpoint
                 * would answer as a guest.
                 *
                 * ONE request, with NO cursor: this asks "what is the
                 * newest thing and how many are unread", which is exactly
                 * what a notification has to show. `latest` is carried for
                 * display and advances nothing -- the tab's own cursor is
                 * its own business.
                 *
                 * A woken worker is on a cold start with a short lifetime,
                 * so a second round trip here is one too many.
                 */
                const response = await fetch('/notifications/live', {
                    credentials: 'include',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });

                if (response.ok) {
                    const data = await response.json();
                    const count = Number(data.unread_count || 0);
                    const newest = data.latest || null;

                    if (newest) {
                        title = newest.title || title;
                        body = newest.body || '';
                        url = newest.url || FALLBACK_URL;
                        // One notification per record: a permit that moved
                        // three times replaces its own line instead of
                        // stacking three.
                        tag = newest.subject ? `ioms-${newest.subject.type}-${newest.subject.id}` : `ioms-${newest.id}`;
                    } else if (count > 0) {
                        body = count === 1 ? 'Ada 1 pemberitahuan baru.' : `Ada ${count} pemberitahuan baru.`;
                    }
                }
            } catch (error) {
                // Offline, or the session is gone. The generic line above
                // stands: the person taps it, IOMS opens, and the
                // Notification Center shows the truth.
            }

            await self.registration.showNotification(title, {
                body,
                tag,
                renotify: true,
                icon: '/branding/ioms-icon-192.png',
                badge: '/branding/ioms-maskable-192.png',
                data: { url },
                // Not `requireInteraction`: an operations notification that
                // will not dismiss itself is a notification people turn off.
            });
        })()
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = (event.notification.data && event.notification.data.url) || FALLBACK_URL;

    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            /*
             * An IOMS tab that is already open is FOCUSED and navigated,
             * rather than a second one being opened beside it. A person who
             * taps a notification and finds two copies of the app has to
             * work out which one is theirs.
             */
            for (const client of clients) {
                if (new URL(client.url).origin === self.location.origin) {
                    await client.focus();

                    if ('navigate' in client) {
                        try {
                            await client.navigate(target);
                        } catch (error) {
                            // Some browsers refuse navigate() on a client
                            // the worker did not create. Focus already
                            // happened, which is the important half.
                        }
                    }

                    return;
                }
            }

            /*
             * No tab open. `openWindow` loads the destination as an
             * ORDINARY authenticated request: the server decides whether
             * this account may see it, and an expired session lands on the
             * login page with the destination remembered as `intended`.
             * Nothing here checks permission, because nothing here could be
             * trusted to.
             */
            await self.clients.openWindow(target);
        })()
    );
});

/**
 * A subscription can be rotated by the push service itself, without anybody
 * doing anything. Re-subscribing here keeps the DEVICE reachable.
 *
 * The server's row is deliberately NOT updated from here. A POST from a
 * service worker carries no CSRF token and would be refused, and sending a
 * request built to be refused is worse than not sending one: it looks like
 * delivery. The client posts its current subscription every time IOMS is
 * opened with permission granted, and the row -- keyed by endpoint -- is
 * corrected then. The window in between is one in which a push goes to an
 * address that answers 410, which deletes the stale row. Nothing is lost:
 * the notification is in the Notification Center either way.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        (async () => {
            try {
                if (!event.newSubscription && event.oldSubscription?.options) {
                    await self.registration.pushManager.subscribe(event.oldSubscription.options);
                }
            } catch (error) {
                // The next time the app is opened, the client subscribes
                // again and the row is corrected then.
            }
        })()
    );
});
