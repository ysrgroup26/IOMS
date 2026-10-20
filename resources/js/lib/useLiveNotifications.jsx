import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { startRealtime, subscribeRealtime, applyLocalUnreadCount, pollNow, watchRecord, propsWatching } from '@/lib/realtime';
import { playNotificationSound } from '@/lib/notificationSound';

/**
 * v2.129.0 -- THE ONE PLACE A PAGE ASKS "HAS ANYTHING HAPPENED?"
 *
 * `lib/realtime.js` is the transport: one poller per tab, a cursor, and no
 * React in it at all. This is the React face of it -- a provider that owns
 * the merged list, the unread count, and the arrival side effects, so that
 * the bell, the toasts and any page that cares all read one state rather than
 * three subscriptions that can disagree.
 *
 * =============================================================================
 * WHAT HAPPENS WHEN SOMETHING ARRIVES
 * =============================================================================
 *
 *   1. The badge and the list update. In place -- no navigation, no prop bag,
 *      no reload. This is the whole point of the release.
 *   2. A toast appears, for notifications only.
 *   3. A sound plays, subject to the four rules in `lib/notificationSound.js`.
 *   4. IF the notification is about the record on screen, the page asks
 *      Inertia for a PARTIAL reload of that record's props.
 *
 * Step 4 is the part worth explaining. A permit's status on an open page is
 * not patched from the notification payload: the notification says only that
 * something happened. `router.reload({ only: [...] })` re-runs the controller,
 * which re-runs `assertInCurrentTenant()`, the entitlement middleware, the
 * gates in `PtwWorkflowService` and every scope on every query. The page
 * updates without a full refresh AND without a second, thinner path to record
 * state -- which is where isolation defects live (ADR 069).
 */

const LiveNotificationsContext = createContext(null);

/** Newest first, de-duplicated by id, capped at what the bell shows. */
function mergeItems(existing, arriving) {
    const seen = new Set();

    return [...arriving, ...existing]
        .filter((item) => {
            if (seen.has(item.id)) return false;
            seen.add(item.id);
            return true;
        })
        .sort((a, b) => b.id - a.id)
        .slice(0, 30);
}

