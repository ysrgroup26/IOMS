import { useEffect, useLayoutEffect, useRef } from 'react';

/**
 * v2.71.0 -- NAVIGATION MEMORY.
 *
 * Two small pieces of per-session state that make a long sidebar behave
 * like navigation rather than like a page that reloads.
 *
 * WHY THIS IS NEEDED AT ALL, and why it is not a route special-case.
 *
 * Every one of IOMS's 144 authenticated pages wraps its own
 * `<AuthenticatedLayout>`, and nothing uses Inertia's persistent-layout
 * pattern (`Page.layout = ...`). So the layout is fully unmounted and
 * remounted on every navigation: the sidebar's scroll container is a
 * brand-new DOM node each time, and a brand-new node starts at
 * `scrollTop = 0`.
 *
 * That is the whole "scroll jumps back to the top when I click a item at
 * the bottom" behaviour. It is structural, not a quirk of one route --
 * it happens on every link in the product, and it gets more noticeable
 * the longer the department's menu is.
 *
 * The other fix would be converting all 144 pages to persistent layouts.
 * That is a genuinely large, risky change (every page's local state
 * assumptions change, and the mobile drawer currently relies on the
 * remount -- see AuthenticatedLayout's v2.16.0 note), and it would buy
 * nothing else here. Remembering the scroll offset and restoring it
 * before the browser paints achieves the same result for the user.
 *
 * SESSION-SCOPED ON PURPOSE. `sessionStorage`, not `localStorage`:
 * navigation context is a property of "the tab I am working in right
 * now", not a preference that should survive until next week. A new tab
 * starts fresh, which is what someone opening a second tab expects.
 *
 * Every accessor is guarded: Safari in private mode throws on
 * `sessionStorage` access, and a thrown navigation helper would take the
 * whole shell down.
 */

const SCROLL_PREFIX = 'ioms-nav-scroll:';
const WORKSPACE_KEY = 'ioms-nav-workspace';

function readStore(key) {
    try {
        return window.sessionStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStore(key, value) {
    try {
        window.sessionStorage.setItem(key, value);
    } catch {
        /* Private mode, or storage disabled. Navigation still works. */
    }
}

/* ======================================================================
 * Which department the user is working in
 * ====================================================================== */

/**
 * A route prefix can legitimately belong to more than one department --
 * Man-Hour is shared HR/HSE data, and Material Request is raised by every
 * department (v2.71.0). Without memory, such a route resolves to whichever
 * workspace happened to be declared last, so an HSE user opening Material
 * Request would be thrown into the Logistics sidebar mid-task.
 *
 * Remembering the last DEPARTMENT the user was actually in lets the
 * ambiguity resolve in favour of where they already are. It is only ever
 * consulted for a route with more than one owner; a single-owner route
 * still resolves from the route alone, so "the route is the source of
 * truth" (ADR 007) holds everywhere it was ever true.
 */
export function rememberWorkspaceKey(key) {
    if (typeof window === 'undefined' || ! key) return;
    writeStore(WORKSPACE_KEY, key);
}

export function recallWorkspaceKey() {
    if (typeof window === 'undefined') return null;
    return readStore(WORKSPACE_KEY);
}

/* ======================================================================
 * Sidebar scroll position
 * ====================================================================== */

/**
 * Persist and restore a scroll container's offset across remounts.
 *
 * @param {object}  ref      Ref to the scrollable element.
 * @param {string}  key      What the remembered offset belongs to. Changing
 *                           it (switching department) starts a new memory,
 *                           because the list itself is now different and an
 *                           offset from the previous list is meaningless.
 * @param {boolean} enabled  Skip entirely when the container is not really
 *                           scrollable in this state (the mobile drawer
 *                           while closed).
 */
export function useScrollMemory(ref, key, enabled = true) {
    const storageKey = `${SCROLL_PREFIX}${key ?? 'global'}`;
    const restored = useRef(false);

    /*
     * BEFORE PAINT, NOT AFTER. `useLayoutEffect` runs synchronously after
     * the DOM is committed and before the browser paints, so the sidebar is
     * never drawn at the top and then visibly corrected. `useEffect` here
     * produces a flash of the wrong position on every navigation, which is
     * more distracting than the bug being fixed.
     */
    useLayoutEffect(() => {
        restored.current = false;

        const el = ref.current;
        if (! el || ! enabled) return;

        const stored = Number.parseInt(readStore(storageKey) ?? '', 10);

        if (Number.isFinite(stored) && stored > 0) {
            // Clamped: the same department can render a shorter list than
            // last time (a module disabled, a plan changed), and restoring
            // past the new maximum would silently land at the bottom.
            el.scrollTop = Math.min(stored, el.scrollHeight - el.clientHeight);
            restored.current = true;
            return;
        }

        /*
         * No remembered offset -- a fresh session, or a department the user
         * has not opened yet. Rather than leaving the active item possibly
         * below the fold, bring it into view. `block: 'nearest'` scrolls
         * only if it actually needs to, so a menu that already fits does
         * not move at all.
         */
        const active = el.querySelector('[aria-current="page"]');
        active?.scrollIntoView({ block: 'nearest' });
    }, [ref, storageKey, enabled]);

    useEffect(() => {
        const el = ref.current;
        if (! el || ! enabled) return undefined;

        /*
         * Written on scroll rather than on unmount: Inertia can replace the
         * layout without a teardown the element is still attached for, and a
         * cleanup that reads a detached node records 0.
         *
         * WRITTEN SYNCHRONOUSLY, NOT INSIDE requestAnimationFrame. The
         * first version of this throttled through rAF, which looked like
         * ordinary scroll-handler hygiene and quietly broke the feature:
         * a page that is not painting -- a background tab, a minimised
         * window -- does not run rAF callbacks at all, so the offset was
         * never persisted. Found in browser verification, where the offset
         * came back as "never stored" rather than as a wrong number.
         *
         * rAF is the right tool for work whose only purpose is the next
         * FRAME. Persistence is not that: it has to happen whether or not
         * anything is ever drawn again. A `sessionStorage` write of one
         * short string is cheap, and this list is twenty rows, not a
         * virtualised feed.
         */
        const onScroll = () => {
            writeStore(storageKey, String(el.scrollTop));
        };

        el.addEventListener('scroll', onScroll, { passive: true });

        return () => el.removeEventListener('scroll', onScroll);
    }, [ref, storageKey, enabled]);
}
