/**
 * v2.129.0 -- ONE LIVE CONNECTION PER TAB, SHARED BY EVERYTHING THAT WANTS IT.
 *
 * =============================================================================
 * WHAT THIS IS
 * =============================================================================
 *
 * A cursor-based delta client for `/notifications/live`. It asks one question
 * -- "what has happened since id N?" -- and hands the answer to every
 * subscriber in the tab: the bell, the toasts, and any page that wants to
 * refresh itself when something arrives about the record it is showing.
 *
 * A MODULE SINGLETON, deliberately. Three components wanting live
 * notifications must not produce three pollers: the server would see triple
 * the requests and the three would disagree about what had already been seen,
 * which is how one notification becomes three toasts.
 *
 * =============================================================================
 * WHY A CURSOR AND NOT A SNAPSHOT
 * =============================================================================
 *
 * The cursor is what makes a dropped connection harmless, and it is the whole
 * reason this is not a "give me the latest ten" poller. A tab that was
 * asleep, offline, or behind a captive portal for ten minutes asks with the
 * cursor it still has and receives exactly what it missed -- in order, once.
 * Nothing is lost, because the rows were written to the database before
 * anybody was told about them; nothing is doubled, because the cursor only
 * advances past rows that actually arrived.
 *
 * =============================================================================
 * WHY IT IS NOT A WEBSOCKET
 * =============================================================================
 *
 * IOMS deploys to cPanel shared hosting: no long-running process, which is
 * also why Inertia SSR is off in this product. See ADR 069 for what was
 * evaluated. The cadence below is what keeps this honest rather than wasteful:
 *
 *   - VISIBLE and active  -> every `interval` seconds (5 by default)
 *   - VISIBLE and idle    -> every `idleInterval` seconds, after two minutes
 *                            without a keystroke, click or scroll
 *   - HIDDEN              -> not at all. That case belongs to web push, and a
 *                            background tab polling a server for hours is the
 *                            thing people disable notifications over.
 *   - OFFLINE             -> not at all, and one immediate catch-up the
 *                            moment the browser says it is back.
 */

const listeners = new Set();

const state = {
    /** The newest notification id this tab knows about. Null until the first answer. */
    cursor: null,
    unreadCount: null,
    ppeAlertCount: null,
    /** Ids already handed to subscribers, so a retry cannot double-toast. */
    seen: new Set(),
    online: true,
    lastActivity: Date.now(),
    failures: 0,
    /**
     * Records a page has asked to follow, as "Type:id" -> the last change
     * marker seen for it. A marker is one opaque number from the server; it
     * says that something happened and nothing about what, and the state
     * itself still arrives only through Inertia's partial reload.
     */
    watched: new Map(),
    /**
     * Which Inertia props each watched key wants refreshed, as
     * "Type:id" -> ['permit', 'activities', ...]. A wildcard key ("Type:*")
     * is a LIST watching a whole type and carries props but no marker.
     *
     * HELD HERE, NOT IN REACT CONTEXT, and that is a correction rather than
     * a preference: every one of this product's pages wraps its OWN
     * `<AuthenticatedLayout>`, so a page component is the PARENT of anything
     * the layout provides -- a context published by the layout is invisible
     * to the page inside it. The first implementation put this registry in
     * the provider and `useWatchedRecord()` silently did nothing on every
     * page in the product; found by watching the request query string, which
     * never carried a `watch` parameter.
     */
    watchers: new Map(),
};

let config = {
    transport: 'sync',
    interval: 5,
    idleInterval: 30,
    idleAfter: 120,
    url: '/notifications/live',
};

let timer = null;
let inFlight = null;
let started = false;

/** Subscribers are told about arrivals and about every count change. */
function emit(payload) {
    listeners.forEach((listener) => {
        try {
            listener(payload);
        } catch (error) {
            // One broken subscriber must not stop the others, and must not
            // stop the poller: a thrown render error here would silently end
            // live notifications for the rest of the session.
            if (import.meta.env?.DEV) console.error('[ioms realtime] subscriber failed', error);
        }
    });
}

function isVisible() {
    return typeof document === 'undefined' || document.visibilityState !== 'hidden';
}