export function LiveNotificationsProvider({ children }) {
    const page = usePage();
    const { realtime, notifications, auth } = page.props;

    const propItems = notifications?.items ?? [];

    const [items, setItems] = useState(propItems);
    const [unreadCount, setUnreadCount] = useState(notifications?.unread_count ?? 0);
    const [ppeAlertCount, setPpeAlertCount] = useState(notifications?.ppe_alert_count ?? 0);
    const [toasts, setToasts] = useState([]);

    /*
     * The props are authoritative on NAVIGATION: a full page visit re-reads
     * the table, so whatever the live state had accumulated is superseded.
     * Merged rather than replaced, because a notification that arrived
     * between the server rendering the page and this effect running would
     * otherwise vanish from the list it is already counted in.
     */
    useEffect(() => {
        setItems((current) => mergeItems(current, propItems));
        setUnreadCount(notifications?.unread_count ?? 0);
        setPpeAlertCount(notifications?.ppe_alert_count ?? 0);
         
    }, [page.url, notifications?.unread_count]);

    const dismissToast = useCallback((id) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    useEffect(() => {
        if (!auth?.user) return undefined;

        startRealtime({
            transport: realtime?.transport ?? 'sync',
            interval: realtime?.interval ?? 5,
            idleInterval: realtime?.idle_interval ?? 30,
            idleAfter: realtime?.idle_after ?? 120,
        });

        return subscribeRealtime(({ items: arriving, unreadCount: unread, ppeAlertCount: ppe, changed, first }) => {
            if (typeof unread === 'number') setUnreadCount(unread);
            if (typeof ppe === 'number') setPpeAlertCount(ppe);

            /*
             * A WATCHED RECORD MOVED, with or without a notification for
             * this person. Refreshed silently -- no toast, no sound: nothing
             * has been asked of them, the page they are reading simply stops
             * being out of date. The brief is explicit that not every data
             * update is worth a noise.
             */
            if (!first && changed && changed.length > 0) {
                const only = new Set(propsWatching(changed));

                if (only.size > 0) {
                    router.reload({ only: [...only], preserveScroll: true, preserveState: true });
                }
            }

            if (first || arriving.length === 0) return;

            setItems((current) => mergeItems(current, arriving));

            /*
             * ONE SOUND for a batch, never one per item. A verifier clearing
             * six permits produces six notifications for the approver, and
             * six chimes is the reason people turn sound off.
             */
            playNotificationSound();

            setToasts((current) => [
                // Three at a time, newest on top. More than that on a phone
                // covers the page they were reading.
                ...arriving.slice(0, 3).map((item) => ({ ...item, toastId: `${item.id}-${Date.now()}` })),
                ...current,
            ].slice(0, 3));

            /*
             * The open page, refreshed through Inertia so every check the
             * controller performs is performed again. One reload for the
             * whole batch -- `only` is the union of what the watchers asked
             * for -- rather than one per notification.
             */
            const only = new Set();

            arriving.forEach((item) => {
                if (!item.subject) return;

                /*
                 * The record itself first, then a LIST watching the whole
                 * type: a register of permits wants refreshing when any
                 * permit moves, and it cannot know which ids it is showing
                 * before the notification arrives. `propsWatching` resolves
                 * both from the one registry.
                 */
                propsWatching([`${item.subject.type}:${item.subject.id}`]).forEach((prop) => only.add(prop));
            });

            if (only.size > 0) {
                router.reload({ only: [...only], preserveScroll: true, preserveState: true });
            }
        });
         
    }, [auth?.user?.id, realtime?.transport]);

    /** Mark one row read without navigating. */
    const markRead = useCallback(async (notification) => {
        if (notification.read_at) return;

        // Optimistic: the badge must answer the click, not the round trip.
        setItems((current) => current.map((item) => (
            item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item
        )));
        setUnreadCount((current) => Math.max(0, current - 1));

        try {
            const response = await fetch(`/notifications/${notification.id}/read`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.ok) {
                const data = await response.json();
                setUnreadCount(data.unread_count ?? 0);
                applyLocalUnreadCount(data.unread_count ?? 0);
            }
        } catch (error) {
            // The optimistic state stands until the next poll corrects it --
            // which it will, from the server, within seconds.
        }
    }, []);

    const markAllRead = useCallback(async () => {
        setItems((current) => current.map((item) => (
            item.read_at ? item : { ...item, read_at: new Date().toISOString() }
        )));
        setUnreadCount(0);

        try {
            await fetch('/notifications/read-all', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            applyLocalUnreadCount(0);
        } catch (error) {
            // As above.
        }
    }, []);

    const value = useMemo(() => ({
        items,
        unreadCount,
        ppeAlertCount,
        toasts,
        dismissToast,
        markRead,
        markAllRead,
        refresh: pollNow,
        pushEnabled: Boolean(realtime?.push_enabled),
    }), [items, unreadCount, ppeAlertCount, toasts, dismissToast, markRead, markAllRead, realtime?.push_enabled]);

    return (
        <LiveNotificationsContext.Provider value={value}>
            {children}
        </LiveNotificationsContext.Provider>
    );
}

/**
 * Safe outside the provider: a page rendered without the authenticated
 * layout (an error page, a public page) gets inert values rather than a
 * thrown "must be used within" error that would blank the screen.
 */
export function useLiveNotifications() {
    return useContext(LiveNotificationsContext) ?? {
        items: [],
        unreadCount: 0,
        ppeAlertCount: 0,
        toasts: [],
        dismissToast: () => {},
        markRead: () => {},
        markAllRead: () => {},
        refresh: () => {},
        pushEnabled: false,
    };
}

/**
 * For a page: "tell Inertia to refresh these props when something happens to
 * this record."
 *
 * DELIBERATELY NOT A CONTEXT CONSUMER. Every page in this product wraps its
 * own `<AuthenticatedLayout>`, which makes the page the PARENT of anything
 * the layout provides -- so a hook reading layout context from a page body
 * reads nothing at all. The registry lives in `lib/realtime.js` instead, and
 * this hook writes to it directly; the provider reads the same registry.
 *
 * Pass `'*'` as the id for a LIST: it refreshes when a notification arrives
 * about any record of that type, which is what a register needs and is all
 * it can know before the notification exists.
 *
 * The module names its own props, because only the page knows which of them
 * describe the record -- and naming them explicitly is what keeps the reload
 * partial instead of fetching the whole page again.
 */
export function useWatchedRecord(type, id, props) {
    // Stable across renders: an array literal in a caller's JSX would
    // otherwise re-register the watcher on every render.
    const key = Array.isArray(props) ? props.join(',') : '';

    useEffect(() => {
        if (!type || id === undefined || id === null || !key) return undefined;

        return watchRecord(`${type}:${id}`, key.split(','));
    }, [type, id, key]);
}
