import { csrfHeaders } from '@/lib/csrf';

/**
 * v2.129.0 -- ASKING TO BE ALLOWED TO INTERRUPT SOMEBODY.
 *
 * Browser notification permission is a one-shot: a person who says No is not
 * asked again by the browser, and the only way back is through a settings
 * panel most people never find. So the prompt is never fired on page load --
 * it is fired from a control the person pressed, in the Notification Center,
 * next to a sentence saying what it is for.
 *
 * WHAT WORKS WHERE, which the product has to be honest about:
 *
 *   - Android Chrome / Edge / Firefox: works in a tab and when the browser is
 *     closed, no installation required.
 *   - Desktop Chrome / Edge / Firefox: the same.
 *   - iPhone and iPad: ONLY when IOMS has been added to the Home Screen
 *     (iOS 16.4+). Safari in a tab cannot receive push at all, whatever
 *     permission says -- `PushManager` is simply absent. `state()` reports
 *     that case separately so the UI can tell somebody to install the app
 *     rather than show them a button that cannot work.
 *   - Any browser with no service worker or no push support: unsupported, and
 *     said so.
 */

const PUBLIC_KEY_META = 'ioms-vapid-key';

/** base64url -> the Uint8Array `pushManager.subscribe` wants. */
function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalised = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(normalised);

    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

function publicKey() {
    return document.querySelector(`meta[name="${PUBLIC_KEY_META}"]`)?.content || null;
}

function isStandalone() {
    return window.matchMedia?.('(display-mode: standalone)')?.matches
        || window.navigator.standalone === true;
}

function isIos() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

/**
 * What this browser can actually do, as one word plus the reason.
 *
 * @returns {{ status: 'unavailable'|'needs-install'|'unconfigured'|'denied'|'granted'|'available', reason: string }}
 */
export function pushState() {
    if (typeof window === 'undefined') {
        return { status: 'unavailable', reason: 'No browser.' };
    }

    if (!publicKey()) {
        return {
            status: 'unconfigured',
            reason: 'Notifikasi perangkat belum diaktifkan pada instalasi IOMS ini.',
        };
    }

    if (!('serviceWorker' in navigator)) {
        return { status: 'unavailable', reason: 'Browser ini tidak mendukung notifikasi perangkat.' };
    }

    if (!('PushManager' in window) || !('Notification' in window)) {
        // On iOS this is exactly what an uninstalled Safari tab looks like,
        // and telling somebody their browser is unsupported when the real
        // answer is "add it to your Home Screen" is a dead end.
        if (isIos() && !isStandalone()) {
            return {
                status: 'needs-install',
                reason: 'Di iPhone, tambahkan IOMS ke Home Screen terlebih dahulu melalui Share -> Add to Home Screen.',
            };
        }

        return { status: 'unavailable', reason: 'Browser ini tidak mendukung notifikasi perangkat.' };
    }

    if (isIos() && !isStandalone()) {
        return {
            status: 'needs-install',
            reason: 'Di iPhone, tambahkan IOMS ke Home Screen terlebih dahulu melalui Share -> Add to Home Screen.',
        };
    }

    if (Notification.permission === 'denied') {
        return {
            status: 'denied',
            reason: 'Notifikasi diblokir untuk situs ini. Ubah melalui pengaturan situs di browser Anda.',
        };
    }

    if (Notification.permission === 'granted') {
        /*
         * PERMISSION IS NOT A SUBSCRIPTION, and saying so is the whole point
         * of this branch.
         *
         * `Notification.permission === 'granted'` only means the person
         * agreed to be interrupted. Whether this browser can actually BE
         * woken depends on `pushManager.subscribe()` succeeding, and it does
         * not always: a Chromium build with no push service connection fails
         * with "Registration failed - permission denied" on a profile whose
         * permission says granted. Caught exactly that way, in a browser.
         *
         * So this status is 'permitted', not 'granted' -- it means "allowed,
         * not yet proven". `resolvePushState()` asks the subscription itself
         * and is what any UI should render. Reporting "this device receives
         * IOMS notifications" from permission alone is a claim the product
         * cannot support (ADR 061: an offer is bound to what will actually
         * answer).
         */
        return { status: 'permitted', reason: 'Izin diberikan. Memeriksa pendaftaran perangkat…' };
    }

    return { status: 'available', reason: 'Aktifkan agar IOMS dapat memberi tahu Anda saat aplikasi tidak dibuka.' };
}