function currentDelay() {
    if (state.failures > 0) {
        // Exponential, capped at a minute, with jitter so that twenty tabs
        // knocked offline by one wifi drop do not return in lockstep.
        const backoff = Math.min(config.interval * 2 ** state.failures, 60);
        return (backoff + Math.random() * 2) * 1000;
    }

    const idleFor = (Date.now() - state.lastActivity) / 1000;
    const seconds = idleFor > config.idleAfter ? config.idleInterval : config.interval;

    return seconds * 1000;
}

function schedule() {
    clearTimeout(timer);

    if (!started || config.transport === 'none') return;
    if (!isVisible() || !state.online) return;

    timer = setTimeout(() => {
        poll();
    }, currentDelay());
}

async function poll({ immediate = false } = {}) {
    if (!started || config.transport === 'none') return;
    if (!isVisible() || !state.online) return;

    // One request at a time. A tab returning from sleep can fire
    // visibilitychange, focus and online within the same tick.
    if (inFlight) return inFlight;

    const parameters = new URLSearchParams();

    /*
     * OMITTED on the first ask, not sent as zero. The server reads an absent
     * cursor as "tell me where to start, and do not replay my history"; it
     * reads `cursor=0` as "I have seen nothing, tell me about anything",
     * which is exactly what a tab sends once it has been told the cursor is 0
     * -- the state every account with no notifications yet starts in.
     */
    if (state.cursor !== null) {
        parameters.set('cursor', String(state.cursor));
    }

    if (state.watched.size > 0) {
        // Capped server-side too; sent as one parameter so a tab showing a
        // record and a list is still one request.
        parameters.set('watch', [...state.watched.keys()].slice(0, 5).join(','));
    }

    const query = parameters.toString();

    inFlight = (async () => {
        try {
            const response = await fetch(`${config.url}?${query}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (response.status === 401 || response.status === 419) {
                /*
                 * The session has ended. Stop asking: a poller hammering a
                 * login redirect every five seconds is how a logged-out tab
                 * becomes a load problem. The next real navigation takes the
                 * person to the login page, which is the right place for
                 * this to be resolved.
                 */
                stopRealtime();
                return;
            }

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();

            state.failures = 0;

            const fresh = (data.items || []).filter((item) => !state.seen.has(item.id));
            fresh.forEach((item) => state.seen.add(item.id));

            // Bounded: a long session must not grow this set forever. The
            // cursor is what prevents re-delivery anyway; `seen` only guards
            // against the same response being processed twice.
            if (state.seen.size > 500) {
                state.seen = new Set([...state.seen].slice(-200));
            }

            const first = state.cursor === null;

            /*
             * WHICH WATCHED RECORDS MOVED. On the first answer the markers
             * are only recorded -- a page that has just loaded is already
             * current, and treating its opening marker as a change would
             * make every page reload itself the moment it appeared.
             */
            const changed = [];

            Object.entries(data.markers || {}).forEach(([key, marker]) => {
                const known = state.watched.get(key);

                if (!first && known !== null && known !== undefined && marker !== known) {
                    changed.push(key);
                }

                state.watched.set(key, marker);
            });

            state.cursor = typeof data.cursor === 'number' ? data.cursor : state.cursor;
            state.unreadCount = data.unread_count ?? state.unreadCount;
            state.ppeAlertCount = data.ppe_alert_count ?? state.ppeAlertCount;

            emit({
                /*
                 * EMPTY ON THE FIRST ANSWER, always. The server returns no
                 * items for a cursorless call precisely so that opening a tab
                 * does not toast and chime through everything a person has
                 * ever been sent; this flag says which call this was, so a
                 * subscriber cannot mistake "here is where we start" for
                 * "here is what just happened".
                 */
                items: first ? [] : fresh,
                unreadCount: state.unreadCount,
                ppeAlertCount: state.ppeAlertCount,
                /*
                 * Reconciliation, not an event: the ids the SERVER still
                 * considers unread. A subscriber marks everything outside
                 * this set as read, which is what carries a read made on a
                 * phone across to a tab left open on a desk. Idempotent by
                 * construction -- a replayed poll converges on the same
                 * answer, and nothing can flip a row back to unread.
                 *
                 * Null when the server did not say (an older response), so
                 * a subscriber can tell "nothing is unread" from "I was not
                 * told" -- an empty array means the first.
                 */
                unreadIds: Array.isArray(data.unread_ids) ? data.unread_ids : null,
                changed,
                first,
            });
        } catch (error) {
            // A failed poll is not an error anybody should see. It is a
            // network, and networks are like that; the cursor is unchanged,
            // so the next successful poll returns everything missed.
            state.failures = Math.min(state.failures + 1, 6);
        } finally {
            inFlight = null;
            schedule();
        }
    })();

    return inFlight;
}

function markActivity() {
    state.lastActivity = Date.now();
}

export function startRealtime(options = {}) {
    if (typeof window === 'undefined') return;

    config = { ...config, ...options };

    if (started || config.transport === 'none') return;

    started = true;
    state.online = navigator.onLine !== false;

    document.addEventListener('visibilitychange', () => {
        if (isVisible()) {
            // Back on screen: ask at once rather than waiting out the
            // interval. This is the moment a person looks for new work.
            markActivity();
            poll({ immediate: true });
        } else {
            clearTimeout(timer);
        }
    });

    window.addEventListener('online', () => {
        state.online = true;
        state.failures = 0;
        poll({ immediate: true });
    });

    window.addEventListener('offline', () => {
        state.online = false;
        clearTimeout(timer);
    });

    window.addEventListener('focus', () => {
        markActivity();
        poll({ immediate: true });
    });

    ['pointerdown', 'keydown', 'scroll'].forEach((event) => {
        window.addEventListener(event, markActivity, { passive: true });
    });

    poll({ immediate: true });
}

export function stopRealtime() {
    started = false;
    clearTimeout(timer);
}

/** @returns {() => void} unsubscribe */
export function subscribeRealtime(listener) {
    listeners.add(listener);

    // Hand a new subscriber what is already known, so a component mounting
    // between polls is not blank until the next one.
    if (state.unreadCount !== null) {
        listener({ items: [], unreadCount: state.unreadCount, ppeAlertCount: state.ppeAlertCount, unreadIds: null, changed: [], first: true });
    }

    return () => listeners.delete(listener);
}

/**
 * Tell the shared state what this tab just did, so the badge is right
 * immediately rather than at the next poll. The server is still the source of
 * truth -- the next answer overwrites this -- but a badge that lags a click by
 * five seconds reads as a broken button.
 */
export function applyLocalUnreadCount(count) {
    if (typeof count !== 'number') return;

    state.unreadCount = count;
    emit({ items: [], unreadCount: state.unreadCount, ppeAlertCount: state.ppeAlertCount, unreadIds: null, changed: [], first: true });
}

/**
 * Follow a record while a page showing it is open.
 *
 * Registered here rather than in React state because the poller has to send
 * the list with every request, and because two components showing the same
 * record must produce one entry rather than two.
 *
 * @returns {() => void} stop following
 */
export function watchRecord(key, props = []) {
    state.watchers.set(key, props);

    // A wildcard is a list: there is no single record to follow by marker,
    // and the register's own notifications are what move it.
    if (key.endsWith(':*')) {
        return () => state.watchers.delete(key);
    }

    if (!state.watched.has(key)) {
        // Null, not 0: "no marker seen yet" has to be distinguishable from
        // "the marker is zero", or the first answer reads as a change.
        state.watched.set(key, null);
        // Ask at once, so the page starts from a known marker rather than
        // from whatever the next scheduled poll happens to find.
        poll({ immediate: true });
    }

    return () => {
        state.watched.delete(key);
        state.watchers.delete(key);
    };
}

/**
 * The union of the Inertia props these watched keys asked for.
 *
 * Both refresh paths go through it -- a notification about a record, and a
 * change marker moving on one -- so a page declares what it needs refreshed
 * exactly once, in one place.
 *
 * @param {string[]} keys  "Type:id" entries; a missing id falls back to the
 *                         type's wildcard, which is how a LIST is refreshed
 *                         by a notification about any one of its rows.
 * @returns {string[]}
 */
export function propsWatching(keys) {
    const props = new Set();

    keys.forEach((key) => {
        const type = key.split(':')[0];
        const matched = state.watchers.get(key) ?? state.watchers.get(`${type}:*`);

        if (matched) matched.forEach((prop) => props.add(prop));
    });

    return [...props];
}

/** For tests and for the Notification Center's own "check now" affordance. */
export function pollNow() {
    return poll({ immediate: true });
}
