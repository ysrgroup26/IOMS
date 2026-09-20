/**
 * v2.73.0 -- PWA INSTALLABILITY.
 *
 * Two jobs, both small, both easy to get subtly wrong:
 *
 *   1. Register the service worker -- which exists so the browser will
 *      OFFER to install IOMS. It caches build artefacts and brand assets
 *      and nothing else; see `public/service-worker.js` for why caching an
 *      authenticated multi-tenant platform is a security decision.
 *
 *   2. Capture `beforeinstallprompt` so the product can offer installation
 *      at a sensible moment through its own control, rather than relying
 *      on a browser affordance most people never find.
 *
 * WHY THE EVENT HAS TO BE CAPTURED AT BOOT. Chromium fires
 * `beforeinstallprompt` once, early, and if nothing calls
 * `preventDefault()` on it the opportunity is gone. A React component that
 * mounts later has already missed it. So this module listens immediately
 * on import and holds the event; components subscribe to the module, not
 * to the window.
 */

let deferredPrompt = null;
const listeners = new Set();

function notify() {
    const state = getInstallState();
    listeners.forEach((fn) => fn(state));
}

/**
 * Already running as an installed app?
 *
 * `display-mode: standalone` covers Chromium and Android. `navigator.standalone`
 * is the iOS Safari equivalent and exists nowhere else, which is why both
 * are checked rather than one.
 */
export function isInstalled() {
    if (typeof window === 'undefined') return false;

    return window.matchMedia?.('(display-mode: standalone)').matches
        || window.matchMedia?.('(display-mode: minimal-ui)').matches
        || window.navigator.standalone === true;
}

/**
 * iOS Safari implements no installation API at all -- no
 * `beforeinstallprompt`, no `prompt()`. Add to Home Screen exists but can
 * only be reached through the Share sheet by hand.
 *
 * So the product cannot offer a button there; it can only explain where
 * the control is. Detecting it means detecting the PLATFORM rather than
 * the capability, which is normally a mistake and is the correct call
 * here precisely because the capability is absent by design.
 *
 * iPadOS reports itself as a Mac, hence the touch-points check.
 */
export function isIosSafari() {
    if (typeof window === 'undefined') return false;

    const ua = window.navigator.userAgent;
    const isIos = /iPad|iPhone|iPod/.test(ua)
        || (/Macintosh/.test(ua) && typeof document !== 'undefined' && navigator.maxTouchPoints > 1);
    if (!isIos) return false;

    // Chrome and Firefox on iOS are Safari underneath but expose their own
    // UA tokens and their own (different, or absent) share menus, so the
    // instructions IOMS shows would be wrong for them.
    return /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

export function getInstallState() {
    return {
        installed: isInstalled(),
        // A real, usable browser install prompt is being held.
        canPrompt: deferredPrompt !== null,
        // No API exists; the product must give instructions instead.
        needsManualInstructions: !isInstalled() && deferredPrompt === null && isIosSafari(),
    };
}

export function subscribeToInstallState(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

/**
 * Show the browser's OWN install dialog. Never a bespoke imitation: the
 * native flow is what actually installs the app, and a fake one that
 * downloads a file or bookmarks a page would be a lie.
 *
 * Returns the user's choice, or null when there was no prompt to show.
 * The event is single-use either way -- Chromium will not let the same
 * one be shown twice.
 */
export async function promptInstall() {
    if (!deferredPrompt) return null;

    const event = deferredPrompt;
    deferredPrompt = null;
    notify();

    event.prompt();
    const { outcome } = await event.userChoice;

    return outcome; // 'accepted' | 'dismissed'
}

export function initPwa() {
    if (typeof window === 'undefined') return;

    window.addEventListener('beforeinstallprompt', (e) => {
        // Without this the browser shows its own mini-infobar and the
        // event cannot be replayed later from the product's own control.
        e.preventDefault();
        deferredPrompt = e;
        notify();
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        notify();
    });

    if (!('serviceWorker' in navigator)) return;

    // Registered after load so it never competes with the first paint for
    // bandwidth -- it has no role in rendering the current page.
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).catch(() => {
            // A failed registration means the app is not installable. It
            // does not mean the app is broken, and it must never surface
            // as an error to an operator: this is a progressive
            // enhancement and it degrades to an ordinary website.
        });
    });
}