/**
 * The same answer, plus the one fact only the subscription can give.
 *
 * `granted` here means a subscription EXISTS -- the browser is registered
 * with its push service and the server has somewhere to send to.
 * `unsupported-device` means permission was granted and the subscription was
 * refused anyway, which is a real state and needs its own sentence rather
 * than a cheerful one.
 */
export async function resolvePushState() {
    const base = pushState();

    if (base.status !== 'permitted') return base;

    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (subscription) {
            return { status: 'granted', reason: 'Perangkat ini menerima notifikasi IOMS.' };
        }

        return { status: 'available', reason: 'Aktifkan agar IOMS dapat memberi tahu Anda saat aplikasi tidak dibuka.' };
    } catch (error) {
        return {
            status: 'unsupported-device',
            reason: 'Browser ini mengizinkan notifikasi tetapi tidak dapat mendaftarkan perangkat.',
        };
    }
}

/**
 * Register this browser as a delivery address.
 *
 * Called in two places and both matter: from the enable control, and on every
 * page load where permission is ALREADY granted. The second is what keeps the
 * server's row correct after a push service rotates an endpoint -- the
 * service worker cannot post that itself (it has no CSRF token), so the app
 * re-asserts the current subscription whenever it is open.
 */
export async function registerPushSubscription(csrfToken = null) { // eslint-disable-line no-unused-vars
    const key = publicKey();
    const { status } = pushState();

    if (!key || (status !== 'permitted' && status !== 'available')) return false;

    try {
        const registration = await navigator.serviceWorker.ready;

        const subscription = await registration.pushManager.getSubscription()
            || (await registration.pushManager.subscribe({
                // Required by every implementation: a push that is not shown
                // to the person is not allowed, and saying so up front is
                // what keeps the permission.
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(key),
            }));

        if (!subscription) return false;

        const response = await fetch('/notifications/push-subscription', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', ...csrfHeaders() },
            body: JSON.stringify(subscription.toJSON()),
        });

        return response.ok;
    } catch (error) {
        // A failed subscription leaves the in-app notifications untouched.
        // It is a quieter product, not a broken one.
        return false;
    }
}

/**
 * Ask, then register. Only ever from a control the person pressed.
 *
 * @returns {Promise<'granted'|'denied'|'default'|'unavailable'>}
 */
export async function enablePush(csrfToken = null) { // eslint-disable-line no-unused-vars
    const { status } = pushState();

    if (status !== 'permitted' && status !== 'available') return 'unavailable';

    // Already permitted: the missing half is the subscription itself.
    const permission = status === 'permitted'
        ? 'granted'
        : await Notification.requestPermission();

    if (permission !== 'granted') return permission;

    /*
     * The registration is what decides whether this worked. A browser can
     * grant permission and then refuse to register -- so the caller is told
     * 'failed', not 'granted', and the UI says the device could not be
     * registered instead of claiming it now receives notifications.
     */
    const registered = await registerPushSubscription(csrfToken);

    return registered ? 'granted' : 'failed';
}

/** Stop this browser being woken, and forget its address on the server. */
export async function disablePush(csrfToken = null) { // eslint-disable-line no-unused-vars
    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) return true;

        const endpoint = subscription.endpoint;

        await subscription.unsubscribe();

        await fetch('/notifications/push-subscription', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', ...csrfHeaders() },
            body: JSON.stringify({ endpoint }),
        });

        return true;
    } catch (error) {
        return false;
    }
}
